<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        return response()->json([
            'data' => [
                ...$this->batchSummary($syncBatch),
                'logs' => $syncBatch->logs()->latest('id')->limit(500)->get(),
            ],
        ]);
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
            'started_at' => $batch->started_at,
            'finished_at' => $batch->finished_at,
            'duration_seconds' => $batch->durationSeconds(),
            'total_records' => $batch->total_records,
            'inserted_records' => $batch->inserted_records,
            'updated_records' => $batch->updated_records,
            'skipped_records' => $batch->skipped_records,
            'error_records' => $batch->error_records,
            'error_message' => $batch->error_message,
        ];
    }
}
