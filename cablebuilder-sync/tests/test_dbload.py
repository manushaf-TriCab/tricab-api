from __future__ import annotations

import re
from pathlib import Path

from cbsync.dbload import TABLES

DDL = (Path(__file__).resolve().parents[2] / "database" / "scripts" / "development"
       / "create-cablebuilder-schema.sql").read_text(encoding="utf-8")


def table_columns(table: str) -> set[str]:
    block = re.search(rf"CREATE TABLE \[cablebuilder\]\.\[{table}\] \((.*?)\n    \);", DDL, re.S)
    assert block, table
    return set(re.findall(r"^\s+\[(\w+)\]\s+[A-Z]", block.group(1), re.M))


def test_every_column_the_loader_writes_exists_in_the_schema():
    for table, cols in TABLES.items():
        missing = {c for c, _ in cols} - table_columns(table)
        assert not missing, f"{table}: {sorted(missing)}"


def test_the_loader_writes_every_schema_column_it_should():
    # Columns the database fills itself, or the loader sets with UPDATEs.
    own = {"design_version_id", "is_current", "superseded_at", "loaded_at"}
    for table, cols in TABLES.items():
        unwritten = table_columns(table) - {c for c, _ in cols} - own
        assert not unwritten, f"{table}: {sorted(unwritten)}"
