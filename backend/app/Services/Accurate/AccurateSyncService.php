<?php

namespace App\Services\Accurate;

use App\Models\Item;
use App\Models\Npbg;
use App\Models\Ppb;
use App\Models\Ri;
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
 * items.code and upsert, logging every row's outcome to sync_logs. NPBG
 * (accurate_arinv + accurate_arinvdet -> npbg), PPB (accurate_requisition +
 * accurate_requisitiondet -> ppb) and RI (accurate_apinv + accurate_apitmdet
 * -> ri) all follow the same flat one-row-per-line pattern.
 *
 * Items: Accurate's stock figure is a company-wide total (no per-warehouse
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

        $itemCounts = $this->syncItems($batch);
        $npbgCounts = $this->syncNpbg($batch);
        $ppbCounts = $this->syncPpb($batch);
        $riCounts = $this->syncRi($batch);
        $counts = [
            'total' => $itemCounts['total'] + $npbgCounts['total'] + $ppbCounts['total'] + $riCounts['total'],
            'inserted' => $itemCounts['inserted'] + $npbgCounts['inserted'] + $ppbCounts['inserted'] + $riCounts['inserted'],
            'updated' => $itemCounts['updated'] + $npbgCounts['updated'] + $ppbCounts['updated'] + $riCounts['updated'],
            'skipped' => $itemCounts['skipped'] + $npbgCounts['skipped'] + $ppbCounts['skipped'] + $riCounts['skipped'],
            'errors' => $itemCounts['errors'] + $npbgCounts['errors'] + $ppbCounts['errors'] + $riCounts['errors'],
        ];

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
     * NPBG = one row per ARINVDET line, joined back to its ARINV header.
     * Identity is (ARINVOICEID, SEQ) — verified as ARINVDET's real primary key
     * against the live schema (docs/GDB_ANALYSIS.md), not guessed; INVOICENO
     * alone is not unique since one invoice can carry many detail lines.
     *
     * Only Accurate-owned columns are ever written here (no_npbg, tgl_npbg,
     * shipdate, taxdate, divisi, pelanggan, keterangan, deskripsi_barang,
     * kuantitas, satuan, peminta) — the Stockwise-owned enrichment columns
     * (tipe_npbg, klasifikasi, deskripsi, nama_proyek, no_seri_nopol,
     * dikeluarkan_oleh) are never touched by sync, so a user's prior edits
     * survive a re-sync (brief §11).
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncNpbg(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_arinvdet') || ! DB::getSchemaBuilder()->hasTable('accurate_arinv')) {
            return $counts;
        }

        $existing = Npbg::query()
            ->select('id', 'accurate_arinvoice_id', 'accurate_seq', 'no_npbg', 'tgl_npbg', 'shipdate', 'taxdate',
                'divisi', 'pelanggan', 'keterangan', 'kode_barang', 'deskripsi_barang', 'kuantitas', 'satuan', 'peminta', 'accurate_synced_at')
            ->get()
            ->keyBy(fn ($n) => $n->accurate_arinvoice_id.'-'.$n->accurate_seq);

        $rows = DB::table('accurate_arinvdet as d')
            ->join('accurate_arinv as h', 'd.ARINVOICEID', '=', 'h.ARINVOICEID')
            ->select(
                'd.ARINVOICEID as ARINVOICEID', 'd.SEQ as SEQ', 'd.ITEMNO', 'd.ITEMOVDESC', 'd.QUANTITY', 'd.ITEMUNIT', 'd.ITEMRESERVED1',
                'h.INVOICENO', 'h.INVOICEDATE', 'h.SHIPDATE', 'h.TAXDATE', 'h.PURCHASEORDERNO', 'h.SHIPTO1', 'h.DESCRIPTION'
            )
            ->orderBy('d.ARINVOICEID')->orderBy('d.SEQ')
            ->get();

        foreach ($rows as $row) {
            $counts['total']++;

            try {
                $outcome = $this->syncOneNpbgLine($row, $existing);
                $counts[$outcome['bucket']]++;

                SyncLog::create([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'npbg',
                    'source_id' => $row->ARINVOICEID.'-'.$row->SEQ,
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
                    'entity' => 'npbg',
                    'source_id' => ($row->ARINVOICEID ?? '?').'-'.($row->SEQ ?? '?'),
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
    protected function syncOneNpbgLine(object $row, \Illuminate\Support\Collection $existingByKey): array
    {
        if ($row->ARINVOICEID === null || $row->SEQ === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'ARINVOICEID/SEQ kosong — data tidak valid.'];
        }

        $key = $row->ARINVOICEID.'-'.$row->SEQ;

        $new = [
            'no_npbg' => $row->INVOICENO,
            'tgl_npbg' => $row->INVOICEDATE,
            'shipdate' => $row->SHIPDATE,
            'taxdate' => $row->TAXDATE,
            'divisi' => $row->PURCHASEORDERNO,
            'pelanggan' => $row->SHIPTO1,
            'keterangan' => $row->DESCRIPTION,
            'kode_barang' => $row->ITEMNO,
            'deskripsi_barang' => $row->ITEMOVDESC,
            'kuantitas' => $row->QUANTITY !== null ? (float) $row->QUANTITY : null,
            'satuan' => $row->ITEMUNIT,
            'peminta' => $row->ITEMRESERVED1,
        ];

        $existing = $existingByKey->get($key);

        if ($existing) {
            $old = collect($new)->keys()->mapWithKeys(fn ($f) => [$f => $existing->{$f} instanceof \Carbon\Carbon ? $existing->{$f}->toDateString() : $existing->{$f}])->all();
            $unchanged = collect($new)->every(function ($v, $f) use ($existing) {
                $current = $existing->{$f};
                if ($current instanceof \Carbon\Carbon) {
                    $current = $current->toDateString();
                }

                return (string) ($current ?? '') === (string) ($v ?? '');
            });

            if ($unchanged && $existing->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            Npbg::where('id', $existing->id)->update($new + ['accurate_synced_at' => now()]);

            return [
                'bucket' => 'updated', 'action' => 'UPDATE',
                'message' => "NPBG {$row->INVOICENO} baris {$row->SEQ} diperbarui.",
                'old_data' => $old, 'new_data' => $new,
            ];
        }

        Npbg::create($new + [
            'accurate_arinvoice_id' => $row->ARINVOICEID,
            'accurate_seq' => $row->SEQ,
            'accurate_synced_at' => now(),
        ]);

        return [
            'bucket' => 'inserted', 'action' => 'INSERT',
            'message' => "NPBG baru dari invoice {$row->INVOICENO} baris {$row->SEQ}.",
            'new_data' => $new,
        ];
    }

    /**
     * PPB = one row per REQUISITIONDET line, joined back to its REQUISITION
     * header. Identity is (REQID, SEQ) — verified as REQUISITIONDET's real
     * primary key against the live schema (docs/GDB_ANALYSIS.md), not guessed;
     * REQNO alone is not unique since one requisition can carry many lines.
     *
     * Read-only mirror — unlike Npbg there are no Stockwise-owned columns to
     * protect here (no manual enrichment fields exist for PPB).
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncPpb(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_requisitiondet') || ! DB::getSchemaBuilder()->hasTable('accurate_requisition')) {
            return $counts;
        }

        $existing = Ppb::query()
            ->select('id', 'accurate_reqid', 'accurate_seq', 'no_ppb', 'tgl_ppb', 'status', 'divisi', 'keterangan',
                'kode_barang', 'deskripsi_barang', 'kuantitas', 'satuan', 'qty_dipesan', 'qty_diterima', 'peminta',
                'catatan_baris', 'accurate_synced_at')
            ->get()
            ->keyBy(fn ($p) => $p->accurate_reqid.'-'.$p->accurate_seq);

        $rows = DB::table('accurate_requisitiondet as d')
            ->join('accurate_requisition as h', 'd.REQID', '=', 'h.REQID')
            ->select(
                'd.REQID as REQID', 'd.SEQ as SEQ', 'd.ITEMNO', 'd.ITEMOVDESC', 'd.QUANTITY', 'd.ITEMUNIT',
                'd.QTYORDERED', 'd.QTYRECEIVED', 'd.ITEMRESERVED3', 'd.NOTES',
                'h.REQNO', 'h.REQDATE', 'h.ISCLOSED', 'h.DESCRIPTION'
            )
            ->orderBy('d.REQID')->orderBy('d.SEQ')
            ->get();

        foreach ($rows as $row) {
            $counts['total']++;

            try {
                $outcome = $this->syncOnePpbLine($row, $existing);
                $counts[$outcome['bucket']]++;

                SyncLog::create([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'ppb',
                    'source_id' => $row->REQID.'-'.$row->SEQ,
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
                    'entity' => 'ppb',
                    'source_id' => ($row->REQID ?? '?').'-'.($row->SEQ ?? '?'),
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
    protected function syncOnePpbLine(object $row, \Illuminate\Support\Collection $existingByKey): array
    {
        if ($row->REQID === null || $row->SEQ === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'REQID/SEQ kosong — data tidak valid.'];
        }

        $key = $row->REQID.'-'.$row->SEQ;

        $new = [
            'no_ppb' => $row->REQNO,
            'tgl_ppb' => $row->REQDATE,
            'status' => $row->ISCLOSED === null ? null : ((int) $row->ISCLOSED === 1 ? 'CLOSED' : 'OPEN'),
            'divisi' => $this->deriveDivisiFromDocNumber($row->REQNO),
            'keterangan' => $row->DESCRIPTION,
            'kode_barang' => $row->ITEMNO,
            'deskripsi_barang' => $row->ITEMOVDESC,
            'kuantitas' => $row->QUANTITY !== null ? (float) $row->QUANTITY : null,
            'satuan' => $row->ITEMUNIT,
            'qty_dipesan' => $row->QTYORDERED !== null ? (float) $row->QTYORDERED : null,
            'qty_diterima' => $row->QTYRECEIVED !== null ? (float) $row->QTYRECEIVED : null,
            'peminta' => $row->ITEMRESERVED3,
            'catatan_baris' => $row->NOTES,
        ];

        $existing = $existingByKey->get($key);

        if ($existing) {
            $old = collect($new)->keys()->mapWithKeys(fn ($f) => [$f => $existing->{$f} instanceof \Carbon\Carbon ? $existing->{$f}->toDateString() : $existing->{$f}])->all();
            $unchanged = collect($new)->every(function ($v, $f) use ($existing) {
                $current = $existing->{$f};
                if ($current instanceof \Carbon\Carbon) {
                    $current = $current->toDateString();
                }

                return (string) ($current ?? '') === (string) ($v ?? '');
            });

            if ($unchanged && $existing->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            Ppb::where('id', $existing->id)->update($new + ['accurate_synced_at' => now()]);

            return [
                'bucket' => 'updated', 'action' => 'UPDATE',
                'message' => "PPB {$row->REQNO} baris {$row->SEQ} diperbarui.",
                'old_data' => $old, 'new_data' => $new,
            ];
        }

        Ppb::create($new + [
            'accurate_reqid' => $row->REQID,
            'accurate_seq' => $row->SEQ,
            'accurate_synced_at' => now(),
        ]);

        return [
            'bucket' => 'inserted', 'action' => 'INSERT',
            'message' => "PPB baru dari requisition {$row->REQNO} baris {$row->SEQ}.",
            'new_data' => $new,
        ];
    }

    /**
     * PPB and RI numbers are both literally formatted "{DOC}/{divisi}/{yy}/{roman}/{seq}"
     * (e.g. PPB/ATK/25/IX/004, RI/NV/25/IX/001) — confirmed against live
     * accurate_requisition and accurate_apinv data. Neither REQUISITION nor
     * APINV has a dedicated divisi column (unlike ARINV.SHIPTO1 for NPBG), so
     * it is parsed from the document number instead.
     */
    protected function deriveDivisiFromDocNumber(?string $number): ?string
    {
        if ($number === null) {
            return null;
        }

        $parts = explode('/', $number);

        return $parts[1] ?? null;
    }

    /**
     * RI = one row per APITMDET line, joined back to its APINV header and to
     * the vendor's name (accurate_persondata). Identity is (APINVOICEID, SEQ)
     * — verified as APITMDET's real primary key against the live schema
     * (docs/GDB_ANALYSIS.md); INVOICENO alone is not unique since one AP
     * invoice can carry many item lines. APITMDET even carries a
     * self-referencing RIID FK back to APINV, confirming APINV really is the
     * RI document (its INVOICENO is literally "RI/{divisi}/{yy}/{roman}/{seq}").
     *
     * Read-only mirror — separate from the app's own `receivings` /
     * `receiving_items` tables (the internal DRAFT->CHECKING->CONFIRMED
     * workflow tied to a PO and to stock movements).
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncRi(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_apitmdet') || ! DB::getSchemaBuilder()->hasTable('accurate_apinv')) {
            return $counts;
        }

        $existing = Ri::query()
            ->select('id', 'accurate_apinvoice_id', 'accurate_seq', 'no_ri', 'tgl_ri', 'divisi', 'vendor', 'no_po',
                'shipdate', 'keterangan', 'kode_barang', 'deskripsi_barang', 'kuantitas', 'satuan', 'harga_satuan',
                'pemeriksa', 'accurate_synced_at')
            ->get()
            ->keyBy(fn ($r) => $r->accurate_apinvoice_id.'-'.$r->accurate_seq);

        $rows = DB::table('accurate_apitmdet as d')
            ->join('accurate_apinv as h', 'd.APINVOICEID', '=', 'h.APINVOICEID')
            ->leftJoin('accurate_persondata as v', 'h.VENDORID', '=', 'v.ID')
            ->select(
                'd.APINVOICEID as APINVOICEID', 'd.SEQ as SEQ', 'd.ITEMNO', 'd.ITEMOVDESC', 'd.QUANTITY', 'd.ITEMUNIT',
                'd.UNITPRICE', 'd.ITEMRESERVED3',
                'h.INVOICENO', 'h.INVOICEDATE', 'h.PURCHASEORDERNO', 'h.SHIPDATE', 'h.DESCRIPTION',
                'v.NAME as VENDORNAME'
            )
            ->orderBy('d.APINVOICEID')->orderBy('d.SEQ')
            ->get();

        foreach ($rows as $row) {
            $counts['total']++;

            try {
                $outcome = $this->syncOneRiLine($row, $existing);
                $counts[$outcome['bucket']]++;

                SyncLog::create([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'ri',
                    'source_id' => $row->APINVOICEID.'-'.$row->SEQ,
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
                    'entity' => 'ri',
                    'source_id' => ($row->APINVOICEID ?? '?').'-'.($row->SEQ ?? '?'),
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
    protected function syncOneRiLine(object $row, \Illuminate\Support\Collection $existingByKey): array
    {
        if ($row->APINVOICEID === null || $row->SEQ === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'APINVOICEID/SEQ kosong — data tidak valid.'];
        }

        $key = $row->APINVOICEID.'-'.$row->SEQ;

        $new = [
            'no_ri' => $row->INVOICENO,
            'tgl_ri' => $row->INVOICEDATE,
            'divisi' => $this->deriveDivisiFromDocNumber($row->INVOICENO),
            'vendor' => $row->VENDORNAME,
            'no_po' => $row->PURCHASEORDERNO,
            'shipdate' => $row->SHIPDATE,
            'keterangan' => $row->DESCRIPTION,
            'kode_barang' => $row->ITEMNO,
            'deskripsi_barang' => $row->ITEMOVDESC,
            'kuantitas' => $row->QUANTITY !== null ? (float) $row->QUANTITY : null,
            'satuan' => $row->ITEMUNIT,
            'harga_satuan' => $row->UNITPRICE !== null ? (float) $row->UNITPRICE : null,
            'pemeriksa' => $row->ITEMRESERVED3,
        ];

        $existing = $existingByKey->get($key);

        if ($existing) {
            $old = collect($new)->keys()->mapWithKeys(fn ($f) => [$f => $existing->{$f} instanceof \Carbon\Carbon ? $existing->{$f}->toDateString() : $existing->{$f}])->all();
            $unchanged = collect($new)->every(function ($v, $f) use ($existing) {
                $current = $existing->{$f};
                if ($current instanceof \Carbon\Carbon) {
                    $current = $current->toDateString();
                }

                return (string) ($current ?? '') === (string) ($v ?? '');
            });

            if ($unchanged && $existing->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            Ri::where('id', $existing->id)->update($new + ['accurate_synced_at' => now()]);

            return [
                'bucket' => 'updated', 'action' => 'UPDATE',
                'message' => "RI {$row->INVOICENO} baris {$row->SEQ} diperbarui.",
                'old_data' => $old, 'new_data' => $new,
            ];
        }

        Ri::create($new + [
            'accurate_apinvoice_id' => $row->APINVOICEID,
            'accurate_seq' => $row->SEQ,
            'accurate_synced_at' => now(),
        ]);

        return [
            'bucket' => 'inserted', 'action' => 'INSERT',
            'message' => "RI baru dari invoice {$row->INVOICENO} baris {$row->SEQ}.",
            'new_data' => $new,
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
