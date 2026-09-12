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
        $itemsByCode = Item::select('id', 'code', 'accurate_qty_onhand', 'accurate_qty_onorder', 'accurate_synced_at')
            ->get()
            ->keyBy('code');

        $rows = DB::table('accurate_item')
            ->select('ITEMNO', 'ITEMDESCRIPTION', 'UNIT1', 'SUSPENDED', 'QUANTITY', 'ONORDER')
            ->orderBy('ITEMNO')
            ->get();

        foreach ($rows as $row) {
            $counts['total']++;

            try {
                $outcome = $this->syncOneItem($row, $unitsByCode, $itemsByCode);
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
        \Illuminate\Support\Collection $itemsByCode
    ): array {
        $itemno = trim((string) ($row->ITEMNO ?? ''));
        if ($itemno === '') {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'accurate_id (ITEMNO) kosong.'];
        }

        $existing = $itemsByCode->get($itemno);
        $newQty = (float) ($row->QUANTITY ?? 0);
        $newOnOrder = (float) ($row->ONORDER ?? 0);

        if ($existing) {
            $unchanged = (float) $existing->accurate_qty_onhand === $newQty
                && (float) $existing->accurate_qty_onorder === $newOnOrder;

            if ($unchanged && $existing->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            $old = [
                'accurate_qty_onhand' => $existing->accurate_qty_onhand,
                'accurate_qty_onorder' => $existing->accurate_qty_onorder,
            ];

            $existing->forceFill([
                'accurate_synced_at' => now(),
                'accurate_qty_onhand' => $newQty,
                'accurate_qty_onorder' => $newOnOrder,
            ])->save();

            return [
                'bucket' => 'updated',
                'action' => 'UPDATE',
                'message' => "Stok referensi Accurate diperbarui: {$old['accurate_qty_onhand']} -> {$newQty}.",
                'old_data' => $old,
                'new_data' => ['accurate_qty_onhand' => $newQty, 'accurate_qty_onorder' => $newOnOrder],
            ];
        }

        // No match — only create for real products (has a unit); Accurate's
        // category/placeholder nodes (PARENTITEM tree) have no UNIT1.
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
        ]);

        return [
            'bucket' => 'inserted',
            'action' => 'INSERT',
            'message' => 'Barang baru dari Accurate.',
            'new_data' => ['code' => $created->code, 'description' => $created->description],
        ];
    }
}
