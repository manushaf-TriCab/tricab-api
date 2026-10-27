"""Orchestrates a build: model, explosion, trace, machines, checks, run record."""

from __future__ import annotations

import collections
from datetime import datetime, timezone
from pathlib import Path

from . import SCHEMA_VERSION, __version__
from . import checks, reference
from .explode import explode
from .loader import Source
from .model import build_model, _positive
from .parse import parse_item
from .store import write_atomic
from .trace import classify

COLS = {
    "cr_item": ("item", "pool", "diameter_mm", "kg_per_m", "unit", "order_no", "order_status", "order_date",
                "real_orders", "live_orders"),
    "cr_operation": ("item", "seq", "operation_number", "operation", "machine"),
    "cr_input": ("item", "line", "input_item", "qty_per_unit", "unit", "parallel_ends", "kind"),
    "cr_alt_machine": ("item", "operation", "machine", "orders"),
    "cr_material": ("item", "name", "unit", "diameter_mm", "conductor"),
    "cr_finished": ("item", "pool", "code", "construction", "colour", "parse_ok", "diameter_mm", "kg_per_m", "unit",
                    "trace_status", "reason", "reason_items", "step_count", "max_depth"),
    "cr_need": ("finished", "item", "unit", "need_per_unit", "depth", "kind", "is_rod"),
    "cr_machine": reference.MACHINE_COLUMNS,
    "cr_machine_limit": ("machine", "min_mm", "max_mm", "note"),
    "cr_machine_finished": ("machine", "finished", "steps"),
    "cr_flow_edge": ("seq", "from_stage", "from_machines", "to_stage", "to_machines", "flow", "line_colour"),
    "cr_family": ("seq", "family", "line_colour", "codes_text", "poster_route", "expected_ops", "notes"),
    "cr_family_code": ("family", "code"),
    "cr_range": ("seq", "range_name", "code", "description", "voltage", "insulation_jacket", "conductor",
                 "max_temp_c", "legibility"),
    "cr_code": ("code", "description", "in_poster", "finished_items", "traced_items"),
    "cr_check": ("kind", "item", "code", "machine", "operation", "detail", "affected", "total"),
    "cr_run": ("run_id", "schema_version", "built_at", "source_name", "data_as_of", "engine_version", "finished_total",
               "finished_traced", "made_items", "trace_to_rod", "trace_to_purchased_conductor", "trace_stops_early",
               "untraceable_no_orders", "untraceable_cutting_only", "parsed_fit", "parsed_other", "cycle_edges_cut",
               "check_rows"),
}
# Insert order: parents before children (foreign keys are checked at the end, but keep the file readable).
ORDER = ("cr_item", "cr_operation", "cr_input", "cr_alt_machine", "cr_material", "cr_finished", "cr_need",
         "cr_machine", "cr_machine_limit", "cr_machine_finished", "cr_flow_edge", "cr_family", "cr_family_code",
         "cr_range", "cr_code", "cr_check", "cr_run")


