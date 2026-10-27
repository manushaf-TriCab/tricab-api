"""Command line: build and status. Exit codes: 0 ok, 1 other, 2 usage or configuration, 3 required file or column missing."""

from __future__ import annotations

import argparse
import sqlite3
import sys

from . import config
from .build import COLS, run_build
from .loader import SourceError, load


def _parser():
    ap = argparse.ArgumentParser(prog="cable_routes")
    sub = ap.add_subparsers(dest="cmd", required=True)
    b = sub.add_parser("build", help="rebuild the store from the D365 extract")
    b.add_argument("--source", help=f"extract folder (else {config.ENV_SOURCE})")
    b.add_argument("--db", help=f"store path (else {config.ENV_DB}, else {config.DEFAULT_DB})")
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
        log = (lambda m: None) if args.quiet else (lambda m: print(m, flush=True))
        log(f"reading {source.name}")
        src = load(source)
        run = run_build(src, source.name, db, log)
    except config.ConfigError as e:
        print(f"cable_routes: {e}", file=sys.stderr)
        return 2
    except SourceError as e:
        print(f"cable_routes: {e}", file=sys.stderr)
        return 3
    except Exception as e:  # noqa: BLE001 - anything else is exit 1 with the old store untouched
        print(f"cable_routes: build failed: {type(e).__name__}: {e}", file=sys.stderr)
        return 1
    for k, v in zip(COLS["cr_run"], run):
        print(f"{k}: {v}")
    return 0


def _status(db) -> int:
    if not db.is_file():
        print(f"cable_routes: no store at {db.name}; run build first", file=sys.stderr)
        return 2
    con = sqlite3.connect(f"file:{db}?mode=ro", uri=True)
    try:
        cur = con.execute("SELECT * FROM cr_run")
        row = cur.fetchone()
        if row is None:
            print("cable_routes: store has no run record", file=sys.stderr)
            return 1
        for col, v in zip((d[0] for d in cur.description), row):
            print(f"{col}: {v}")
    finally:
        con.close()
    return 0
