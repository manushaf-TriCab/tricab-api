"""cbsync: keep an archive of CableBuilder designs in Blob Storage.

    python -m cbsync status
    python -m cbsync seed --raw ../../planning/cablebuilder/raw
    python -m cbsync pull KL39X-CAXN/3C2.5BK [--no-recurse]
    python -m cbsync feed --since 2026-10-19            (Dynamics only, no CableBuilder)
    python -m cbsync nightly [--rotate-pages 70]
    python -m cbsync rotate --pages 5
    python -m cbsync lookups

--store (or CBSYNC_STORE) is a local folder, or blob:<container> for Azure
with BLOB_CONNECTION_STRING or --account-url. CableBuilder commands need
CB_USER and CB_PASSWORD (or a browser session in CB_COOKIE).
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from datetime import datetime, timezone
from pathlib import Path

from . import changefeed, sync
from .archive import Archive, utcnow
from .cablebuilder import CableBuilder
from .store import open_store


LOCK_HOURS = 6  # a crashed run's lock expires; a nightly run takes minutes


def _date(s: str) -> datetime:
    return datetime.fromisoformat(s).replace(tzinfo=timezone.utc)


def _age_hours(stamp: str) -> float:
    t = datetime.strptime(stamp, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    return (datetime.now(timezone.utc) - t).total_seconds() / 3600


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser(prog="cbsync", description=__doc__,
                                 formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--store", default=os.environ.get("CBSYNC_STORE", "archive"))
    ap.add_argument("--account-url", default=os.environ.get("CBSYNC_ACCOUNT_URL"))
    ap.add_argument("--pause", type=float, default=0.4,
                    help="seconds between CableBuilder requests (shared production server)")
    sub = ap.add_subparsers(dest="command", required=True)

    sub.add_parser("status")
    p = sub.add_parser("seed")
    p.add_argument("--raw", required=True, type=Path, help="the first pull's raw/ folder")
    p = sub.add_parser("pull")
    p.add_argument("codes", nargs="*")
    p.add_argument("--codes-file", type=Path)
    p.add_argument("--alt", default=None)
    p.add_argument("--no-recurse", action="store_true")
    p = sub.add_parser("feed")
    p.add_argument("--since", type=_date, required=True)
    p.add_argument("--until", type=_date)
    p = sub.add_parser("nightly")
    p.add_argument("--since", type=_date)
    p.add_argument("--rotate-pages", type=int, default=0)
    p.add_argument("--run-key", help="e.g. the local date: skip if a run with this key "
                   "already succeeded (the WebJob calls this every hour)")
    p = sub.add_parser("rotate")
    p.add_argument("--pages", type=int, required=True)
    sub.add_parser("lookups")
    args = ap.parse_args(argv)

    store = open_store(args.store, args.account_url)
    archive = Archive(store)
    started, t0 = utcnow(), time.monotonic()
    summary: dict = {"command": args.command, "started": started}

    if args.command == "status":
        kinds: dict[str, int] = {}
        for e in archive.designs.values():
            kinds[e.kind] = kinds.get(e.kind, 0) + 1
        print(json.dumps({
            "designs": len(archive.designs), "distinct_codes": len(archive.codes()),
            "by_kind": kinds,
            "with_history": sum(1 for e in archive.designs.values() if e.history),
            "lookups": len(archive.lookups),
            "changefeed": archive.get_state("changefeed"),
            "rotate": archive.get_state("rotate"),
        }, indent=2))
        return 0

    if args.command == "feed":
        changes, newest, n = changefeed.changes_since(args.since, args.until)
        missing = [c for c in changes if c.code not in archive.codes()]
        print(json.dumps({"messages": n, "items": len(changes), "newest": newest,
                          "not_in_archive": len(missing),
                          "sample": [c.__dict__ for c in changes[:10]]}, indent=2))
        return 0

    if args.command == "nightly" and args.run_key:
        done = archive.get_state("nightly")
        if done.get("last_key") == args.run_key:
            print(f"nightly {args.run_key} already done at {done.get('finished')}")
            return 0
        lock = archive.get_state("lock")
        if lock.get("started") and _age_hours(lock["started"]) < LOCK_HOURS:
            print(f"another run holds the lock since {lock['started']} ({lock.get('key')})")
            return 0
        archive.put_state("lock", {"started": started, "key": args.run_key})

    if args.command == "seed":
        summary.update(sync.seed(archive, args.raw))
    else:
        cb = CableBuilder(pause=args.pause)
        print(f"CableBuilder session OK ({cb.check()} lookup tables under 'Tables')")
        if args.command == "pull":
            codes = list(args.codes)
            if args.codes_file:
                codes += [ln.strip() for ln in args.codes_file.read_text(encoding="utf-8").splitlines()
                          if ln.strip()]
            if not codes:
                ap.error("pull needs codes or --codes-file")
            # Named on the command line: ask CableBuilder even if it failed recently.
            summary.update(sync.pull(archive, cb, [(c, args.alt, None) for c in codes],
                                     recurse=not args.no_recurse, skip_unknown=False))
        elif args.command == "nightly":
            summary.update(sync.nightly(archive, cb, args.since, args.rotate_pages))
        elif args.command == "rotate":
            summary.update(sync.rotate(archive, cb, args.pages))
        elif args.command == "lookups":
            summary.update(sync.lookups(archive, cb))

    summary["seconds"] = round(time.monotonic() - t0, 1)
    run = archive.write_run(summary)
    if args.command == "nightly" and args.run_key:
        archive.put_state("nightly", {"last_key": args.run_key, "finished": utcnow(), "run": run})
        archive.put_state("lock", {})
    short = {k: v for k, v in summary.items() if k not in ("changed", "unidentified")}
    print(json.dumps(short, indent=2, default=str)[:4000])
    print(f"run record: {run}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
