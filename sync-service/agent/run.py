"""Sync Agent entrypoint — scan -> stability check -> restore -> read ->
push, on its own schedule (brief §3-17). Runs independently at the office;
never shells out from or is shelled out to by Laravel (docs/sync-architecture.md).

    python -m agent.run              # loop forever, waking at SYNC_TIMES
    python -m agent.run --sync-now   # run one pass immediately, then exit (brief §41)
    python -m agent.run --dry-run    # scan + log only, no restore/push/state changes

Not installed as a Windows Service yet (brief §42) — run as a plain
foreground process (or under a simple process supervisor) until this has
been observed stable over real scheduled ticks.
"""
import argparse
import base64
import datetime
import decimal
import logging
import os
import sys
import time
from logging.handlers import RotatingFileHandler

sys.path.insert(0, ".")

from agent import scanner
from agent import state as state_mod
from agent.api_client import StockwiseApiClient
from agent.restore import restore_backup
from agent.scheduler import next_run_at, seconds_until
from agent.validate import validate_gudang_staging
from config.settings import AgentSettings, BackupSettings
from firebird import introspect as fb
from mapping.tables import MIRROR_TABLES
from mapping.type_mapping import firebird_column_to_mysql_type

logger = logging.getLogger("agent")


def setup_logging() -> None:
    os.makedirs(AgentSettings.log_path, exist_ok=True)
    logger.setLevel(logging.INFO)
    fmt = logging.Formatter("%(asctime)s %(levelname)s: %(message)s")

    agent_handler = RotatingFileHandler(
        os.path.join(AgentSettings.log_path, "agent.log"), maxBytes=5_000_000, backupCount=5, encoding="utf-8"
    )
    agent_handler.setFormatter(fmt)
    logger.addHandler(agent_handler)

    error_handler = RotatingFileHandler(
        os.path.join(AgentSettings.log_path, "error.log"), maxBytes=5_000_000, backupCount=5, encoding="utf-8"
    )
    error_handler.setLevel(logging.ERROR)
    error_handler.setFormatter(fmt)
    logger.addHandler(error_handler)

    console = logging.StreamHandler()
    console.setFormatter(fmt)
    logger.addHandler(console)


def _sync_log_path() -> str:
    return os.path.join(AgentSettings.log_path, "sync.log")


def _append_sync_log(filename: str, status: str, error: str | None) -> None:
    with open(_sync_log_path(), "a", encoding="utf-8") as f:
        f.write(f"{datetime.datetime.now().isoformat()} backup={filename} status={status} error={error or '-'}\n")


def _json_safe(value):
    """Firebird rows can carry date/datetime/Decimal/bytes — none of those
    are JSON-serializable as-is (requests' json= uses json.dumps).
    """
    if value is None:
        return None
    if isinstance(value, (datetime.datetime, datetime.date, datetime.time)):
        return value.isoformat()
    if isinstance(value, decimal.Decimal):
        return float(value)
    if isinstance(value, bytes):
        try:
            return value.decode("utf-8")
        except UnicodeDecodeError:
            return base64.b64encode(value).decode("ascii")
    return value


