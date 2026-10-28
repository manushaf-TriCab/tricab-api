"""The 15 map machines: seed/conductor-map.csv joined to the poster spec in cable_routes (contract 3.3)."""

from __future__ import annotations

import csv
from pathlib import Path

from cable_routes.reference import read_seed as read_cable_seed

SEED = Path(__file__).resolve().parent / "seed"
STAGES = {1: "Rod breaking", 2: "Drawing & tinning", 3: "Bunching", 4: "Stranding & mica taping"}
SPEC = ("poster_status", "brand", "machine_type", "input_limit", "output_capability", "pay_off", "take_up",
        "speed_text")


def read_map() -> list:
    """The seed rows in map order (seq 1..15)."""
    with open(SEED / "conductor-map.csv", newline="", encoding="utf-8-sig") as f:
        rows = list(csv.DictReader(f))
    return sorted(rows, key=lambda r: int(r["seq"]))


def on_map() -> dict:
    """D365 resource -> poster name for the map machines that Dynamics knows (RB1 -> RB2, TL1 -> TN1)."""
    return {r["d365_id"].strip(): r["machine"].strip() for r in read_map() if r["d365_id"].strip()}


def _n(s):
    s = (s or "").strip()
    return s or None


def machine_rows(stats: dict, items: dict) -> list:
    """cd_machine rows. stats: machine -> [orders_total, orders_cancelled, orders_52w]; items: machine -> cd_item count."""
    poster = {}
    for p in read_cable_seed("poster-machines.csv"):  # the file repeats a block: first row per machine wins
        poster.setdefault(p["machine"].strip(), p)
    rows = []
    for r in read_map():
        m, stage_no = r["machine"].strip(), int(r["stage_no"])
        p = poster.get(m, {})
        total, cancelled, w52 = stats.get(m, (0, 0, 0))
        rows.append((m, _n(r["d365_id"]), int(r["seq"]), stage_no, STAGES[stage_no], *(_n(p.get(c)) for c in SPEC),
                     _n(r["note"]), items.get(m, 0), total, cancelled, w52))
    return rows


MACHINE_COLUMNS = ("machine", "d365_id", "seq", "stage_no", "stage", *SPEC, "note", "items", "orders_total",
                   "orders_cancelled", "orders_52w")
