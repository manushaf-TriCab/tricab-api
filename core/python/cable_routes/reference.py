"""Poster reference data (seed CSVs) and the machine table (contract 4.4 rule 11)."""

from __future__ import annotations

import csv
import re
from pathlib import Path

from .parse import ALIAS

SEED = Path(__file__).resolve().parent / "seed"
_CODE = re.compile(r"^[A-Z][A-Z0-9]{1,2}$")


def read_seed(name: str) -> list:
    with open(SEED / name, newline="", encoding="utf-8-sig") as f:
        return list(csv.DictReader(f))


def _f(s):
    try:
        return float(s)
    except (TypeError, ValueError):
        return None


def _i(s):
    v = _f(s)
    return int(v) if v is not None else None


def _n(s):
    s = (s or "").strip()
    return s or None


def family_codes(codes_text: str) -> list:
    """Codes named in a family's codes cell; words such as '(purchased)' are not codes."""
    out = []
    for tok in (codes_text or "").split():
        if _CODE.match(tok) and tok not in out:
            out.append(tok)
    return out


def build_machines(seen: set, made_by_machine: dict, finished_by_machine: dict, code_of: dict):
    """Rows for cr_machine plus {machine: d365_status}.

    seen: D365 resource ids found in routes. made_by_machine: machine -> set of made items.
    finished_by_machine: machine -> {finished item: steps}. code_of: finished item -> code.
    """
    rows, status = [], {}
    claimed = set()
    for p in read_seed("poster-machines.csv"):
        poster = p["machine"].strip()
        d365 = ALIAS.get(poster, poster)
        retired = p["poster_status"].strip() == "not_in_use"
        if retired:
            st, machine = "retired", poster
            d365_id = d365 if d365 in seen else None
        elif poster in ALIAS and d365 in seen:
            st, machine, d365_id = "alias", d365, d365
        elif poster in seen:
            st, machine, d365_id = "routed", poster, poster
        else:
            st, machine, d365_id = "no_routings", poster, None
        if d365_id:
            claimed.add(d365_id)
        rows.append(_row(machine, poster, d365_id, 1, st, 1 if retired else 0, p,
                         made_by_machine, finished_by_machine, code_of))
        status[machine] = st
    for m in sorted(seen - claimed):
        rows.append(_row(m, None, m, 0, "not_on_poster", 0, {}, made_by_machine, finished_by_machine, code_of))
        status[m] = "not_on_poster"
    return rows, status


def _row(machine, poster, d365_id, in_poster, st, hidden, p, made_by_machine, finished_by_machine, code_of):
    fin = finished_by_machine.get(machine, {})
    return (
        machine, poster, d365_id, in_poster, st, hidden,
        _n(p.get("site")), _i(p.get("stage_no")), _n(p.get("stage")), _n(p.get("poster_status")), _n(p.get("brand")),
        _n(p.get("machine_type")), _n(p.get("input_limit")), _n(p.get("output_capability")), _n(p.get("pay_off")),
        _n(p.get("take_up")), _n(p.get("speed_text")), _f(p.get("speed_m_per_min")), _n(p.get("other_notes")),
        _n(p.get("legibility")),
        len(made_by_machine.get(machine, ())), len(fin), len({code_of[f] for f in fin}),
    )


MACHINE_COLUMNS = (
    "machine", "poster_name", "d365_id", "in_poster", "d365_status", "hidden", "site", "stage_no", "stage",
    "poster_status", "brand", "machine_type", "input_limit", "output_capability", "pay_off", "take_up", "speed_text",
    "speed_m_per_min", "other_notes", "legibility", "made_items", "finished_items", "codes")


def poster_tables():
    """Rows for the pure-seed tables: flow edges, families, family codes, ranges, machine limits."""
    flow = [(i, r["from_stage"], r["from_machines"], r["to_stage"], r["to_machines"], r["flow"], r["line_colour"])
            for i, r in enumerate(read_seed("poster-flow-edges.csv"), 1)]
    fam_rows = read_seed("poster-product-families.csv")
    fams = [(i, r["family"], _n(r["line_colour"]), _n(r["codes"]), _n(r["poster_route"]),
             _n(r["expected_ops_after_insulation"]), _n(r["notes"])) for i, r in enumerate(fam_rows, 1)]
    fam_codes = [(r["family"], c) for r in fam_rows for c in family_codes(r["codes"])]
    ranges, seen = [], set()
    for r in read_seed("poster-product-ranges.csv"):
        key = (r["range"], r["code"])
        if key in seen:  # the poster lists LP twice in Building Wire with identical rows
            continue
        seen.add(key)
        ranges.append((len(ranges) + 1, r["range"], r["code"], _n(r["description"]), _n(r["voltage"]),
                       _n(r["insulation_jacket"]), _n(r["conductor"]), _f(r["max_temp_c"]), _n(r["legibility"])))
    limits = [(r["machine"], _f(r["min_mm"]), _f(r["max_mm"]), _n(r["note"])) for r in read_seed("machine-limits.csv")]
    return flow, fams, fam_codes, ranges, limits
