<?php

namespace App\Services\Accurate;

use App\Models\Item;
use App\Models\SyncBatch;
use App\Models\SyncLog;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Orchestrates one Accurate -> Stockwise sync run.
 *
 * Laravel never talks to Firebird directly (see docs/sync-architecture.md):
 * step 1 shells out to the sync-service/ Python project, which holds the real
 * Firebird credentials in its own .env and refreshes the accurate_* MySQL
 * staging tables (read-only against Accurate, full mirror). Step 2 is pure
 * MySQL-to-MySQL work done here in PHP: match accurate_item.ITEMNO against
 * items.code and upsert, logging every row's outcome to sync_logs.
 *
 * Scope for this phase (per the "Accurate sync foundation" brief): items only.
 * Accurate's stock figure is a company-wide total (no per-warehouse
 * breakdown — see docs/ACCURATE_MAPPING.md §Warehouses), so it is written to
 * the reference-only `items.accurate_qty_onhand`/`accurate_qty_onorder`
 * columns, never into the per-warehouse `inventory` table.
 */
class AccurateSyncService
{
    /** How long a RUNNING batch can go without finishing before it's considered dead (crashed/killed process, not a real lock holder). */
    protected const STALE_RUNNING_MINUTES = 10;

    /**
     * A real Accurate ITEMNO product code: 3 letters + "." + 4-or-more digits
     * (e.g. AUT.0001). Anything else (AUT, AUT.01, AUT.01.01, AUT.01.01.01)
     * is a PARENTITEM category/hierarchy node, not a stockable product —
     * verified against the real GUDANGSIG2025.GDB data (9,177/9,600 rows
     * match; the other 423 are category nodes, all with empty UNIT1), see
     * docs/accurate-database-analysis.md §12.
     */
    protected const PRODUCT_ITEMNO_PATTERN = '/^[A-Za-z]{3}\.[0-9]{4,}$/';

    /** Max category levels resolved upward from a product's PARENTITEM (Anak 1/2/3 — see docs/accurate-database-analysis.md §12 on why there's no "Kategori Induk" level in this data). */
    protected const MAX_CATEGORY_LEVELS = 3;

