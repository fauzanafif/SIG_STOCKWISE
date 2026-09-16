"""Read-only diagnostic: figure out why some ITEM.ITEMDESCRIPTION values come
through as the Unicode replacement character (U+FFFD / a black question mark).

Checks:
  1. The real declared character set of ITEM.ITEMDESCRIPTION (RDB$FIELDS).
  2. Fetches a known-bad row with charset=UTF8 (current sync-service setting).
  3. Fetches the same row with charset=WIN1252 and charset=NONE for comparison.

Never writes anything — SELECT only.
"""
import os
import sys

import fdb

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from config.settings import FirebirdSettings

TEST_ITEMNO = "AUT.0182"

_api_loaded = False


def connect(charset):
    global _api_loaded
    if not _api_loaded:
        fdb.load_api(fb_library_name=FirebirdSettings.client_lib)
        _api_loaded = True
    return fdb.connect(
        dsn=FirebirdSettings.dsn(),
        user=FirebirdSettings.user,
        password=FirebirdSettings.password,
        charset=charset,
    )


def main():
    # 1. Real declared charset of ITEM.ITEMDESCRIPTION
    con = connect("UTF8")
    cur = con.cursor()
    cur.execute(
        """
        SELECT cs.RDB$CHARACTER_SET_NAME
        FROM RDB$RELATION_FIELDS rf
        JOIN RDB$FIELDS f ON f.RDB$FIELD_NAME = rf.RDB$FIELD_SOURCE
        LEFT JOIN RDB$CHARACTER_SETS cs ON cs.RDB$CHARACTER_SET_ID = f.RDB$CHARACTER_SET_ID
        WHERE rf.RDB$RELATION_NAME = 'ITEM' AND TRIM(rf.RDB$FIELD_NAME) = 'ITEMDESCRIPTION'
        """
    )
    row = cur.fetchone()
    print("ITEM.ITEMDESCRIPTION declared charset:", row[0].strip() if row and row[0] else "(none / not found)")

    print("\nDatabase-level default charset:")
    cur.execute("SELECT RDB$CHARACTER_SET_NAME FROM RDB$DATABASE")
    dbrow = cur.fetchone()
    print(" ->", dbrow[0].strip() if dbrow and dbrow[0] else "(unknown)")
    con.close()

    # 2 & 3. Fetch the same row under different connection charsets.
    for charset in ["UTF8", "WIN1252", "NONE", "DOS850"]:
        try:
            con = connect(charset)
            cur = con.cursor()
            cur.execute(
                "SELECT ITEMNO, ITEMDESCRIPTION FROM ITEM WHERE ITEMNO = ?",
                (TEST_ITEMNO,),
            )
            row = cur.fetchone()
            con.close()
            if row is None:
                print(f"\ncharset={charset}: row not found")
                continue
            desc = row[1]
            print(f"\ncharset={charset}: repr={desc!r}")
            print(f"charset={charset}: bytes={desc.encode('utf-8', errors='replace')!r}")
        except Exception as exc:
            print(f"\ncharset={charset}: ERROR {exc}")


if __name__ == "__main__":
    main()