def build_tables(src: Source, source_name: str, log=lambda m: None) -> dict:
    model = build_model(src)
    items = model.items
    log(f"model: {len(items)} made items, {len(model.finished)} finished items, data as of {model.data_as_of}")

    graph = {i: [(x.input_item, x.qty_per_unit, x.unit) for x in it.inputs] for i, it in items.items()}
    unit_of = {i: it.unit for i, it in items.items()}
    rod_break = {i for i, it in items.items() if it.has_rod_break}

    fin_rows, need_rows, check_inputs = [], [], []
    machine_finished = collections.defaultdict(dict)  # machine -> {finished: steps}
    all_cut = set()
    used = set()
    finished_ops = {}
    code_of = {}
    for f in model.finished:
        p = parse_item(f)
        code_of[f] = p.code
        prod = model.products.get(f, {})
        dia, kg = _positive(prod.get("CsDiameter")), _positive(prod.get("NetProductWeight"))
        unit = prod.get("InventoryUnitSymbol", "")
        pool = model.pools[f]
        head = (f, pool, p.code, p.construction, p.colour, int(p.parse_ok), dia, kg, unit)
        if f not in items:
            reason = model.no_real_reason[f]
            fin_rows.append(head + ("untraceable", reason, None, 0, 0))
            check_inputs.append((f, p.code, "untraceable", reason))
            continue
        tree = explode(f, graph, unit_of, items[f].unit)
        all_cut |= tree.cut
        made = [n for n in tree.order if n in items]
        used.update(made)
        mats = [n for n in tree.order if n not in items]
        status, reason, reason_items = classify(made, mats, rod_break.__contains__, tree.depth)
        steps, per_machine = set(), collections.Counter()
        ops = set()
        rod_mats = set()
        for n in made:
            for _seq, _no, op, machine in items[n].ops:
                steps.add((op, machine))
                ops.add(op)
            for machine in {m for _, _, _, m in items[n].ops}:
                per_machine[machine] += 1
            if n in rod_break:
                rod_mats.update(x.input_item for x in items[n].inputs if x.kind == "material")
        for machine, k in per_machine.items():
            machine_finished[machine][f] = k
        finished_ops[f] = ops & set(checks.ROUTE_OPS)
        fin_rows.append(head + (status, reason, reason_items, len(steps), max(tree.depth[n] for n in made)))
        check_inputs.append((f, p.code, status, reason))
        for n in tree.order:
            kind = "finished" if n == f else ("made" if n in items else "material")
            for u, v in tree.need[n].items():
                need_rows.append((f, n, u, v, tree.depth[n], kind, int(kind == "material" and n in rod_mats)))
    # Only items that sit in some traced tree are stored; made items nothing finished uses are dead data.
    items = {i: it for i, it in items.items() if i in used}
    log(f"explosion: {len(need_rows)} need rows, {len(all_cut)} backward edges cut")

    # Machines
    made_by_machine = collections.defaultdict(set)
    for it in items.values():
        for _seq, _no, _op, m in it.ops:
            made_by_machine[m].add(it.item)
    seen = set(made_by_machine) | {m for (i, _, m) in model.alt if i in items}
    mach_rows, machine_status = reference.build_machines(seen, made_by_machine, machine_finished, code_of)
    flow, fams, fam_codes, ranges, limits = reference.poster_tables()
    mf_rows = [(m, f, k) for m, d in sorted(machine_finished.items()) for f, k in sorted(d.items())]
    log(f"machines: {len(mach_rows)} ({sum(1 for s in machine_status.values() if s == 'not_on_poster')} not on poster)")

    # Checks
    retired = {m for m, s in machine_status.items() if s == "retired"}
    retired |= {r[2] for r in mach_rows if r[4] == "retired" and r[2]}
    made_count = {m: len(v) for m, v in made_by_machine.items()}
    traced = [(f, code_of[f]) for f, _c, st, _r in check_inputs if st != "untraceable"]
    fam_in = [(r[1], reference.family_codes(r[3]), r[5]) for r in fams]
    limit_map = {m: (lo, hi) for m, lo, hi, _ in limits}
    check_list = (checks.family_route_mismatch(traced, finished_ops, fam_in)
                  + checks.machine_rows(machine_status, made_count)
                  + checks.retired_rows(items, retired)
                  + checks.limit_rows(items, limit_map)
                  + checks.status_rows(check_inputs))
    log(f"checks: {len(check_list)} rows")

    # Codes
    desc, in_poster = {}, set()
    for r in ranges:
        in_poster.add(r[2])
        desc.setdefault(r[2], r[3])
    n_fin, n_traced = collections.Counter(), collections.Counter()
    for _f, code, st, _r in check_inputs:
        n_fin[code] += 1
        if st != "untraceable":
            n_traced[code] += 1
    code_rows = [(c, desc.get(c), int(c in in_poster), n_fin[c], n_traced[c]) for c in sorted(set(n_fin) | in_poster)]

    # Materials, items
    used_mats = {x.input_item for it in items.values() for x in it.inputs if x.kind == "material"}
    material_rows = [(m, d["name"], d["unit"], d["diameter_mm"], int(_is_conductor(m)))
                     for m, d in sorted(model.materials.items()) if m in used_mats]
    item_rows = [(it.item, it.pool, it.diameter_mm, it.kg_per_m, it.unit, it.order_no, it.order_status, it.order_date,
                  it.real_orders, it.live_orders) for it in items.values()]
    op_rows = [(it.item, *o) for it in items.values() for o in it.ops]
    input_rows = [(it.item, x.line, x.input_item, x.qty_per_unit, x.unit, x.parallel_ends, x.kind)
                  for it in items.values() for x in it.inputs]
    alt_rows = [(i, op, m, n) for (i, op, m), n in sorted(model.alt.items()) if i in items]

    count = collections.Counter(r[9] for r in fin_rows)
    reasons = collections.Counter(r[10] for r in fin_rows if r[9] == "untraceable")
    run = (1, SCHEMA_VERSION, datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), source_name, model.data_as_of,
           __version__, len(fin_rows), len(fin_rows) - count["untraceable"], len(items), count["to_rod"],
           count["to_purchased_conductor"], count["stops_early"], reasons["no_orders"], reasons["cutting_only"],
           sum(r[5] for r in fin_rows), sum(1 - r[5] for r in fin_rows), len(all_cut), len(check_list))

    data = {
        "cr_item": item_rows, "cr_operation": op_rows, "cr_input": input_rows, "cr_alt_machine": alt_rows,
        "cr_material": material_rows, "cr_finished": fin_rows, "cr_need": need_rows, "cr_machine": mach_rows,
        "cr_machine_limit": limits, "cr_machine_finished": mf_rows, "cr_flow_edge": flow, "cr_family": fams,
        "cr_family_code": fam_codes, "cr_range": ranges, "cr_code": code_rows,
        "cr_check": check_list, "cr_run": [run],
    }
    return {name: (COLS[name], data[name]) for name in ORDER}


def _is_conductor(item):
    from .parse import conductor_prefix
    return conductor_prefix(item)


def run_build(src: Source, source_name: str, db: Path, log=lambda m: None) -> tuple:
    tables = build_tables(src, source_name, log)
    write_atomic(db, tables)
    return tables["cr_run"][1][0]
