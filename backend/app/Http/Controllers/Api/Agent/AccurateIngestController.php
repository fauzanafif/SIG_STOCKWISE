<?php

namespace App\Http\Controllers\Api\Agent;

use App\Http\Controllers\Controller;
use App\Models\SyncBatch;
use App\Services\Accurate\AccurateSyncService;
use App\Services\Accurate\StagingTableWriter;
use Illuminate\Http\Request;
use Throwable;

/**
 * Receives one staging-refresh push from the office Sync Agent
 * (sync-service/agent/) — the only place in the app a network payload is
 * allowed to shape the accurate_* MySQL tables. Never reachable by a human
 * user: gated by the `agent:sync` Sanctum ability, not the RBAC `permission:`
 * middleware (see routes/api.php's `agent` group).
 *
 * Flow: startSession() -> N x pushTable() (one call per chunk per table) ->
 * complete() (hands off to AccurateSyncService::finishFromStaging(), the
 * same mapping/business logic the manual "Sync Accurate" button already
 * uses) — or fail() if the Agent couldn't even get this far (backup restore
 * or Firebird read failed before any data was ready to push).
 */
class AccurateIngestController extends Controller
{
    public function startSession(AccurateSyncService $service)
    {
        $batch = $service->startForAgent();

        return response()->json([
            'data' => [
                'id' => $batch->id,
                'sync_code' => $batch->sync_code,
                'status' => $batch->status,
                // false means an already-RUNNING batch was returned instead
                // (another sync — manual or a previous Agent tick — is still
                // going) — the Agent should treat this tick as skipped, not
                // push into someone else's session.
                'is_new_session' => $batch->wasRecentlyCreated,
            ],
        ], 201);
    }

    public function pushTable(Request $request, SyncBatch $syncBatch, string $table, StagingTableWriter $writer)
    {
        if ($syncBatch->status !== 'RUNNING') {
            return response()->json(['message' => 'This sync session is no longer running.'], 409);
        }

        $data = $request->validate([
            'columns' => ['required', 'array', 'min:1'],
            'columns.*.name' => ['required', 'string'],
            'columns.*.type' => ['required', 'string'],
            'columns.*.length' => ['nullable', 'integer', 'min:0'],
            'columns.*.nullable' => ['required', 'boolean'],
            'primary_key' => ['sometimes', 'array'],
            'primary_key.*' => ['string'],
            'rows' => ['present', 'array'], // an empty table in Accurate right now is a legitimate push, not an error
            'is_first_chunk' => ['required', 'boolean'],
        ]);

        try {
            if ($data['is_first_chunk']) {
                $writer->createTable($table, $data['columns'], $data['primary_key'] ?? []);
            }

            $inserted = $writer->insertRows($table, $data['rows']);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => ['table' => $table, 'inserted' => $inserted]]);
    }

    public function complete(SyncBatch $syncBatch, AccurateSyncService $service)
    {
        if ($syncBatch->status !== 'RUNNING') {
            return response()->json(['message' => 'This sync session is no longer running.'], 409);
        }

        // Same reasoning as the manual "Sync Accurate" trigger
        // (SyncController::store()) — finishFromStaging() walks every staged
        // row plus derives STPP/UsedReturn over the existing npbg/ri tables;
        // confirmed against real local data this can take well past PHP's
        // default 30s (~90s observed for ~20k records).
        set_time_limit(300);

        $batch = $service->finishFromStaging($syncBatch);

        return response()->json(['data' => ['id' => $batch->id, 'sync_code' => $batch->sync_code, 'status' => $batch->status]]);
    }

    public function fail(Request $request, SyncBatch $syncBatch, AccurateSyncService $service)
    {
        $data = $request->validate(['message' => ['required', 'string', 'max:2000']]);

        if ($syncBatch->status !== 'RUNNING') {
            return response()->json(['message' => 'This sync session is no longer running.'], 409);
        }

        $batch = $service->failBatch($syncBatch, $data['message']);

        return response()->json(['data' => ['id' => $batch->id, 'sync_code' => $batch->sync_code, 'status' => $batch->status]]);
    }
}
