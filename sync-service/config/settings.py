"""Connection settings for the Accurate <-> Stockwise sync service.

All credentials come from environment variables — never hardcoded, never guessed.
"""
import os
import sys

from dotenv import load_dotenv

# Load sync-service/.env before any settings below are read. python-dotenv does
# not override variables already set in the real environment (e.g. by a
# scheduler), so this is safe to call unconditionally.
load_dotenv(os.path.join(os.path.dirname(os.path.dirname(__file__)), ".env"))


class FirebirdSettings:
    host = os.environ.get("FIREBIRD_HOST", "localhost")
    port = os.environ.get("FIREBIRD_PORT", "3050")
    database = os.environ.get("FIREBIRD_DATABASE", r"D:\STOCKWISE\GUDANGSIG2025.GDB")
    user = os.environ.get("FIREBIRD_USER")
    password = os.environ.get("FIREBIRD_PASSWORD")
    client_lib = os.environ.get(
        "FIREBIRD_CLIENT_LIB", r"D:\FirebirdLocal\Firebird_25\bin\fbclient.dll"
    )

    @classmethod
    def dsn(cls):
        return f"{cls.host}/{cls.port}:{cls.database}"

    @classmethod
    def require_credentials(cls):
        if not cls.user or not cls.password:
            print(
                "ERROR: FIREBIRD_USER and FIREBIRD_PASSWORD environment variables are "
                "required. Refusing to guess or fall back to default credentials.",
                file=sys.stderr,
            )
            sys.exit(1)


class MySQLSettings:
    host = os.environ.get("MYSQL_HOST", "127.0.0.1")
    port = int(os.environ.get("MYSQL_PORT", "3306"))
    database = os.environ.get("MYSQL_DATABASE", "stockwise")
    user = os.environ.get("MYSQL_USER", "root")
    password = os.environ.get("MYSQL_PASSWORD", "")
    # Match Laravel's migration default so accurate_* staging tables can be
    # JOINed against Stockwise's own tables without a COLLATE clause.
    collation = "utf8mb4_unicode_ci"

    @classmethod
    def connect_kwargs(cls):
        return dict(
            host=cls.host,
            port=cls.port,
            database=cls.database,
            user=cls.user,
            password=cls.password,
            charset="utf8mb4",
        )
