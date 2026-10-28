"""Command line: build and status. Exit codes: 0 ok, 1 other, 2 usage or configuration, 3 required file, column or R-86 format."""

from __future__ import annotations

import argparse
import sqlite3
import sys
from datetime import date

from cable_routes.loader import SourceError

from . import config
from .run import run_build


def _parser():
    ap = argparse.ArgumentParser(prog="conductor_schedule")
    sub = ap.add_subparsers(dest="cmd", required=True)
    b = sub.add_parser("build", help="rebuild the store from the source folder")
    b.add_argument("--source", help=f"source folder (else {config.ENV_SOURCE})")
    b.add_argument("--db", help=f"store path (else {config.ENV_DB}, else {config.DEFAULT_DB})")
    b.add_argument("--settings", help=f"settings override file (else {config.ENV_SETTINGS}, else the seed only)")
    b.add_argument("--today", help="fix 'today' (YYYY-MM-DD); default the local date")
    b.add_argument("--quiet", action="store_true")
    s = sub.add_parser("status", help="print the run record of the store")
    s.add_argument("--db")
    return ap


def main(argv=None) -> int:
    try:
        args = _parser().parse_args(argv)
    except SystemExit as e:
        return 2 if e.code else 0
    try:
        db = config.resolve_db(args.db)
        if args.cmd == "status":
            return _status(db)
        source = config.resolve_source(args.source)
        override = config.resolve_settings(args.settings)
        try:
            today = date.fromisoformat(args.today) if args.today else date.today()
        except ValueError:
            raise config.ConfigError(f"--today must be YYYY-MM-DD, got {args.today!r}") from None
        log = (lambda m: None) if args.quiet else (lambda m: print(m, flush=True))
        log(f"reading {source.name}")
        run = run_build(source, db, override, today, log)
    except config.ConfigError as e:
        print(f"conductor_schedule: {e}", file=sys.stderr)
        return 2
    except SourceError as e:
        print(f"conductor_schedule: {e}", file=sys.stderr)
        return 3
    except Exception as e:  # noqa: BLE001 - anything else is exit 1 with the old store untouched
        print(f"conductor_schedule: build failed: {type(e).__name__}: {e}", file=sys.stderr)
        return 1
    for k, v in run.items():
        print(f"{k}: {v}")
    return 0


def _status(db) -> int:
    if not db.is_file():
        print(f"conductor_schedule: no store at {db.name}; run build first", file=sys.stderr)
        return 2
    con = sqlite3.connect(f"file:{db}?mode=ro", uri=True)
    try:
        cur = con.execute("SELECT * FROM cs_run")
        row = cur.fetchone()
        if row is None:
            print("conductor_schedule: store has no run record", file=sys.stderr)
            return 1
        for col, v in zip((d[0] for d in cur.description), row):
            print(f"{col}: {v}")
    finally:
        con.close()
    return 0
