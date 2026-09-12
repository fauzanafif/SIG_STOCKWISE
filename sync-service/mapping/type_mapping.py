"""Firebird -> MySQL column type mapping.

This mirrors Accurate's data as literally as reasonable (per the user's request to
keep the same format), only translating types where Firebird and MySQL genuinely
differ (e.g. Firebird has no native BOOLEAN, MySQL has no BLOB sub-type distinction).
Values themselves are copied verbatim — no reinterpretation (e.g. BIGINT money
columns are NOT rescaled to DECIMAL; their real scale is still unconfirmed per
docs/ACCURATE_MAPPING.md open items).
"""

MYSQL_MAX_VARCHAR = 16000  # conservative ceiling before falling back to TEXT (utf8mb4 row-size limits)


def firebird_column_to_mysql_ddl(col: dict) -> str:
    ftype = col["type"]
    length = col["length"] or 0
    not_null = " NOT NULL" if col["not_null"] else " NULL"

    if ftype == "SMALLINT":
        sql_type = "SMALLINT"
    elif ftype == "INTEGER":
        sql_type = "INT"
    elif ftype == "BIGINT":
        sql_type = "BIGINT"
    elif ftype in ("FLOAT",):
        sql_type = "FLOAT"
    elif ftype == "DOUBLE PRECISION":
        sql_type = "DOUBLE"
    elif ftype == "DATE":
        sql_type = "DATE"
    elif ftype == "TIME":
        sql_type = "TIME"
    elif ftype == "TIMESTAMP":
        sql_type = "DATETIME"
    elif ftype in ("VARCHAR", "CSTRING"):
        sql_type = f"VARCHAR({length})" if 0 < length <= MYSQL_MAX_VARCHAR else "TEXT"
    elif ftype == "CHAR":
        sql_type = f"CHAR({length})" if 0 < length <= 255 else "TEXT"
    elif ftype == "BLOB":
        sql_type = "LONGTEXT" if col.get("sub_type") == 1 else "LONGBLOB"
    else:
        # Unknown Firebird type code -> safest lossless fallback
        sql_type = "LONGTEXT"

    return f"`{col['name']}` {sql_type}{not_null}"