    public function run(?int $userId = null): SyncBatch
    {
        $this->reapStaleRunningBatches();

        $inProgress = SyncBatch::where('source', 'accurate')->where('status', 'RUNNING')->first();
        if ($inProgress) {
            return $inProgress; // sync lock: don't start a second run while one is genuinely in progress
        }

        $batch = SyncBatch::create([
            'sync_code' => $this->nextSyncCode(),
            'source' => 'accurate',
            'started_at' => now(),
            'status' => 'RUNNING',
            'created_by' => $userId,
        ]);

        try {
            $this->refreshStaging();
        } catch (Throwable $e) {
            $batch->update([
                'status' => 'FAILED',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            return $batch->fresh();
        }

        $counts = $this->syncItems($batch);

        $batch->update([
            'finished_at' => now(),
            'total_records' => $counts['total'],
            'inserted_records' => $counts['inserted'],
            'updated_records' => $counts['updated'],
            'skipped_records' => $counts['skipped'],
            'error_records' => $counts['errors'],
            'status' => $counts['errors'] > 0
                ? ($counts['inserted'] + $counts['updated'] > 0 ? 'PARTIAL' : 'FAILED')
                : 'SUCCESS',
        ]);

        return $batch->fresh();
    }

    /** Mark any RUNNING batch that's been open too long as FAILED — a real sync lock only makes sense if a crashed/killed process can't hold it forever. */
    protected function reapStaleRunningBatches(): void
    {
        SyncBatch::where('source', 'accurate')
            ->where('status', 'RUNNING')
            ->where('started_at', '<', now()->subMinutes(self::STALE_RUNNING_MINUTES))
            ->get()
            ->each(fn (SyncBatch $b) => $b->update([
                'status' => 'FAILED',
                'finished_at' => now(),
                'error_message' => 'Interrupted — no completion recorded within '.self::STALE_RUNNING_MINUTES.' minutes (process likely crashed or was killed).',
            ]));
    }

    protected function nextSyncCode(): string
    {
        $today = now()->format('Ymd');
        $countToday = SyncBatch::whereDate('created_at', now()->toDateString())->count();

        return sprintf('SYNC-%s-%03d', $today, $countToday + 1);
    }

    /**
     * Refresh accurate_* MySQL staging tables by running the Python
     * sync-service. Throws on failure (unreachable Firebird, bad credentials,
     * etc.) — the caller marks the batch FAILED without crashing the request.
     */
    protected function refreshStaging(): void
    {
        $path = config('accurate.sync_service_path');
        if (! $path || ! is_dir($path)) {
            throw new \RuntimeException(
                "Unable to connect to Accurate database: sync-service path not configured or missing ({$path})."
            );
        }

        $process = Process::path($path)
            ->timeout(config('accurate.process_timeout_seconds'));

        // Windows: the shell that started `php artisan serve` may hand down a
        // PATH the Firebird client library can't use (e.g. Git Bash/MSYS
        // mangles it). Give the child a clean, known-good PATH explicitly so
        // fbclient.dll's own dependency resolution doesn't depend on that.
        $clientDir = config('accurate.firebird_client_dir');
        if ($clientDir && PHP_OS_FAMILY === 'Windows') {
            $systemRoot = getenv('SystemRoot') ?: 'C:\\Windows';
            $process = $process->env([
                'PATH' => "{$clientDir};{$systemRoot}\\System32;{$systemRoot}",
                'SystemRoot' => $systemRoot,
            ]);
        }

        $result = $process->run([config('accurate.python_bin'), '-m', 'sync.initial_sync']);

        if (! $result->successful()) {
            $output = trim($result->errorOutput() ?: $result->output());
            throw new \RuntimeException(
                'Unable to connect to Accurate database. '.($output ?: 'sync-service exited with an error.')
            );
        }
    }

    /**
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncItems(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_item')) {
            return $counts;
        }

        $unitsByCode = Unit::pluck('id', 'code')
            ->keyBy(fn ($id, $code) => mb_strtoupper(trim($code)));

        // Preload existing items keyed by code once — avoids an N+1 query per
        // Accurate row (this loop runs over ~9,600 rows).
        $itemsByCode = Item::select(
            'id', 'code', 'accurate_qty_onhand', 'accurate_qty_onorder', 'accurate_synced_at',
            'accurate_category_anak_1', 'accurate_category_anak_2', 'accurate_category_anak_3'
        )
            ->get()
            ->keyBy('code');

        $rows = DB::table('accurate_item')
            ->select('ITEMNO', 'ITEMDESCRIPTION', 'UNIT1', 'SUSPENDED', 'QUANTITY', 'ONORDER', 'PARENTITEM')
            ->orderBy('ITEMNO')
            ->get();

        // Every row (product AND category node) keyed by ITEMNO — needed to
        // walk PARENTITEM chains upward and read each ancestor's own
        // ITEMDESCRIPTION. Built once, reused for all ~9,600 rows below.
        $nodesByCode = $rows->keyBy('ITEMNO');

        foreach ($rows as $row) {
            $counts['total']++;

            try {
                $outcome = $this->syncOneItem($row, $unitsByCode, $itemsByCode, $nodesByCode);
                $counts[$outcome['bucket']]++;

                SyncLog::create([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'item',
                    'source_id' => (string) ($row->ITEMNO ?? ''),
                    'action' => $outcome['action'],
                    'status' => 'SUCCESS',
                    'message' => $outcome['message'],
                    'old_data' => $outcome['old_data'] ?? null,
                    'new_data' => $outcome['new_data'] ?? null,
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;

                SyncLog::create([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'item',
                    'source_id' => (string) ($row->ITEMNO ?? ''),
                    'action' => 'ERROR',
                    'status' => 'FAILED',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }

    /**
     * @return array{bucket:string,action:string,message:string,old_data?:array,new_data?:array}
     */
    protected function syncOneItem(
        object $row,
        \Illuminate\Support\Collection $unitsByCode,
        \Illuminate\Support\Collection $itemsByCode,
        \Illuminate\Support\Collection $nodesByCode
    ): array {
        $itemno = trim((string) ($row->ITEMNO ?? ''));
        if ($itemno === '') {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'accurate_id (ITEMNO) kosong.'];
        }

        // Category/hierarchy nodes (AUT, AUT.01, AUT.01.01, AUT.01.01.01) are
        // never products, even if a row happens to carry a UNIT1 — the
        // pattern match is the authoritative rule per the brief (§3).
        if (! preg_match(self::PRODUCT_ITEMNO_PATTERN, $itemno)) {
            return [
                'bucket' => 'skipped',
                'action' => 'SKIP',
                'message' => 'Bukan kode produk (node kategori/hierarki ITEMNO), digunakan hanya untuk resolusi kategori barang lain.',
            ];
        }

        $existing = $itemsByCode->get($itemno);
        $newQty = (float) ($row->QUANTITY ?? 0);
        $newOnOrder = (float) ($row->ONORDER ?? 0);
        $category = $this->resolveCategoryHierarchy($row->PARENTITEM ?? null, $nodesByCode);

        if ($existing) {
            $unchanged = (float) $existing->accurate_qty_onhand === $newQty
                && (float) $existing->accurate_qty_onorder === $newOnOrder
                && $existing->accurate_category_anak_1 === $category['anak_1']
                && $existing->accurate_category_anak_2 === $category['anak_2']
                && $existing->accurate_category_anak_3 === $category['anak_3'];

            if ($unchanged && $existing->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            $old = [
                'accurate_qty_onhand' => $existing->accurate_qty_onhand,
                'accurate_qty_onorder' => $existing->accurate_qty_onorder,
                'accurate_category_anak_1' => $existing->accurate_category_anak_1,
                'accurate_category_anak_2' => $existing->accurate_category_anak_2,
                'accurate_category_anak_3' => $existing->accurate_category_anak_3,
            ];

            $existing->forceFill([
                'accurate_synced_at' => now(),
                'accurate_qty_onhand' => $newQty,
                'accurate_qty_onorder' => $newOnOrder,
                'accurate_category_anak_1' => $category['anak_1'],
                'accurate_category_anak_2' => $category['anak_2'],
                'accurate_category_anak_3' => $category['anak_3'],
            ])->save();

            return [
                'bucket' => 'updated',
                'action' => 'UPDATE',
                'message' => "Data referensi Accurate diperbarui: qty {$old['accurate_qty_onhand']} -> {$newQty}.",
                'old_data' => $old,
                'new_data' => [
                    'accurate_qty_onhand' => $newQty, 'accurate_qty_onorder' => $newOnOrder,
                ] + $category,
            ];
        }

        // No match — only create for real products (has a unit); Accurate's
        // category/placeholder nodes (PARENTITEM tree) have no UNIT1. Kept as
        // a secondary data-quality guard alongside the regex check above.
        $unit1 = trim((string) ($row->UNIT1 ?? ''));
        if ($unit1 === '') {
            return [
                'bucket' => 'skipped',
                'action' => 'SKIP',
                'message' => 'Tanpa satuan — kemungkinan node kategori (PARENTITEM), bukan barang.',
            ];
        }

        $description = trim((string) ($row->ITEMDESCRIPTION ?? ''));
        if ($description === '') {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'nama_barang kosong.'];
        }

        $unitId = $unitsByCode->get(mb_strtoupper($unit1));

        $created = Item::create([
            'code' => $itemno,
            'description' => $description,
            'unit_id' => $unitId,
            'item_type' => 'CONSUMABLE',
            'needs_blueprint' => false,
            'source' => 'accurate',
            'is_active' => ! (bool) ($row->SUSPENDED ?? false),
            'accurate_synced_at' => now(),
            'accurate_qty_onhand' => $newQty,
            'accurate_qty_onorder' => $newOnOrder,
            'accurate_category_anak_1' => $category['anak_1'],
            'accurate_category_anak_2' => $category['anak_2'],
            'accurate_category_anak_3' => $category['anak_3'],
        ]);

        return [
            'bucket' => 'inserted',
            'action' => 'INSERT',
            'message' => 'Barang baru dari Accurate.',
            'new_data' => ['code' => $created->code, 'description' => $created->description] + $category,
        ];
    }

    /**
     * Kategori Anak 1/2/3 = ITEMDESCRIPTION of each real PARENTITEM ancestor,
     * walked upward from the product's immediate parent (deepest) to the
     * shallowest real ancestor. There is deliberately no "Kategori Induk"
     * slot: verified against the real GDB that a bare 0-dot ITEMNO node
     * (e.g. "AUT") never exists in this company's data, so that level has no
     * ITEMDESCRIPTION to read — see docs/accurate-database-analysis.md §12.
     * Chain depth varies per product (1-3 real ancestors are all observed in
     * the real data), so shallower products simply leave the deeper Anak
     * slots null — not a bug.
     *
     * @return array{anak_1: ?string, anak_2: ?string, anak_3: ?string}
     */
    protected function resolveCategoryHierarchy(?string $parentItem, \Illuminate\Support\Collection $nodesByCode): array
    {
        $chain = [];
        $current = $parentItem !== null ? trim($parentItem) : '';

        while ($current !== '' && count($chain) < self::MAX_CATEGORY_LEVELS) {
            $node = $nodesByCode->get($current);
            if (! $node) {
                break; // dangling PARENTITEM reference — stop rather than guess
            }

            $chain[] = trim((string) ($node->ITEMDESCRIPTION ?? '')) ?: null;
            $current = trim((string) ($node->PARENTITEM ?? ''));
        }

        // $chain is deepest-first (immediate parent first); reverse so the
        // shallowest real ancestor becomes Anak 1.
        $chain = array_reverse($chain);

        return [
            'anak_1' => $chain[0] ?? null,
            'anak_2' => $chain[1] ?? null,
            'anak_3' => $chain[2] ?? null,
        ];
    }
}
