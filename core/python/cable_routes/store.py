"""Write the SQLite store and swap it in atomically (contract 3.3)."""

from __future__ import annotations

import os
import sqlite3
from pathlib import Path

SCHEMA = Path(__file__).resolve().parent / "schema.sql"


def write_store(path: Path, tables: dict) -> None:
    """tables: name -> (columns tuple, rows). Writes and checks a new store file at path (no swap).

    The caller owns the file: it is removed on failure, and nothing else is touched.
    """
    path = Path(path)
    path.unlink(missing_ok=True)
    try:
        con = sqlite3.connect(path)
        try:
            con.execute("PRAGMA journal_mode = DELETE")
            con.executescript(SCHEMA.read_text(encoding="utf-8"))
            for name, (cols, rows) in tables.items():
                marks = ",".join("?" * len(cols))
                con.executemany(f"INSERT INTO {name} ({','.join(cols)}) VALUES ({marks})", rows)
            con.commit()
            ok = con.execute("PRAGMA integrity_check").fetchall()
            if ok != [("ok",)]:
                raise RuntimeError(f"integrity_check failed: {ok[:3]}")
            bad = con.execute("PRAGMA foreign_key_check").fetchall()
            if bad:
                raise RuntimeError(f"foreign_key_check failed: {bad[:3]}")
        finally:
            con.close()
    except BaseException:
        path.unlink(missing_ok=True)
        raise


def write_atomic(db_path: Path, tables: dict) -> None:
    """Builds <db>.tmp-<pid>, checks it, then os.replace.

    On any failure the temp file is removed and the old store is left alone.
    """
    db_path = Path(db_path)
    db_path.parent.mkdir(parents=True, exist_ok=True)
    tmp = db_path.with_name(f"{db_path.name}.tmp-{os.getpid()}")
    try:
        write_store(tmp, tables)
        os.replace(tmp, db_path)
    except BaseException:
        tmp.unlink(missing_ok=True)
        raise
