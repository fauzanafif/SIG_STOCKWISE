# API — Accurate Sync

All endpoints require Sanctum auth (`Authorization: Bearer <token>`) plus the listed permission.

## `POST /api/sync/accurate`

Permission: `sync.accurate.trigger`.

Triggers one full sync run (staging refresh + item upsert). Synchronous — the request blocks until
the run finishes (staging ~13s + item matching ~35s for ~9,600 rows ≈ 50s observed). Returns the
batch summary.

Response `201`:
```json
{ "data": {
    "id": 5, "sync_code": "SYNC-20260912-005", "source": "accurate", "status": "SUCCESS",
    "started_at": "...", "finished_at": "...", "duration_seconds": 49,
    "total_records": 9600, "inserted_records": 0, "updated_records": 0,
    "skipped_records": 9600, "error_records": 0, "error_message": null
} }
```

Response `502` (Firebird unreachable, bad credentials, sync-service crashed) — `status: "FAILED"`,
`error_message` carries the real underlying error (never a generic "something went wrong").

If a sync is already `RUNNING`, returns that in-progress batch instead of starting a second one
(sync lock).

## `GET /api/sync/status`

Permission: `sync.accurate.view`. Latest batch's summary (or `data: null` if never run).

## `GET /api/sync/history?page=1&per_page=20`

Permission: `sync.accurate.view`. Paginated list of past batches, newest first.

## `GET /api/sync/history/{id}`

Permission: `sync.accurate.view`. One batch's summary plus up to 500 of its `sync_logs` rows
(`entity`, `source_id`, `action`, `status`, `message`, `old_data`, `new_data`).
