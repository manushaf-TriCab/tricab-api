"""Copy the conductor schedule source files into one flat folder (contract 2.3).

    python core/python/tools/make_schedule_source.py DEST [--dynamics DIR] [--snapshot DIR] [--inventory DIR]
                                                          [--r86 DIR] [--force]

Flag, then environment (CS_SRC_DYNAMICS, CS_SRC_SNAPSHOT, CS_SRC_INVENTORY, CS_SRC_R86), then default. Exit 0 ok, 2 bad
folder, 3 source file or required column missing (the engine's own check, run on DEST). Never writes outside DEST.
"""

from __future__ import annotations

import argparse
import os
import shutil
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from cable_routes.loader import SourceError  # noqa: E402
from conductor_schedule import r86, source  # noqa: E402

# Defaults assume the TriCab folder layout: <TriCab>/platform/<this repo> beside <TriCab>/planning.
TRICAB_ROOT = Path(__file__).resolve().parents[5]
DEFAULT_DYNAMICS = TRICAB_ROOT / "planning" / "dynamics"
DEFAULT_INVENTORY = TRICAB_ROOT / "planning" / "conductor" / "inventory-2026-10-26"
DEFAULT_R86 = TRICAB_ROOT / "platform" / "r86"
SNAPSHOT_FILES = ("production-order-headers-raw.csv", "production-bom-lines-raw.csv", "production-route-operations-raw.csv",
                  "released-products.csv", "manufactured-items.csv", "purchase-order-lines-all.csv",
                  "production-order-headers.csv", "production-picking-list-lines.csv")
DYNAMICS_FILES = ("production-route-jobs-raw.csv", "machine-operation-parameters-full.csv", "work-calendars.csv",
                  "work-calendar-time-intervals.csv")


def _pick(flag, env, default):
    return Path(flag or os.environ.get(env) or default)


def main(argv=None) -> int:
    ap = argparse.ArgumentParser(prog="make_schedule_source")
    ap.add_argument("dest")
    ap.add_argument("--dynamics")
    ap.add_argument("--snapshot")
    ap.add_argument("--inventory")
    ap.add_argument("--r86")
    ap.add_argument("--force", action="store_true", help="overwrite existing files in DEST")
    try:
        a = ap.parse_args(argv)
    except SystemExit as e:
        return 2 if e.code else 0
    dyn = _pick(a.dynamics, "CS_SRC_DYNAMICS", DEFAULT_DYNAMICS)
    snap = Path(a.snapshot or os.environ.get("CS_SRC_SNAPSHOT") or dyn / "snapshot-2026-10-28")
    inv = _pick(a.inventory, "CS_SRC_INVENTORY", DEFAULT_INVENTORY)
    r86_dir = _pick(a.r86, "CS_SRC_R86", DEFAULT_R86)
    for label, d in (("dynamics", dyn), ("snapshot", snap), ("inventory", inv), ("r86", r86_dir)):
        if not d.is_dir():
            print(f"make_schedule_source: {label} folder not found: {d}", file=sys.stderr)
            return 2
    dest = Path(a.dest)
    if dest.exists() and not dest.is_dir():
        print(f"make_schedule_source: DEST is not a folder: {dest}", file=sys.stderr)
        return 2

    plan = [(snap / n, dest / n) for n in SNAPSHOT_FILES] + [(dyn / n, dest / n) for n in DYNAMICS_FILES]
    plan += [(inv / "full-inventory.csv", dest / source.INVENTORY), (inv / "summary.json", dest / source.INVENTORY_META)]
    r86_files = sorted(p for p in r86_dir.iterdir() if p.is_file() and not p.name.startswith("."))
    plan += [(p, dest / source.R86_DIR / p.name) for p in r86_files]
    missing = [src for src, _ in plan if not src.is_file()]
    if missing:
        print("make_schedule_source: missing source file " + ", ".join(str(m) for m in missing), file=sys.stderr)
        return 3

    try:
        (dest / source.R86_DIR).mkdir(parents=True, exist_ok=True)
        for src, dst in plan:
            if dst.exists() and not a.force:
                print(f"kept     {dst.relative_to(dest)} (exists; --force to overwrite)")
                continue
            shutil.copy2(src, dst)
            print(f"copied   {dst.relative_to(dest)}  {dst.stat().st_size:,} bytes")
    except OSError as e:
        print(f"make_schedule_source: {e}", file=sys.stderr)
        return 2
    try:
        source.check(dest)
        found = r86.discover(dest / source.R86_DIR)
    except SourceError as e:
        print(f"make_schedule_source: {e}", file=sys.stderr)
        return 3
    for doc, reps in found.items():
        print(f"r86 {doc}: {reps[0].name} printed {reps[0].printed}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