def run_once(dry_run: bool = False) -> bool:
    """One full tick. Returns True if a sync actually ran (success or
    failure), False if there was nothing to do (already processed, still
    being written, or a sync is already in progress elsewhere).
    """
    state = state_mod.AgentState()

    files = scanner.list_backups()
    if not files:
        logger.info("No .GBK backups found in %s", BackupSettings.path)
        return False

    candidate = scanner.pick_candidate(files, state.is_success)
    if candidate is None:
        logger.info("All %d backup(s) already processed successfully. Nothing to do.", len(files))
        return False

    logger.info(
        "Candidate backup: %s (%.1f MB, modified %s)",
        candidate.filename, candidate.size / 1_048_576, time.ctime(candidate.modified_at),
    )

    if dry_run:
        logger.info("[dry-run] Would check stability and process %s — stopping here.", candidate.filename)
        return False

    if not scanner.wait_until_stable(candidate.path, BackupSettings.stability_check_seconds):
        logger.info("%s is still being written (size changed) — will re-check next tick.", candidate.filename)
        return False

    state.mark_processing(candidate.filename, candidate.size, candidate.modified_at)
    client = StockwiseApiClient()
    batch_id = None

    try:
        staging_db = restore_backup(candidate.path)
        logger.info("Restored %s -> %s", candidate.filename, staging_db)

        fb_conn = fb.connect()
        try:
            cur = fb_conn.cursor()

            # Second safety layer (brief §9 STEP 7, TEST 7) — the filename
            # filter alone (GUDANGSIG2025*.gbk) is not trusted. Runs BEFORE
            # start_session() on purpose: a validation failure never touches
            # the API at all, so it never leaves a stray RUNNING batch behind.
            validate_gudang_staging(cur, fb.fetch_columns, fb.fetch_rows)
            logger.info("Staging database identity validated: looks like Accurate's Gudang database.")

            session = client.start_session()
            batch_id = session["id"]
            if not session.get("is_new_session", True):
                logger.info("A sync is already RUNNING (batch %s) — skipping this tick, will retry next.", batch_id)
                return False

            for table in MIRROR_TABLES:
                _push_table(cur, table, client, batch_id)
        finally:
            fb_conn.close()

        batch = client.complete(batch_id)
        logger.info(
            "Sync complete for %s: batch %s status=%s",
            candidate.filename, batch.get("sync_code"), batch.get("status"),
        )
        state.mark_success(candidate.filename)
        _append_sync_log(candidate.filename, batch.get("status", "UNKNOWN"), None)
        return True

    except Exception as exc:
        logger.exception("Sync failed for %s", candidate.filename)
        state.mark_failed(candidate.filename, str(exc))
        _append_sync_log(candidate.filename, "FAILED", str(exc))
        if batch_id is not None:
            try:
                client.fail(batch_id, str(exc)[:2000])
            except Exception:
                logger.exception("Could not report the failure back to the API for batch %s either.", batch_id)
        return True


def _push_table(cur, table: str, client: StockwiseApiClient, batch_id: int, chunk_size: int = 1000) -> None:
    columns = fb.fetch_columns(cur, table)
    if not columns:
        logger.warning("Table %s not found in staging DB — skipping.", table)
        return

    primary_key = fb.fetch_primary_key(cur, table)
    api_columns = [{"name": c["name"], **firebird_column_to_mysql_type(c)} for c in columns]
    column_names = [c["name"] for c in columns]

    total = 0
    is_first = True
    chunk: list[dict] = []

    for row in fb.fetch_rows(cur, table, columns):
        chunk.append({name: _json_safe(v) for name, v in zip(column_names, row)})
        if len(chunk) >= chunk_size:
            client.push_table(batch_id, table, api_columns, chunk, primary_key, is_first_chunk=is_first)
            total += len(chunk)
            is_first = False
            chunk = []

    if chunk or is_first:
        # is_first still True with an empty chunk means the table has zero
        # rows right now — still push once so it gets (re)created empty.
        client.push_table(batch_id, table, api_columns, chunk, primary_key, is_first_chunk=is_first)
        total += len(chunk)

    logger.info("Pushed %s: %d row(s)", table, total)


def main() -> None:
    parser = argparse.ArgumentParser(description="Stockwise Sync Agent")
    parser.add_argument("--sync-now", action="store_true", help="Run one pass immediately and exit")
    parser.add_argument("--dry-run", action="store_true", help="Scan only, no restore/push")
    args = parser.parse_args()

    setup_logging()

    if args.sync_now or args.dry_run:
        run_once(dry_run=args.dry_run)
        return

    logger.info("Sync Agent starting. Schedule: %s", ", ".join(AgentSettings.sync_times))
    while True:
        target = next_run_at(datetime.datetime.now())
        wait = seconds_until(target, datetime.datetime.now())
        logger.info("Next run at %s (in %.0f minute(s))", target.isoformat(), wait / 60)
        time.sleep(wait)
        try:
            run_once()
        except Exception:
            logger.exception("Unexpected error during scheduled run — will retry next tick.")


if __name__ == "__main__":
    main()
