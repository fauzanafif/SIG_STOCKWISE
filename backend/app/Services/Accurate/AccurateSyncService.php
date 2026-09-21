<?php

namespace App\Services\Accurate;

use App\Models\Item;
use App\Models\Npbg;
use App\Models\Ppb;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Ri;
use App\Models\Site;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\StppTransaction;
use App\Models\SyncBatch;
use App\Models\SyncLog;
use App\Models\Unit;
use App\Models\UsedReturn;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\DocumentNumberService;
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

    /**
     * Kategori Induk — NOT walked from Accurate's PARENTITEM chain (there is
     * no top-level node to read there, see PRODUCT_ITEMNO_PATTERN docblock).
     * Instead, per explicit user instruction, derived from the item code's
     * own 3-letter prefix through this fixed translation table.
     */
    protected const KATEGORI_INDUK_MAP = [
        'SSP' => 'Small Spare Parts',
        'PUI' => 'Post-Use Items',
        'OFN' => 'Office Needs',
        'OAA' => 'Office Apparel & Accessories',
        'MAA' => 'Manufacture & Assembly',
        'MAI' => 'Maintenance & Industry',
        'HHN' => 'Household Needs',
        'ETA' => 'Etalase',
        'EAE' => 'Electronics & Electricals',
        'BSP' => 'Big Spare Parts',
        'AUT' => 'Automotive',
        'AST' => 'Assets',
    ];

    public function __construct(private readonly DocumentNumberService $numbers) {}

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
            'current_step' => 'staging',
            'created_by' => $userId,
        ]);

        try {
            $this->refreshStaging();
        } catch (Throwable $e) {
            $batch->update([
                'status' => 'FAILED',
                'finished_at' => now(),
                'current_step' => null,
                'error_message' => $e->getMessage(),
            ]);

            return $batch->fresh();
        }

        // Order matters: PO's accurate_ppb_id resolves against `ppb` rows, and
        // RI's accurate_po_item_id resolves against `purchase_order_items`
        // rows — both need their upstream link already synced in this same run
        // (see AccurateSyncService::syncPo()/syncRi() chain-linking comments).
        // current_step is a short stable key (frontend maps it to a label),
        // written before each phase (not after) so a client polling
        // GET /api/sync/status mid-run — see useSyncStatus()'s fast refetch
        // while RUNNING — can show real progress, not a guess.
        $batch->update(['current_step' => 'items']);
        $itemCounts = $this->syncItems($batch);

        $batch->update(['current_step' => 'npbg']);
        $npbgCounts = $this->syncNpbg($batch);

        // Derived from the NPBG rows just synced above, not from Accurate
        // staging tables directly — see deriveStppFromNpbg() docblock.
        $batch->update(['current_step' => 'stpp']);
        $stppCounts = $this->deriveStppFromNpbg($batch);

        $batch->update(['current_step' => 'ppb']);
        $ppbCounts = $this->syncPpb($batch);

        $batch->update(['current_step' => 'po']);
        $poCounts = $this->syncPo($batch);

        $batch->update(['current_step' => 'ri']);
        $riCounts = $this->syncRi($batch);

        // Derived from the RI rows just synced above (divisi "NV" only, per
        // explicit user instruction) — see deriveUsedReturnsFromRi() docblock.
        $batch->update(['current_step' => 'used_returns']);
        $usedReturnCounts = $this->deriveUsedReturnsFromRi($batch);

        $batch->update(['current_step' => 'stock_opname']);
        $soCounts = $this->syncStockOpname($batch);
        $counts = [
            'total' => $itemCounts['total'] + $npbgCounts['total'] + $stppCounts['total'] + $ppbCounts['total'] + $riCounts['total'] + $usedReturnCounts['total'] + $poCounts['total'] + $soCounts['total'],
            'inserted' => $itemCounts['inserted'] + $npbgCounts['inserted'] + $stppCounts['inserted'] + $ppbCounts['inserted'] + $riCounts['inserted'] + $usedReturnCounts['inserted'] + $poCounts['inserted'] + $soCounts['inserted'],
            'updated' => $itemCounts['updated'] + $npbgCounts['updated'] + $ppbCounts['updated'] + $riCounts['updated'] + $poCounts['updated'] + $soCounts['updated'],
            'skipped' => $itemCounts['skipped'] + $npbgCounts['skipped'] + $stppCounts['skipped'] + $ppbCounts['skipped'] + $riCounts['skipped'] + $usedReturnCounts['skipped'] + $poCounts['skipped'] + $soCounts['skipped'],
            // Only npbg/ppb/ri ever produce a 'deleted' bucket (see deleteOrphans()) — item/po/stock_opname arrays don't have that key at all.
            'deleted' => ($npbgCounts['deleted'] ?? 0) + ($ppbCounts['deleted'] ?? 0) + ($riCounts['deleted'] ?? 0),
            'errors' => $itemCounts['errors'] + $npbgCounts['errors'] + $stppCounts['errors'] + $ppbCounts['errors'] + $riCounts['errors'] + $usedReturnCounts['errors'] + $poCounts['errors'] + $soCounts['errors'],
        ];

        $batch->update([
            'finished_at' => now(),
            'current_step' => null,
            'total_records' => $counts['total'],
            'inserted_records' => $counts['inserted'],
            'updated_records' => $counts['updated'],
            'skipped_records' => $counts['skipped'],
            'deleted_records' => $counts['deleted'],
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
                'current_step' => null,
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
            'id', 'code', 'description', 'accurate_qty_onhand', 'accurate_qty_onorder', 'accurate_synced_at',
            'accurate_category_anak_1', 'accurate_category_anak_2', 'accurate_category_anak_3', 'accurate_category_induk'
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
        $newDescription = trim((string) ($row->ITEMDESCRIPTION ?? ''));
        $newInduk = $this->deriveKategoriInduk($itemno);

        if ($existing) {
            // description is included here (not just qty/category) so that a
            // corrected Firebird decode — e.g. the Ø/etc. that previously came
            // through as U+FFFD before the Windows-1252 charset fix — actually
            // reaches already-existing items on re-sync, not just brand-new ones.
            $unchanged = (float) $existing->accurate_qty_onhand === $newQty
                && (float) $existing->accurate_qty_onorder === $newOnOrder
                && $existing->accurate_category_anak_1 === $category['anak_1']
                && $existing->accurate_category_anak_2 === $category['anak_2']
                && $existing->accurate_category_anak_3 === $category['anak_3']
                && $existing->accurate_category_induk === $newInduk
                && ($newDescription === '' || $existing->description === $newDescription);

            if ($unchanged && $existing->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            $old = [
                'accurate_qty_onhand' => $existing->accurate_qty_onhand,
                'accurate_qty_onorder' => $existing->accurate_qty_onorder,
                'accurate_category_anak_1' => $existing->accurate_category_anak_1,
                'accurate_category_anak_2' => $existing->accurate_category_anak_2,
                'accurate_category_anak_3' => $existing->accurate_category_anak_3,
                'accurate_category_induk' => $existing->accurate_category_induk,
                'description' => $existing->description,
            ];

            $existing->forceFill([
                'accurate_synced_at' => now(),
                'accurate_qty_onhand' => $newQty,
                'accurate_qty_onorder' => $newOnOrder,
                'accurate_category_anak_1' => $category['anak_1'],
                'accurate_category_anak_2' => $category['anak_2'],
                'accurate_category_anak_3' => $category['anak_3'],
                'accurate_category_induk' => $newInduk,
            ]);
            if ($newDescription !== '') {
                $existing->forceFill(['description' => $newDescription]);
            }
            $existing->save();

            return [
                'bucket' => 'updated',
                'action' => 'UPDATE',
                'message' => "Data referensi Accurate diperbarui: qty {$old['accurate_qty_onhand']} -> {$newQty}.",
                'old_data' => $old,
                'new_data' => [
                    'accurate_qty_onhand' => $newQty, 'accurate_qty_onorder' => $newOnOrder,
                    'accurate_category_induk' => $newInduk,
                    'description' => $newDescription !== '' ? $newDescription : $old['description'],
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
            'accurate_category_induk' => $newInduk,
        ]);

        return [
            'bucket' => 'inserted',
            'action' => 'INSERT',
            'message' => 'Barang baru dari Accurate.',
            'new_data' => ['code' => $created->code, 'description' => $created->description, 'accurate_category_induk' => $newInduk] + $category,
        ];
    }

    /**
     * Kategori Induk — see KATEGORI_INDUK_MAP. Unrecognized prefixes (a new
     * category the user hasn't told us about yet) fall through to null
     * rather than guessing, same policy as every other derived field here.
     */
    protected function deriveKategoriInduk(string $itemno): ?string
    {
        $prefix = mb_strtoupper(substr($itemno, 0, 3));

        return self::KATEGORI_INDUK_MAP[$prefix] ?? null;
    }

    /**
     * NPBG/PPB/RI must end up an exact mirror of Accurate — per explicit user
     * instruction, a row that no longer appears in Accurate's current data
     * (deleted there) is hard-deleted here too, not just flagged. Safe to do:
     * traced every FK across the schema that once pointed at npbg/ppb — the
     * tracking tables (lend/borrow/stpp/tyre/maintenance/manufacturing/used-
     * return) were built against the OLD internal npbg/ppb tables, and
     * migrations 2026_09_15_090001/2026_09_16_100001 renamed those away to
     * goods_issues/purchase_proposals before these Accurate-mirror tables
     * were (re)created under the freed names — MySQL's RENAME TABLE moved
     * those FKs along with the rename, so they point at the renamed tables,
     * never at npbg/ppb/ri. The only real dependent is npbg_verifications
     * (cascadeOnDelete), which correctly disappears with its source document.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Database\Eloquent\Model>  $existingByKey  every Stockwise row before this sync, keyed the same way as $seenKeys
     * @param  array<int, string>  $seenKeys  keys still present in Accurate's current data
     * @param  \Closure(\Illuminate\Database\Eloquent\Model): string  $describe  short human label for the log message, e.g. fn ($n) => "NPBG {$n->no_npbg} baris ini"
     * @return array{deleted:int,errors:int}
     */
    protected function deleteOrphans(\Illuminate\Support\Collection $existingByKey, array $seenKeys, SyncBatch $batch, string $entity, \Closure $describe): array
    {
        // A staging refresh that silently comes back empty (Python step "ran"
        // but wrote 0 rows — network blip, partial Firebird read, etc.) must
        // never be read as "Accurate deleted everything" — that would wipe the
        // whole table. Only trust the fetch enough to delete when it actually
        // returned SOME current rows; a suspicious all-empty fetch is refused
        // and flagged instead, leaving existing data untouched.
        if ($seenKeys === [] && $existingByKey->isNotEmpty()) {
            SyncLog::create([
                'sync_batch_id' => $batch->id,
                'entity' => $entity,
                'source_id' => '-',
                'action' => 'ERROR',
                'status' => 'FAILED',
                'message' => "Accurate tidak mengembalikan data {$entity} sama sekali padahal Stockwise sudah punya {$existingByKey->count()} baris — kemungkinan staging refresh gagal sebagian, bukan penghapusan massal di Accurate. Deteksi hapus dilewati demi keamanan data; periksa sync-service.",
            ]);

            return ['deleted' => 0, 'errors' => 1];
        }

        // $existingByKey is really an Eloquent\Collection (keyBy()'d by an
        // Accurate composite key, e.g. "REQID-SEQ") — its except()/only() are
        // overridden to filter by the model's PRIMARY KEY, not the collection
        // key, so calling except() directly here would silently match nothing
        // and treat every row as an orphan. Re-wrapping as a plain Collection
        // restores the normal by-array-key semantics we actually want.
        $orphans = collect($existingByKey->all())->except($seenKeys);

        foreach ($orphans as $key => $model) {
            SyncLog::create([
                'sync_batch_id' => $batch->id,
                'entity' => $entity,
                'source_id' => (string) $key,
                'action' => 'DELETE',
                'status' => 'SUCCESS',
                'message' => $describe($model).' sudah tidak ada di Accurate — dihapus dari Stockwise.',
                'old_data' => $model->toArray(),
            ]);
            $model->delete();
        }

        return ['deleted' => $orphans->count(), 'errors' => 0];
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
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'deleted' => 0];

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

        $seenKeys = [];
        foreach ($rows as $row) {
            $counts['total']++;
            $seenKeys[] = $row->ARINVOICEID.'-'.$row->SEQ;

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

        $orphanResult = $this->deleteOrphans($existing, $seenKeys, $batch, 'npbg', fn ($n) => "NPBG {$n->no_npbg} baris ini");
        $counts['deleted'] = $orphanResult['deleted'];
        $counts['errors'] += $orphanResult['errors'];

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
     * STPP (alat ber-serial diserahkan ke divisi/holder, docs/status-flow.md
     * §8) had zero rows despite the workflow being real — its "issue" form
     * requires manually picking an out_npbg, and nothing ever surfaced which
     * NPBG lines were STPP placements, so nobody ever did. Per explicit user
     * instruction, they're identified by NPBG's own keterangan mentioning
     * "STPP" (189 real lines already do, e.g. "U/ DIJADIKAN STPP GUDANG..."),
     * so every synced NPBG line matching that is turned into an StppTransaction
     * automatically — one NPBG line, one STPP row, keyed by out_npbg_id so a
     * repeat sync never creates a duplicate. Runs after syncNpbg() (reads its
     * output), not against Accurate staging tables directly. Serial number,
     * holder_id and placement_department_id aren't in NPBG's data at all —
     * left null for a person to fill in, same as every other "don't guess
     * what isn't there" derived field this sync produces.
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function deriveStppFromNpbg(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        $unitsByCode = Unit::pluck('id', 'code')->keyBy(fn ($id, $code) => mb_strtoupper(trim($code)));
        $itemIdsByCode = Item::pluck('id', 'code');
        $alreadyDerived = StppTransaction::whereNotNull('out_npbg_id')->pluck('out_npbg_id')->flip();

        $rows = Npbg::where('keterangan', 'like', '%STPP%')->orderBy('id')->get();

        foreach ($rows as $npbg) {
            $counts['total']++;

            try {
                if (isset($alreadyDerived[$npbg->id])) {
                    $counts['skipped']++;
                    SyncLog::create([
                        'sync_batch_id' => $batch->id, 'entity' => 'stpp', 'source_id' => (string) $npbg->id,
                        'action' => 'SKIP', 'status' => 'SUCCESS',
                        'message' => 'STPP sudah pernah dibuat dari baris NPBG ini.',
                    ]);

                    continue;
                }

                $unit1 = $npbg->satuan ? mb_strtoupper(trim($npbg->satuan)) : null;
                $stpp = StppTransaction::create([
                    'number' => $this->numbers->next('STPP', 'SDA', $npbg->tgl_npbg ?? now()),
                    'item_id' => $npbg->kode_barang ? $itemIdsByCode->get($npbg->kode_barang) : null,
                    'description_raw' => $npbg->deskripsi_barang ?: '-',
                    'qty' => $npbg->kuantitas ?: 1,
                    'unit_id' => $unit1 ? $unitsByCode->get($unit1) : null,
                    'holder_name_raw' => $npbg->peminta,
                    'out_npbg_id' => $npbg->id,
                    'out_date' => $npbg->tgl_npbg ?? now(),
                    'status' => 'ACTIVE',
                    'out_note' => $npbg->keterangan,
                ]);

                $counts['inserted']++;
                SyncLog::create([
                    'sync_batch_id' => $batch->id, 'entity' => 'stpp', 'source_id' => (string) $npbg->id,
                    'action' => 'INSERT', 'status' => 'SUCCESS',
                    'message' => "STPP {$stpp->number} dibuat dari NPBG {$npbg->no_npbg} (keterangan menyebut STPP).",
                    'new_data' => [
                        'number' => $stpp->number, 'kode_barang' => $npbg->kode_barang,
                        'deskripsi_barang' => $stpp->description_raw, 'peminta' => $stpp->holder_name_raw,
                        'keterangan' => $stpp->out_note,
                    ],
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;
                SyncLog::create([
                    'sync_batch_id' => $batch->id, 'entity' => 'stpp', 'source_id' => (string) $npbg->id,
                    'action' => 'ERROR', 'status' => 'FAILED', 'message' => $e->getMessage(),
                ]);
            }
        }

        return $counts;
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
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'deleted' => 0];

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

        $seenKeys = [];
        foreach ($rows as $row) {
            $counts['total']++;
            $seenKeys[] = $row->REQID.'-'.$row->SEQ;

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

        $orphanResult = $this->deleteOrphans($existing, $seenKeys, $batch, 'ppb', fn ($p) => "PPB {$p->no_ppb} baris ini");
        $counts['deleted'] = $orphanResult['deleted'];
        $counts['errors'] += $orphanResult['errors'];

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
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'deleted' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_apitmdet') || ! DB::getSchemaBuilder()->hasTable('accurate_apinv')) {
            return $counts;
        }

        $existing = Ri::query()
            ->select('id', 'accurate_apinvoice_id', 'accurate_seq', 'no_ri', 'tgl_ri', 'divisi', 'vendor', 'vendor_id', 'no_po',
                'shipdate', 'keterangan', 'kode_barang', 'deskripsi_barang', 'kuantitas', 'satuan', 'harga_satuan',
                'pemeriksa', 'accurate_po_item_id', 'accurate_synced_at')
            ->get()
            ->keyBy(fn ($r) => $r->accurate_apinvoice_id.'-'.$r->accurate_seq);

        // Accurate's own APITMDET.POID/POSEQ chains an RI line back to the PO
        // line it received against — verified against real data (an APITMDET
        // row's POID/POSEQ resolves to a real PODET row, same ITEMNO).
        // Resolved once into our own purchase_order_items' ids; only ~46% of
        // RI lines have this (the rest are internal stock-take style
        // receipts with no PO), which is expected, not a bug.
        $poItemIdByPoKey = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereNotNull('purchase_order_items.accurate_seq')
            ->whereNotNull('purchase_orders.accurate_po_id')
            ->get(['purchase_order_items.id', 'purchase_orders.accurate_po_id', 'purchase_order_items.accurate_seq'])
            ->keyBy(fn ($i) => $i->accurate_po_id.'-'.$i->accurate_seq)
            ->map(fn ($i) => $i->id);

        $rows = DB::table('accurate_apitmdet as d')
            ->join('accurate_apinv as h', 'd.APINVOICEID', '=', 'h.APINVOICEID')
            ->leftJoin('accurate_persondata as v', 'h.VENDORID', '=', 'v.ID')
            ->select(
                'd.APINVOICEID as APINVOICEID', 'd.SEQ as SEQ', 'd.ITEMNO', 'd.ITEMOVDESC', 'd.QUANTITY', 'd.ITEMUNIT',
                'd.UNITPRICE', 'd.ITEMRESERVED3', 'd.POID', 'd.POSEQ',
                'h.INVOICENO', 'h.INVOICEDATE', 'h.PURCHASEORDERNO', 'h.SHIPDATE', 'h.DESCRIPTION',
                'v.NAME as VENDORNAME'
            )
            ->orderBy('d.APINVOICEID')->orderBy('d.SEQ')
            ->get();

        $seenKeys = [];
        foreach ($rows as $row) {
            $counts['total']++;
            $seenKeys[] = $row->APINVOICEID.'-'.$row->SEQ;

            try {
                $outcome = $this->syncOneRiLine($row, $existing, $poItemIdByPoKey);
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

        $orphanResult = $this->deleteOrphans($existing, $seenKeys, $batch, 'ri', fn ($r) => "RI {$r->no_ri} baris ini");
        $counts['deleted'] = $orphanResult['deleted'];
        $counts['errors'] += $orphanResult['errors'];

        return $counts;
    }

    /**
     * @return array{bucket:string,action:string,message:string,old_data?:array,new_data?:array}
     */
    protected function syncOneRiLine(object $row, \Illuminate\Support\Collection $existingByKey, \Illuminate\Support\Collection $poItemIdByPoKey): array
    {
        if ($row->APINVOICEID === null || $row->SEQ === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'APINVOICEID/SEQ kosong — data tidak valid.'];
        }

        $key = $row->APINVOICEID.'-'.$row->SEQ;

        // Same PERSONDATA source as PO's vendor — resolve to the same
        // `vendors` row (via firstOrCreate's unique(name), idempotent whether
        // PO or RI sync gets to a given vendor first) rather than leaving the
        // RI<->PO vendor relationship as two separately-typed strings.
        $vendor = $this->findOrCreateAccurateVendor($row->VENDORNAME);

        $new = [
            'no_ri' => $row->INVOICENO,
            'tgl_ri' => $row->INVOICEDATE,
            'divisi' => $this->deriveDivisiFromDocNumber($row->INVOICENO),
            'vendor' => $row->VENDORNAME,
            'vendor_id' => $vendor->id,
            'no_po' => $row->PURCHASEORDERNO,
            'shipdate' => $row->SHIPDATE,
            'keterangan' => $row->DESCRIPTION,
            'kode_barang' => $row->ITEMNO,
            'deskripsi_barang' => $row->ITEMOVDESC,
            'kuantitas' => $row->QUANTITY !== null ? (float) $row->QUANTITY : null,
            'satuan' => $row->ITEMUNIT,
            'harga_satuan' => $row->UNITPRICE !== null ? (float) $row->UNITPRICE : null,
            'pemeriksa' => $row->ITEMRESERVED3,
            'accurate_po_item_id' => ($row->POID !== null && $row->POSEQ !== null)
                ? $poItemIdByPoKey->get($row->POID.'-'.$row->POSEQ)
                : null,
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
     * PO — unlike NPBG/PPB/RI, `purchase_orders`/`purchase_order_items` already
     * represent the real thing (an order sent to a vendor), just not yet
     * reconciled against Accurate's own PO/PODET. Per explicit user decision,
     * there is no separate mirror page here: real Accurate POs are synced
     * straight into these tables as additional rows (accurate_po_id/accurate_seq
     * set), sitting alongside whatever the internal DRAFT->APPROVED->SENT->
     * RECEIVED workflow already created (accurate_po_id null) — no attempt is
     * made to match/merge the two, since there is no reliable key linking an
     * internally-generated PO number to a real Accurate PONO.
     *
     * Counted per PO header (not per PODET line, unlike the other syncers)
     * since PO/PODET is a real relational header+lines document, matching the
     * shape purchase_orders/purchase_order_items already has.
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncPo(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_po') || ! DB::getSchemaBuilder()->hasTable('accurate_podet')) {
            return $counts;
        }

        $unitsByCode = Unit::pluck('id', 'code')->keyBy(fn ($id, $code) => mb_strtoupper(trim($code)));
        $itemIdsByCode = Item::pluck('id', 'code');
        $defaultSiteId = Site::query()->orderBy('id')->value('id');

        // Accurate's own PODET.REQID/REQSEQ chains a PO line back to the PPB
        // (REQUISITIONDET) line it was raised from — verified against real
        // data (a PODET row's REQID/REQSEQ resolves to a real REQUISITIONDET
        // row, same ITEMNO). Resolved once into our own `ppb` mirror's ids.
        $ppbIdByReqKey = Ppb::query()->select('id', 'accurate_reqid', 'accurate_seq')->get()
            ->keyBy(fn ($p) => $p->accurate_reqid.'-'.$p->accurate_seq)
            ->map(fn ($p) => $p->id);

        $headers = DB::table('accurate_po as h')
            ->leftJoin('accurate_persondata as v', 'h.VENDORID', '=', 'v.ID')
            ->select(
                'h.POID as POID', 'h.PONO', 'h.PODATE', 'h.EXPECTED', 'h.CLOSED', 'h.POAMOUNT',
                'h.TAX1AMOUNT', 'h.TAX2AMOUNT', 'h.DESCRIPTION', 'v.NAME as VENDORNAME'
            )
            ->orderBy('h.POID')
            ->get();

        foreach ($headers as $h) {
            $counts['total']++;

            try {
                $outcome = $this->syncOnePo($h, $unitsByCode, $itemIdsByCode, $defaultSiteId, $ppbIdByReqKey);
                $counts[$outcome['bucket']]++;

                SyncLog::create([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'po',
                    'source_id' => (string) $h->POID,
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
                    'entity' => 'po',
                    'source_id' => (string) ($h->POID ?? '?'),
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
    protected function syncOnePo(
        object $h,
        \Illuminate\Support\Collection $unitsByCode,
        \Illuminate\Support\Collection $itemIdsByCode,
        ?int $defaultSiteId,
        \Illuminate\Support\Collection $ppbIdByReqKey
    ): array {
        if ($h->POID === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'POID kosong — data tidak valid.'];
        }

        $lines = DB::table('accurate_podet')
            ->where('POID', $h->POID)
            ->select('SEQ', 'ITEMNO', 'ITEMOVDESC', 'QUANTITY', 'QTYRECV', 'UNITPRICE', 'ITEMUNIT', 'CLOSED', 'REQID', 'REQSEQ')
            ->orderBy('SEQ')
            ->get();

        $totalQty = (float) $lines->sum('QUANTITY');
        $totalRecv = (float) $lines->sum('QTYRECV');
        // Accurate has no DRAFT/SUBMITTED concept — a PO recorded there is
        // already a committed document — so status is derived from received
        // quantity (richer than Accurate's own CLOSED flag alone).
        $status = match (true) {
            $totalQty > 0 && $totalRecv >= $totalQty - 1e-6 => 'RECEIVED',
            $totalRecv > 0 => 'PARTIAL_RECEIVED',
            (int) $h->CLOSED === 1 => 'CLOSED',
            default => 'SENT',
        };

        $tax = (float) (($h->TAX1AMOUNT ?? 0) + ($h->TAX2AMOUNT ?? 0));
        $total = (float) ($h->POAMOUNT ?? 0);

        $new = [
            'number' => $h->PONO,
            'date' => $h->PODATE,
            'expected_date' => $h->EXPECTED,
            'status' => $status,
            'subtotal' => round($total - $tax, 2),
            'tax' => $tax,
            'total' => $total,
            'notes' => $h->DESCRIPTION,
        ];

        $vendor = $this->findOrCreateAccurateVendor($h->VENDORNAME);

        $po = PurchaseOrder::where('accurate_po_id', $h->POID)->first();

        if ($po) {
            $old = collect($new)->keys()->mapWithKeys(fn ($f) => [$f => $po->{$f} instanceof \Carbon\Carbon ? $po->{$f}->toDateString() : $po->{$f}])->all();
            $headerUnchanged = collect($new)->every(function ($v, $f) use ($po) {
                $current = $po->{$f};
                if ($current instanceof \Carbon\Carbon) {
                    $current = $current->toDateString();
                }

                return (string) ($current ?? '') === (string) ($v ?? '');
            }) && $po->vendor_id === $vendor->id;

            // Lines are always (re)synced even when the header looks unchanged
            // — e.g. totalRecv can move from 5 to 8 out of 10 without the
            // derived header status changing, but the individual line's own
            // qty_received/line_status still needs to move.
            $linesChanged = $this->syncPoLines($po, $lines, $unitsByCode, $itemIdsByCode, $ppbIdByReqKey);

            if ($headerUnchanged && ! $linesChanged && $po->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            $po->forceFill($new + ['vendor_id' => $vendor->id, 'accurate_synced_at' => now()])->save();

            return [
                'bucket' => 'updated', 'action' => 'UPDATE',
                'message' => "PO {$h->PONO} diperbarui.",
                'old_data' => $old, 'new_data' => $new,
            ];
        }

        $po = PurchaseOrder::create($new + [
            'accurate_po_id' => $h->POID,
            'vendor_id' => $vendor->id,
            'site_id' => $defaultSiteId,
            'accurate_synced_at' => now(),
        ]);
        $this->syncPoLines($po, $lines, $unitsByCode, $itemIdsByCode, $ppbIdByReqKey);

        return [
            'bucket' => 'inserted', 'action' => 'INSERT',
            'message' => "PO baru dari Accurate: {$h->PONO}.",
            'new_data' => $new,
        ];
    }

    /**
     * Upsert every PODET line for one PO header, keyed by (purchase_order_id,
     * accurate_seq). Returns whether anything actually changed, so the caller
     * can fold that into its own unchanged/skip decision.
     */
    protected function syncPoLines(PurchaseOrder $po, \Illuminate\Support\Collection $lines, \Illuminate\Support\Collection $unitsByCode, \Illuminate\Support\Collection $itemIdsByCode, \Illuminate\Support\Collection $ppbIdByReqKey): bool
    {
        $existingBySeq = PurchaseOrderItem::where('purchase_order_id', $po->id)
            ->whereNotNull('accurate_seq')
            ->get()
            ->keyBy('accurate_seq');

        $changed = false;

        foreach ($lines as $line) {
            $qty = (float) ($line->QUANTITY ?? 0);
            $unitPrice = (float) ($line->UNITPRICE ?? 0);
            $qtyReceived = (float) ($line->QTYRECV ?? 0);
            $lineStatus = match (true) {
                $qty > 0 && $qtyReceived >= $qty - 1e-6 => 'RECEIVED',
                $qtyReceived > 0 => 'PARTIAL_RECEIVED',
                (int) ($line->CLOSED ?? 0) === 1 => 'CLOSED',
                default => 'PENDING',
            };

            $attrs = [
                'item_id' => $itemIdsByCode->get($line->ITEMNO),
                'description_raw' => $line->ITEMOVDESC ?? '-',
                'qty' => $qty,
                'unit_id' => $unitsByCode->get(mb_strtoupper(trim((string) ($line->ITEMUNIT ?? '')))),
                'unit_price' => $unitPrice,
                'line_total' => round($qty * $unitPrice, 2),
                'qty_received' => $qtyReceived,
                'line_status' => $lineStatus,
                'accurate_ppb_id' => ($line->REQID !== null && $line->REQSEQ !== null)
                    ? $ppbIdByReqKey->get($line->REQID.'-'.$line->REQSEQ)
                    : null,
            ];

            $existing = $existingBySeq->get($line->SEQ);
            if ($existing) {
                $lineUnchanged = collect($attrs)->every(fn ($v, $f) => (string) ($existing->{$f} ?? '') === (string) ($v ?? ''));
                if (! $lineUnchanged) {
                    $existing->forceFill($attrs)->save();
                    $changed = true;
                }
            } else {
                PurchaseOrderItem::create($attrs + ['purchase_order_id' => $po->id, 'accurate_seq' => $line->SEQ]);
                $changed = true;
            }
        }

        return $changed;
    }

    /**
     * Find a Vendor by exact name (case-sensitive, matching the unique
     * constraint on vendors.name) or create a placeholder flagged for human
     * review — same source='accurate'+needs_review=true pattern already used
     * by App\Console\Commands\Accurate\ImportNewItems for auto-created rows.
     */
    protected function findOrCreateAccurateVendor(?string $name): Vendor
    {
        $name = trim((string) $name) ?: 'VENDOR ACCURATE TANPA NAMA';

        return Vendor::firstOrCreate(
            ['name' => $name],
            ['is_active' => true, 'needs_review' => true, 'source' => 'accurate']
        );
    }

    /**
     * Pengembalian Bekas (UsedReturn) had the same problem as STPP: a real
     * workflow with no data behind it, because nothing ever surfaced which
     * Accurate records represented one. Per explicit user instruction, the
     * source here is the RI mirror's own divisi "NV" documents (e.g.
     * "RI/NV/25/IX/001") — confirmed against real data (2,534 lines across
     * 1,017 invoices) and, per the user's own follow-up answer, taken as-is
     * with no extra keyword filtering, even though many of them read as
     * warehouse data-migration or fabrication events rather than literally
     * "used item" returns — that's a business-content judgment call outside
     * what this sync can verify, not something to silently narrow down.
     *
     * One Accurate AP invoice (accurate_apinvoice_id) -> one UsedReturn
     * header, its lines -> used_return_items, keyed by accurate_apinvoice_id
     * so a repeat sync never duplicates it (see the "used_returns" migration
     * adding that column). Created PENDING, same as a manually-entered one —
     * still goes through the normal "Tutup & Masukkan ke Stok" review step
     * before anything touches stock_movements/inventory (never automatic,
     * matching this class's own "Accurate's own stock effect is reference-
     * only" rule, restated in syncStockOpname()'s docblock below).
     *
     * `condition`/`into_stock` have no equivalent in RI's data at all — per
     * the user's explicit instruction to derive them from Accurate's own
     * database rather than a fixed guess, they come from the item's own
     * Kategori Induk (see KATEGORI_INDUK_MAP): "Post-Use Items" (prefix PUI)
     * -> REUSABLE + into_stock, anything else -> USED + not into stock, left
     * for a person to correct on review — same "don't fabricate what isn't
     * there" policy as every other derived field in this class.
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function uniqueUsedReturnNumber(string $noRi): string
    {
        if (! UsedReturn::where('number', $noRi)->exists()) {
            return $noRi;
        }

        for ($suffix = 2; ; $suffix++) {
            $candidate = "{$noRi} ({$suffix})";
            if (! UsedReturn::where('number', $candidate)->exists()) {
                return $candidate;
            }
        }
    }

    protected function deriveUsedReturnsFromRi(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        $itemsByCode = Item::select('id', 'code', 'accurate_category_induk')->get()->keyBy('code');
        $unitsByCode = Unit::pluck('id', 'code')->keyBy(fn ($id, $code) => mb_strtoupper(trim($code)));
        $alreadyDerived = UsedReturn::whereNotNull('accurate_apinvoice_id')->pluck('accurate_apinvoice_id')->flip();
        $defaultSiteId = Site::query()->orderBy('id')->value('id');

        $grouped = Ri::where('divisi', 'NV')
            ->orderBy('accurate_apinvoice_id')->orderBy('accurate_seq')
            ->get()
            ->groupBy('accurate_apinvoice_id');

        foreach ($grouped as $apinvoiceId => $lines) {
            $counts['total']++;

            try {
                if (isset($alreadyDerived[$apinvoiceId])) {
                    $counts['skipped']++;
                    SyncLog::create([
                        'sync_batch_id' => $batch->id, 'entity' => 'used_return', 'source_id' => (string) $apinvoiceId,
                        'action' => 'SKIP', 'status' => 'SUCCESS',
                        'message' => 'Pengembalian Bekas sudah pernah dibuat dari RI ini.',
                    ]);

                    continue;
                }

                $first = $lines->first();
                $ur = UsedReturn::create([
                    // The RI's own number IS this record's number — per explicit user
                    // instruction, a separately generated "UR/SDA/..." number isn't
                    // wanted for rows that already have a real Accurate document number.
                    // used_returns.number is unique, and 5 real RI/NV numbers in
                    // production are reused across two different invoices (a data
                    // quirk in Accurate itself) — disambiguated with a suffix so the
                    // rare collision never silently drops a row.
                    'number' => $this->uniqueUsedReturnNumber($first->no_ri),
                    'accurate_apinvoice_id' => $apinvoiceId,
                    // npbg_ref_raw is specifically "reference NPBG number" (shown in the
                    // UI as "NPBG asal") — an RI-derived row has no NPBG; left null
                    // rather than overloading the wrong field.
                    'return_date' => $first->tgl_ri ?? now(),
                    'status' => 'PENDING',
                    'format' => 'ITEM_LINE',
                    'site_id' => $defaultSiteId,
                    'note' => $first->keterangan,
                ]);

                foreach ($lines as $i => $line) {
                    $item = $line->kode_barang ? $itemsByCode->get($line->kode_barang) : null;
                    $unit1 = $line->satuan ? mb_strtoupper(trim($line->satuan)) : null;
                    $isPostUse = $item?->accurate_category_induk === 'Post-Use Items';

                    $ur->items()->create([
                        'item_id' => $item?->id,
                        'description_raw' => $line->deskripsi_barang,
                        'qty' => $line->kuantitas ?: 1,
                        'unit_id' => $unit1 ? $unitsByCode->get($unit1) : null,
                        'condition' => $isPostUse ? 'REUSABLE' : 'USED',
                        'into_stock' => $isPostUse,
                        'item_no' => $i + 1,
                    ]);
                }

                $counts['inserted']++;
                SyncLog::create([
                    'sync_batch_id' => $batch->id, 'entity' => 'used_return', 'source_id' => (string) $apinvoiceId,
                    'action' => 'INSERT', 'status' => 'SUCCESS',
                    'message' => "Pengembalian Bekas {$ur->number} dibuat dari RI {$first->no_ri} ({$lines->count()} baris).",
                    'new_data' => ['number' => $ur->number, 'no_ri' => $first->no_ri, 'keterangan' => $first->keterangan],
                ]);
            } catch (Throwable $e) {
                $counts['errors']++;
                SyncLog::create([
                    'sync_batch_id' => $batch->id, 'entity' => 'used_return', 'source_id' => (string) $apinvoiceId,
                    'action' => 'ERROR', 'status' => 'FAILED', 'message' => $e->getMessage(),
                ]);
            }
        }

        return $counts;
    }

    /**
     * Stock Opname — same situation as PO: `stock_opnames`/`stock_opname_items`
     * already represent the real thing (a physical count reconciled against
     * system qty), just not yet synced against Accurate's own record of it.
     * Accurate's ITEMADJ (header) + ITADJDET (lines) is that record — verified
     * against real data: the DESCRIPTION literally says e.g. "STOK OPNAME TGL
     * 10.09.2026", and ITADJDET.NEWQTY/CURRENTQTY/QTYDIFFERENCE map exactly
     * onto physical_qty/system_qty/difference.
     *
     * No line-level accurate_seq column: stock_opname_items already enforces
     * unique(stock_opname_id, item_id), which doubles as the right upsert key
     * here (one line per item per opname) — one known ITEMADJ has the same
     * ITEMNO twice across two SEQs; the second simply overwrites the first,
     * an acceptable resolution for a single edge case in the source data.
     *
     * Accurate's own stock effect is NOT re-applied to stock_movements/
     * inventory — same rule as every other Accurate sync in this app
     * (Accurate's qty is reference-only, see this class's docblock). This
     * only mirrors the opname record itself; no stock_adjustments row is
     * created and StockLedgerService is never called.
     *
     * Counted per ITEMADJ header (not per ITADJDET line), same reasoning as
     * syncPo(): a real relational header+lines document, matching the shape
     * stock_opnames/stock_opname_items already has.
     *
     * @return array{total:int,inserted:int,updated:int,skipped:int,errors:int}
     */
    protected function syncStockOpname(SyncBatch $batch): array
    {
        $counts = ['total' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];

        if (! DB::getSchemaBuilder()->hasTable('accurate_itemadj') || ! DB::getSchemaBuilder()->hasTable('accurate_itadjdet')) {
            return $counts;
        }

        $itemIdsByCode = Item::pluck('id', 'code');
        $defaultSiteId = Site::query()->orderBy('id')->value('id');
        $defaultWarehouseId = Warehouse::query()->orderBy('id')->value('id');

        $headers = DB::table('accurate_itemadj')
            ->select('ITEMADJID', 'ADJNO', 'ADJDATE', 'ADJCHECK', 'DESCRIPTION')
            ->orderBy('ITEMADJID')
            ->get();

        foreach ($headers as $h) {
            $counts['total']++;

            try {
                $outcome = $this->syncOneStockOpname($h, $itemIdsByCode, $defaultSiteId, $defaultWarehouseId);
                $counts[$outcome['bucket']]++;

                SyncLog::create([
                    'sync_batch_id' => $batch->id,
                    'entity' => 'stock_opname',
                    'source_id' => (string) $h->ITEMADJID,
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
                    'entity' => 'stock_opname',
                    'source_id' => (string) ($h->ITEMADJID ?? '?'),
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
    protected function syncOneStockOpname(
        object $h,
        \Illuminate\Support\Collection $itemIdsByCode,
        ?int $defaultSiteId,
        ?int $defaultWarehouseId
    ): array {
        if ($h->ITEMADJID === null) {
            return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'ITEMADJID kosong — data tidak valid.'];
        }

        $lines = DB::table('accurate_itadjdet')
            ->where('ITEMADJID', $h->ITEMADJID)
            ->select('SEQ', 'ITEMNO', 'NEWQTY', 'CURRENTQTY')
            ->orderBy('SEQ')
            ->get();

        // Accurate has no SCHEDULED/IN_PROGRESS concept — a physical count
        // only ever reaches ITEMADJ once counted — so ADJCHECK (posted/final
        // in Accurate's own books) is the only signal available: checked ->
        // COMPLETED (matches our terminal reviewed-and-approved state),
        // unchecked -> PENDING_REVIEW (counts are in, not yet finalized).
        $status = (int) $h->ADJCHECK === 1 ? 'COMPLETED' : 'PENDING_REVIEW';

        $new = [
            'number' => (string) $h->ADJNO,
            'scheduled_date' => $h->ADJDATE,
            'status' => $status,
        ];

        $opname = StockOpname::where('accurate_itemadj_id', $h->ITEMADJID)->first();

        if ($opname) {
            $old = collect($new)->keys()->mapWithKeys(fn ($f) => [$f => $opname->{$f} instanceof \Carbon\Carbon ? $opname->{$f}->toDateString() : $opname->{$f}])->all();
            $headerUnchanged = collect($new)->every(function ($v, $f) use ($opname) {
                $current = $opname->{$f};
                if ($current instanceof \Carbon\Carbon) {
                    $current = $current->toDateString();
                }

                return (string) ($current ?? '') === (string) ($v ?? '');
            });

            // Lines are always (re)synced even when the header looks
            // unchanged — same reasoning as syncPoLines().
            $linesChanged = $this->syncStockOpnameLines($opname, $lines, $itemIdsByCode, $status, $defaultWarehouseId);

            if ($headerUnchanged && ! $linesChanged && $opname->accurate_synced_at !== null) {
                return ['bucket' => 'skipped', 'action' => 'SKIP', 'message' => 'Tidak ada perubahan.'];
            }

            $opname->forceFill($new + ['accurate_synced_at' => now()])->save();

            return [
                'bucket' => 'updated', 'action' => 'UPDATE',
                'message' => "Stock Opname {$h->ADJNO} diperbarui.",
                'old_data' => $old, 'new_data' => $new,
            ];
        }

        $opname = StockOpname::create($new + [
            'accurate_itemadj_id' => $h->ITEMADJID,
            'site_id' => $defaultSiteId,
            'warehouse_id' => $defaultWarehouseId,
            'type' => 'PARTIAL',
            'accurate_synced_at' => now(),
        ]);
        $this->syncStockOpnameLines($opname, $lines, $itemIdsByCode, $status, $defaultWarehouseId);

        return [
            'bucket' => 'inserted', 'action' => 'INSERT',
            'message' => "Stock Opname baru dari Accurate: {$h->ADJNO}.",
            'new_data' => $new,
        ];
    }

    /**
     * Upsert every ITADJDET line for one ITEMADJ header, keyed by
     * (stock_opname_id, item_id) — stock_opname_items' own unique
     * constraint. Returns whether anything actually changed.
     */
    protected function syncStockOpnameLines(StockOpname $opname, \Illuminate\Support\Collection $lines, \Illuminate\Support\Collection $itemIdsByCode, string $headerStatus, ?int $defaultWarehouseId): bool
    {
        $existingByItemId = StockOpnameItem::where('stock_opname_id', $opname->id)->get()->keyBy('item_id');
        // Never-mutated snapshot of the DB state as it was BEFORE this pass,
        // purely for the "did the final state actually change" check below.
        $originalByItemId = $existingByItemId->map(fn ($i) => $i->only(['item_id', 'warehouse_id', 'system_qty', 'physical_qty', 'count_status', 'review_status']));
        $reviewStatus = $headerStatus === 'COMPLETED' ? 'APPROVED' : 'PENDING';

        // One known ITEMADJID repeats the same ITEMNO across two SEQs (see
        // class docblock) — always write every line (last SEQ wins for that
        // item), and separately track just the final attrs actually reached
        // per item, so "changed" reflects the end result rather than
        // flip-flopping mid-pass on that one edge case.
        $finalAttrsByItemId = [];

        foreach ($lines as $line) {
            $itemId = $itemIdsByCode->get($line->ITEMNO);
            if ($itemId === null) {
                continue; // ITEMNO not a real Master Barang product (or not yet synced) — skip rather than guess.
            }

            $attrs = [
                'item_id' => $itemId,
                'warehouse_id' => $defaultWarehouseId,
                'system_qty' => (float) ($line->CURRENTQTY ?? 0),
                'physical_qty' => (float) ($line->NEWQTY ?? 0),
                'count_status' => 'COUNTED',
                'review_status' => $reviewStatus,
            ];
            $finalAttrsByItemId[$itemId] = $attrs;

            $existing = $existingByItemId->get($itemId);
            if ($existing) {
                $existing->forceFill($attrs)->save();
            } else {
                $existing = StockOpnameItem::create($attrs + ['stock_opname_id' => $opname->id]);
                $existingByItemId->put($itemId, $existing);
            }
        }

        foreach ($finalAttrsByItemId as $itemId => $attrs) {
            $original = $originalByItemId->get($itemId);
            $unchanged = $original !== null && collect($attrs)->every(fn ($v, $f) => (string) ($original[$f] ?? '') === (string) ($v ?? ''));
            if (! $unchanged) {
                return true;
            }
        }

        return false;
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
