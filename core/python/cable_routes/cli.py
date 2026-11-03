"""Command line: build and status. Exit codes: 0 ok, 1 other, 2 usage or configuration, 3 required file or column missing (refresh: see refresh.py)."""

from __future__ import annotations

import argparse
import sqlite3
import sys
from pathlib import Path

from . import config
from . import refresh as refresh_mod
from .build import COLS, run_build
from .loader import SourceError, load


def _parser():
    ap = argparse.ArgumentParser(prog="cable_routes")
    sub = ap.add_subparsers(dest="cmd", required=True)
    b = sub.add_parser("build", help="rebuild the store from the D365 extract")
    b.add_argument("--source", help=f"extract folder (else {config.ENV_SOURCE})")
    b.add_argument("--db", help=f"store path (else {config.ENV_DB}, else {config.DEFAULT_DB})")
    b.add_argument("--quiet", action="store_true")
    r = sub.add_parser("refresh", help="extract from Dynamics, rebuild, validate and swap the store in")
    r.add_argument("--source", help="build from this extract folder instead of reading Dynamics (tests, dry runs)")
    r.add_argument("--db")
    r.add_argument("--if-enabled", action="store_true", help=f"exit 3 unless {refresh_mod.ENV_ENABLED}=true")
    r.add_argument("--allow-shrink", action="store_true", help="skip the row-count collapse guard (people only)")
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
        if args.cmd == "refresh":
            src = Path(args.source).expanduser().resolve() if args.source else None
            if src is not None and not src.is_dir():
                raise config.ConfigError(f"source folder does not exist: {src.name!r}")
            return refresh_mod.run(db, src, args.if_enabled, args.allow_shrink)
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
