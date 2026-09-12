"""Read-only Firebird metadata + data access. Never writes to the Accurate database."""
import fdb

from config.settings import FirebirdSettings

FIREBIRD_TYPE_NAMES = {
    7: "SMALLINT",
    8: "INTEGER",
    9: "QUAD",
    10: "FLOAT",
    12: "DATE",
    13: "TIME",
    14: "CHAR",
    16: "BIGINT",
    27: "DOUBLE PRECISION",
    35: "TIMESTAMP",
    37: "VARCHAR",
    40: "CSTRING",
    45: "BLOB_ID",
    261: "BLOB",
}

_api_loaded = False


def connect():
    global _api_loaded
    FirebirdSettings.require_credentials()
    if not _api_loaded:
        fdb.load_api(fb_library_name=FirebirdSettings.client_lib)
        _api_loaded = True
    return fdb.connect(
        dsn=FirebirdSettings.dsn(),
        user=FirebirdSettings.user,
        password=FirebirdSettings.password,
        charset="UTF8",
    )


def fetch_columns(cur, table):
    cur.execute(
        """
        SELECT
            TRIM(rf.RDB$FIELD_NAME) AS COLUMN_NAME,
            f.RDB$FIELD_TYPE AS FIELD_TYPE,
            f.RDB$FIELD_LENGTH AS FIELD_LENGTH,
            f.RDB$FIELD_PRECISION AS FIELD_PRECISION,
            f.RDB$FIELD_SCALE AS FIELD_SCALE,
            f.RDB$FIELD_SUB_TYPE AS FIELD_SUB_TYPE,
            rf.RDB$NULL_FLAG AS NOT_NULL,
            rf.RDB$FIELD_POSITION AS FIELD_POSITION
        FROM RDB$RELATION_FIELDS rf
        JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
        WHERE rf.RDB$RELATION_NAME = ?
        ORDER BY rf.RDB$FIELD_POSITION
        """,
        (table,),
    )
    cols = []
    for row in cur.fetchall():
        name, ftype, flen, fprec, fscale, fsub, notnull, pos = row
        cols.append(
            {
                "name": name.strip(),
                "type": FIREBIRD_TYPE_NAMES.get(ftype, f"TYPE_{ftype}"),
                "length": flen,
                "precision": fprec,
                "scale": fscale,
                "sub_type": fsub,
                "not_null": bool(notnull),
                "position": pos,
            }
        )
    return cols


def fetch_primary_key(cur, table):
    cur.execute(
        """
        SELECT TRIM(s.RDB$FIELD_NAME)
        FROM RDB$RELATION_CONSTRAINTS rc
        JOIN RDB$INDEX_SEGMENTS s ON s.RDB$INDEX_NAME = rc.RDB$INDEX_NAME
        WHERE rc.RDB$RELATION_NAME = ? AND rc.RDB$CONSTRAINT_TYPE = 'PRIMARY KEY'
        ORDER BY s.RDB$FIELD_POSITION
        """,
        (table,),
    )
    return [row[0].strip() for row in cur.fetchall()]


def fetch_rows(cur, table, columns, batch_size=2000):
    """Yield rows from a Firebird table as lists, in column-position order."""
    col_list = ", ".join(f'"{c["name"]}"' for c in columns)
    cur.execute(f'SELECT {col_list} FROM "{table}"')
    while True:
        batch = cur.fetchmany(batch_size)
        if not batch:
            break
        for row in batch:
            yield row
