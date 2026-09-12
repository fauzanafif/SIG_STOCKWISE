# Sync Architecture — Accurate → Stockwise

## Layers

```
ACCURATE 5 DELUXE (Firebird 2.5, GUDANGSIG2025.GDB)
        │  read-only, TCP 3050
        ▼
sync-service/ (Python) — holds the real Firebird credentials, its own .env,
never runs on the same box as production Hostinger deployment target.
        │  mirrors 20 whitelisted tables into MySQL, full drop+reload each run
        ▼
accurate_* staging tables (MySQL `stockwise` DB) — raw, Accurate's own column
names/types, read-only from Stockwise's own business logic's point of view.
        │  matched/transformed by Laravel (PHP), triggered via POST /api/sync/accurate
        ▼
items (+ 3 reference columns: accurate_synced_at, accurate_qty_onhand,
accurate_qty_onorder) — Stockwise's own existing table, untouched schema
otherwise. sync_batches + sync_logs record every run.
        │
        ▼
Laravel API → React frontend (Sync Accurate / Sync History / Dashboard widget)
```

## Why Laravel doesn't connect to Firebird directly

Checked first, not assumed: this PHP 8.4.23 (ZTS, VC17, x64) install has no `pdo_firebird` or
`interbase` extension, and a recovered `php_pdo_firebird.dll` found on this machine turned out to be
built for a different PHP ABI (loads its dependencies fine, but PHP can't find the expected module
entry point — confirmed via direct test, not assumed). Rather than compile a custom extension (a
real, distinct, avoidable amount of risk), Laravel orchestrates the already-working Python
sync-service as a subprocess. This also matches the master prompt's own architecture diagram, which
draws "Accurate Connector / Sync Service" as a layer distinct from and prior to "Laravel API" — so
this isn't a workaround, it's the specified shape.

**Windows-specific gotcha (found, not theoretical):** the shell that starts `php artisan serve` can
hand down a `PATH` the Firebird client library can't use (observed with Git Bash/MSYS — raw TCP
connectivity from a Laravel-spawned process worked, but `fbclient.dll`'s own dependency resolution
failed until an explicit clean `PATH` including its directory was passed to the child process). Fixed
in `AccurateSyncService::refreshStaging()` via `Process::env([...])`; `ACCURATE_FIREBIRD_CLIENT_DIR`
in `.env` controls it.

## Why the stock number is a reference column, not a ledger entry

Accurate's `WAREHS` table has exactly one row, named `CENTRE` — a generic default, not a real
location. Stockwise's 7 warehouses all belong to one site (`SIG-SDA`), and Accurate's own
`BRANCHCODES` is a single, still-unconfigured placeholder row. Conclusion (confirmed with the user):
Accurate's stock figure is a site-wide total that cannot be safely attributed to any one of
Stockwise's 7 physical warehouses. Forcing it into the per-warehouse `inventory` table would fabricate
precision Accurate's own data doesn't have. It's stored on `items.accurate_qty_onhand` instead — a
cross-check number, not a replacement for Stockwise's own actual/reserved/available ledger.

## Idempotency / upsert

`AccurateSyncService::syncOneItem()` matches by `items.code = accurate_item.ITEMNO`:
- No match + has a unit (`UNIT1`) + has a description → **INSERT** a new item.
- No match + no unit → **SKIP** (Accurate's `PARENTITEM` category-placeholder node, not a real
  product — see `docs/accurate-database-analysis.md` §6).
- Match, values differ → **UPDATE** (only the 3 reference columns).
- Match, values identical → **SKIP** ("no change" — this is what makes running the sync twice safe;
  verified in `tests/Feature/Accurate/AccurateSyncTest.php`).
- Any row-level exception → **ERROR**, logged, does not abort the rest of the batch.

## Sync lock

`sync_batches.status = RUNNING` acts as the lock: a new sync request returns the in-progress batch
instead of starting a second one. A `RUNNING` batch older than 10 minutes is treated as a crashed
process and reaped to `FAILED` before the lock check runs, so a real crash can't hold the lock
forever (`AccurateSyncService::reapStaleRunningBatches()`).
