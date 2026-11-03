"""Rebuild the store from a fresh extract, check it, and swap it in (plan item 3.2, decision D1).

Order: lock, extract (or read a given folder), build into <db>.refresh-<pid>, validate against the live store, keep the
live file as <db>.prev, then one os.replace. Readers open the store read-only per request, so they see the old file or
the new one, never half of either. Any failure removes the temp files and leaves the live store alone.

Exit codes: 0 swapped, 1 failure, 2 refused (new store looks collapsed), 3 switched off, 4 Dynamics unreachable or
refused, 5 another refresh holds the lock.
"""

from __future__ import annotations

import fcntl
import os
import shutil
import sqlite3
import time
from pathlib import Path

from . import config
from .build import build_tables
from .d365 import Client, D365Error, extract
from .loader import SourceError, load
from .store import write_store

ENV_ENABLED = "CABLE_ROUTES_REFRESH_ENABLED"
ENV_RATIO = "CABLE_ROUTES_REFRESH_MIN_RATIO"
DEFAULT_RATIO = 0.8
# Tables whose row count must not collapse, and (table, minimum when there is no old store).
GUARDED = ("cr_finished", "cr_item", "cr_operation", "cr_input", "cr_need")
REQUIRED_TABLES = GUARDED + ("cr_run", "cr_machine", "cr_check")


class Refused(Exception):
    """The new store failed validation; the live store was kept."""


def enabled(environ=None) -> bool:
    return ((environ if environ is not None else os.environ).get(ENV_ENABLED) or "").strip().lower() == "true"


def ratio(environ=None) -> float:
    raw = ((environ if environ is not None else os.environ).get(ENV_RATIO) or "").strip()
    try:
        v = float(raw)
    except ValueError:
        return DEFAULT_RATIO
    return v if 0 < v <= 1 else DEFAULT_RATIO


def table_counts(path: Path) -> dict[str, int]:
    con = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
    try:
        names = {r[0] for r in con.execute("SELECT name FROM sqlite_master WHERE type = 'table'")}
        missing = [t for t in REQUIRED_TABLES if t not in names]
        if missing:
            raise Refused(f"new store is missing table{'s' if len(missing) > 1 else ''} {', '.join(missing)}")
        counts = {t: con.execute(f"SELECT COUNT(*) FROM {t}").fetchone()[0] for t in REQUIRED_TABLES}
        if counts["cr_run"] != 1:
            raise Refused(f"new store has {counts['cr_run']} run records, expected 1")
        return counts
    finally:
        con.close()


def validate(new: Path, live: Path, min_ratio: float, allow_shrink: bool = False) -> tuple[dict, dict | None]:
    """Raise Refused unless the new store has its schema and has not collapsed against the live one."""
    try:
        got = table_counts(new)
    except sqlite3.DatabaseError as e:
        raise Refused(f"new store is not a readable database: {e}") from e
    for t in ("cr_finished", "cr_item", "cr_operation"):
        if got[t] == 0:
            raise Refused(f"new store has no rows in {t}")
    old = None
    if live.is_file():
        try:
            old = table_counts(live)
        except (sqlite3.DatabaseError, Refused):
            old = None  # a broken live store is no reason to refuse a good new one
    if old and not allow_shrink:
        for t in GUARDED:
            if old[t] and got[t] < old[t] * min_ratio:
                raise Refused(f"{t} fell from {old[t]} to {got[t]} rows (under {int(min_ratio * 100)}% of the live "
                              f"store); keeping the live store")
    return got, old


def refresh(db: Path, source: Path | None = None, client: Client | None = None, allow_shrink: bool = False,
            min_ratio: float = DEFAULT_RATIO, log=print) -> dict[str, int]:
    """Returns the new store's table counts. Raises Refused, D365Error, SourceError, or anything else on failure."""
    db = Path(db)
    db.parent.mkdir(parents=True, exist_ok=True)
    lock = open(db.with_name(db.name + ".lock"), "w")
    try:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except OSError:
            raise LockHeld() from None
        pid = os.getpid()
        work = db.with_name(f"{db.name}.refresh-{pid}")
        tmp = db.with_name(f"{db.name}.new-{pid}")
        shutil.rmtree(work, ignore_errors=True)
        started = time.monotonic()
        try:
            if source is None:
                folder = work
                counts = extract(folder, client, log)
                if not counts["production-order-headers-raw.csv"]:
                    raise Refused("Dynamics returned no production orders")
                source_name = "dynamics"
            else:
                folder, source_name = source, source.name
            src = load(folder)
            tables = build_tables(src, source_name, log)
            write_store(tmp, tables)
            got, old = validate(tmp, db, min_ratio, allow_shrink)
            if db.is_file():
                shutil.copy2(db, db.with_name(db.name + ".prev"))
            os.replace(tmp, db)
        finally:
            tmp.unlink(missing_ok=True)
            shutil.rmtree(work, ignore_errors=True)
        run = dict(zip((c for c in tables["cr_run"][0]), tables["cr_run"][1][0]))
        log(f"refresh ok in {time.monotonic() - started:.0f}s: data as of {run['data_as_of']}, "
            f"{run['finished_total']} finished ({run['finished_traced']} traced, {run['trace_to_rod']} to rod), "
            f"{run['made_items']} made items")
        for t in GUARDED:
            log(f"  {t}: {got[t]}" + (f" (was {old[t]})" if old else " (no previous store)"))
        return got
    finally:
        lock.close()


class LockHeld(Exception):
    """Another refresh holds the lock."""


def run(db: Path, source: Path | None, if_enabled: bool, allow_shrink: bool, log=print, environ=None,
        client: Client | None = None) -> int:
    if if_enabled and not enabled(environ):
        return 3
    try:
        refresh(db, source, client, allow_shrink, ratio(environ), log)
    except LockHeld:
        log("cable_routes refresh: another refresh is running")
        return 5
    except Refused as e:
        log(f"cable_routes refresh REFUSED: {e}")
        return 2
    except D365Error as e:
        log(f"cable_routes refresh: {e}")
        return 4
    except SourceError as e:
        log(f"cable_routes refresh: {e}")
        return 1
    except Exception as e:  # noqa: BLE001 - the live store is untouched
        log(f"cable_routes refresh failed: {type(e).__name__}: {e}")
        return 1
    return 0
