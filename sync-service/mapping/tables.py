"""
Whitelist of Accurate (GDB) tables to mirror into MySQL `stockwise` DB, prefixed
`accurate_` to avoid any collision with Stockwise's own tables (MySQL on this
machine has lower_case_table_names=1 — table names are case-insensitive).

Scope decided 2026-09-11 (user-approved): only tables relevant to Stockwise,
not a full 234-table mirror. See docs/ACCURATE_MAPPING.md for the reasoning
behind each table.
"""

MIRROR_TABLES = [
    # Master data
    "ITEM",
    "WAREHS",
    "ITEMCATEGORY",
    "PERSONDATA",
    "CUSTTYPE",
    # Stock balances / movements
    "ITEMBALANCE",
    "ITEMBALANCEWAREHOUSE",
    "ITEMADJ",
    "ITADJDET",
    "ITEMHIST",
    # Purchasing
    "PO",
    "PODET",
    "REQUISITION",
    "REQUISITIONDET",
    "APINV",
    "APITMDET",
    # Sales
    "SO",
    "SODET",
    "ARINV",
    "ARINVDET",
]


def mysql_table_name(accurate_table_name: str) -> str:
    return f"accurate_{accurate_table_name.lower()}"
