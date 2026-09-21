<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Item;
use App\Models\SyncBatch;
use App\Services\Accurate\AccurateSyncService;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function status()
    {
        $latest = SyncBatch::where('source', 'accurate')->latest('id')->first();

        return response()->json([
            'data' => $latest ? $this->batchSummary($latest) : null,
        ]);
    }

    public function history(Request $request)
    {
        $page = SyncBatch::where('source', 'accurate')
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return response()->json([
            'data' => $page->getCollection()->map(fn (SyncBatch $b) => $this->batchSummary($b))->values(),
            'meta' => [
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(SyncBatch $syncBatch)
    {
        // A full sync can log tens of thousands of SKIP rows (e.g. Stock
        // Opname re-checking ~2,500 unchanged items every run) that would
        // otherwise crowd the ADD/UPDATE/DELETE/ERROR rows entirely out of
        // this capped window — surface the actually-interesting actions
        // first, only filling the rest of the 500 with SKIP once those run out.
        $logs = $syncBatch->logs()
            ->orderByRaw("CASE action WHEN 'ERROR' THEN 0 WHEN 'DELETE' THEN 1 WHEN 'INSERT' THEN 2 WHEN 'UPDATE' THEN 3 ELSE 4 END")
            ->latest('id')
            ->limit(500)
            ->get();

        return response()->json([
            'data' => [
                ...$this->batchSummary($syncBatch),
                'logs' => $this->attachItemInfo($logs),
            ],
        ]);
    }

    /**
     * "ID Barang"/"Nama Barang" per baris log — untuk entity 'item' kode
     * barangnya adalah source_id itu sendiri (ITEMNO), untuk npbg/ppb/ri ada
     * di payload sebagai kode_barang. po/stock_opname log per HEADER
     * (mencakup banyak barang sekaligus) jadi memang tidak ada satu barang
     * untuk diresolusi di sana — item_id/item_name-nya null.
     */
    protected function attachItemInfo(\Illuminate\Support\Collection $logs): \Illuminate\Support\Collection
    {
        $codeOf = function ($log) {
            if ($log->entity === 'item') {
                return $log->source_id ?: null;
            }
            $data = $log->new_data ?? $log->old_data ?? [];

            return $data['kode_barang'] ?? null;
        };

        $codes = $logs->map($codeOf)->filter()->unique()->values();
        $itemsByCode = Item::whereIn('code', $codes)->select('id', 'code', 'description')->get()->keyBy('code');

        return $logs->map(function ($log) use ($codeOf, $itemsByCode) {
            $code = $codeOf($log);
            $item = $code ? $itemsByCode->get($code) : null;
            $data = $log->new_data ?? $log->old_data ?? [];

            $log->setAttribute('item_id', $item?->id);
            $log->setAttribute('item_name', $data['deskripsi_barang'] ?? $data['description'] ?? $item?->description ?? null);

            return $log;
        });
    }

    public function store(Request $request, AccurateSyncService $service)
    {
        // Staging refresh (~15s for 20 tables) + ~9,600-row item upsert can
        // exceed PHP's default 30s limit; matches the pattern already used by
        // LegacyExportController for its own long-running operation.
        set_time_limit(300);

        $batch = $service->run($request->user()?->id);

        return response()->json([
            'data' => $this->batchSummary($batch),
        ], $batch->status === 'FAILED' ? 502 : 201);
    }

    protected function batchSummary(SyncBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'sync_code' => $batch->sync_code,
            'source' => $batch->source,
            'status' => $batch->status,
            'current_step' => $batch->current_step,
            'started_at' => $batch->started_at,
            'finished_at' => $batch->finished_at,
            'duration_seconds' => $batch->durationSeconds(),
            'total_records' => $batch->total_records,
            'inserted_records' => $batch->inserted_records,
            'updated_records' => $batch->updated_records,
            'skipped_records' => $batch->skipped_records,
            'deleted_records' => $batch->deleted_records,
            'error_records' => $batch->error_records,
            'error_message' => $batch->error_message,
        ];
    }
}
