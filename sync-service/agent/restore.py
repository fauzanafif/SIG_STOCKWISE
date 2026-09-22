"""Restores a .GBK backup into the Agent's own staging Firebird database via
gbak -r. Only ever writes to StagingSettings.database_path() — never the
source backup file, and never D:\\GUDANGSIG2025.GDB (brief §9, §12, §43).
"""
import os
import subprocess

from config.settings import FirebirdSettings, StagingSettings


class RestoreError(RuntimeError):
    pass


def restore_backup(backup_path: str, timeout_seconds: int = 900) -> str:
    """Restores `backup_path` into the staging DB, replacing whatever was
    there before (the staging DB only ever holds the most recently restored
    backup — it doesn't accumulate state across ticks). Returns the staging
    DB path on success; raises RestoreError otherwise.
    """
    FirebirdSettings.require_credentials()
    os.makedirs(StagingSettings.path, exist_ok=True)
    staging_db = StagingSettings.database_path()

    cmd = [
        StagingSettings.gbak_bin,
        # -rep (REPLACE_DATABASE): overwrites the target if it already
        # exists — confirmed against real gbak behavior: plain -r
        # (RECREATE_DATABASE) fails with "database ... already exists" on a
        # second run instead, since the staging DB is deliberately
        # overwritten fresh on every tick, not accumulated across runs.
        "-rep",
        "-user", FirebirdSettings.user,
        "-pass", FirebirdSettings.password,
        backup_path,
        staging_db,
    ]

    try:
        result = subprocess.run(cmd, capture_output=True, text=True, timeout=timeout_seconds)
    except FileNotFoundError as exc:
        raise RestoreError(f"gbak binary not found at '{StagingSettings.gbak_bin}': {exc}") from exc
    except subprocess.TimeoutExpired as exc:
        raise RestoreError(f"gbak restore timed out after {timeout_seconds}s: {exc}") from exc

    if result.returncode != 0:
        detail = (result.stderr or result.stdout or "").strip()
        raise RestoreError(f"gbak restore failed (exit {result.returncode}): {detail}")

    return staging_db
