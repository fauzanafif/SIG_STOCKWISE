# sync-service — Accurate Sync Agent

Runs independently at the office (never on the same box as Laravel in production — see
`docs/sync-architecture.md`). Scans for Accurate's `.GBK` backups, restores the newest stable one to a
local staging Firebird DB, and pushes the mirrored tables to the Stockwise Laravel API.

## Setup

```bash
pip install -r requirements.txt        # runtime
pip install -r requirements-dev.txt    # + pytest, for running tests

cp .env.example .env                   # fill in FIREBIRD_USER/PASSWORD and API_TOKEN
```

Get `API_TOKEN` from the Laravel backend:

```bash
cd ../backend
php artisan stockwise:agent-token
```

## Running

```bash
python -m agent.run              # loop forever, waking at SYNC_TIMES (04:00, 10:30, 20:30)
python -m agent.run --sync-now   # run one pass immediately, then exit (for testing)
python -m agent.run --dry-run    # scan only, log what would happen, no restore/push
```

Not a Windows Service yet (brief §42) — run it as a plain console process (or under a simple process
supervisor of your choice) until it's been observed stable over real scheduled ticks.

Logs go to `AGENT_LOG_PATH` (`D:\01.STOCKWISE\Logs\{agent,error,sync}.log` by default). Processed-backup
state is a small SQLite file at `AGENT_STATE_PATH` — a backup already marked `SUCCESS` is never
reprocessed; `FAILED` ones are retried automatically on the next scheduled tick.

## Tests

```bash
python -m pytest tests/
```

Covers the scanner (backup discovery, stability detection), state store (processed/retry logic), and
scheduler (04:00/10:30/20:30 parsing) with no real Firebird/MySQL/network dependency. The
restore → Firebird read → API push path itself needs a real Firebird server and is exercised by
`tests/Feature/Agent/AccurateIngestTest.php` on the Laravel side (the ingest endpoints, with fixture
data standing in for a real push) — running the *whole* pipeline end-to-end against a real `.GBK` still
needs a live Firebird service, which isn't always available in dev; see `docs/sync-architecture.md`.

## Layout

```
agent/
  scanner.py      # find .GBK backups, check they've finished being written
  state.py        # processed-backup tracking (SQLite)
  restore.py      # gbak -r into the staging DB
  api_client.py   # push to POST /api/agent/sync/*
  scheduler.py    # 04:00/10:30/20:30 timing
  run.py          # ties it together — the actual entrypoint

firebird/         # read-only Firebird metadata + row access (unchanged, reused by the Agent)
mapping/          # table whitelist + Firebird->MySQL type mapping (unchanged, reused by the Agent)

sync/, database/mysql_client.py   # legacy direct-Firebird-to-MySQL path, deprecated — not used by
                                   # agent/run.py, kept until the Agent flow above is validated
                                   # end-to-end against a real Firebird server, then removed.
```
