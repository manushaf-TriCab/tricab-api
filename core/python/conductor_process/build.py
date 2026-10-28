"""Orchestrates a build: model, explosion, machines, materials, run record (contract 4 rules 5, 7, 8)."""

from __future__ import annotations

import collections
from datetime import datetime, timezone
from pathlib import Path

from cable_routes.explode import explode
from cable_routes.loader import Source

from . import SCHEMA_VERSION, __version__
from . import reference
from .model import build_model
from .store import write_atomic

COLS = {
    "cd_machine": reference.MACHINE_COLUMNS,
    "cd_machine_operation": ("machine", "operation", "orders"),
    "cd_item": ("item", "pool", "stage", "prefix", "material", "mica", "form", "ends", "size", "diameter_mm",
                "kg_per_m", "unit", "order_no", "order_status", "order_date", "real_orders", "live_orders",
                "orders_52w", "run_size", "traces_to_rod", "step_count"),
    "cd_operation": ("item", "seq", "operation_number", "operation", "machine"),
    "cd_input": ("item", "line", "input_item", "qty_per_unit", "unit", "parallel_ends", "kind"),
    "cd_alt_machine": ("item", "operation", "machine", "orders"),
    "cd_material": ("item", "name", "unit", "is_rod"),
    "cd_need": ("root", "item", "unit", "need_per_unit", "depth", "kind"),
    "cd_run": ("run_id", "schema_version", "built_at", "source_name", "data_as_of", "engine_version", "window_from",
               "items", "finished_items", "traced_to_rod", "excluded_off_map"),
}
# Insert order: parents before children (foreign keys are checked at the end, but keep the file readable).
ORDER = ("cd_machine", "cd_machine_operation", "cd_item", "cd_operation", "cd_input", "cd_alt_machine", "cd_material",
         "cd_need", "cd_run")
STAGE_ORDER = ("wire", "bunch", "conductor", "mica_taped")


def build_tables(src: Source, extra: dict, source_name: str, log=lambda m: None) -> dict:
    model = build_model(src, extra)
    items = model.items
    by_stage = collections.Counter(it.stage for it in items.values())
    log(f"model: {len(items)} conductor items ({', '.join(f'{s} {by_stage[s]}' for s in STAGE_ORDER)}), "
        f"{len(model.excluded)} left out off the map, data as of {model.data_as_of}, window from {model.window_from}")

    # Rule 5: explode every cd_item over cd_items only; anything else is a leaf.
    graph = {i: [(x.input_item, x.qty_per_unit, x.unit) for x in it.inputs] for i, it in items.items()}
    unit_of = {i: it.unit for i, it in items.items()}
    need_rows, traces, steps = [], {}, {}
    cut = set()
    for root, it in items.items():
        tree = explode(root, graph, unit_of, it.unit)
        cut |= tree.cut
        made = [n for n in tree.order if n in items]
        traces[root] = int(any(items[n].has_rod_break for n in made))
        steps[root] = len(made)  # made nodes below the root, plus the root itself
        for n in tree.order:
            kind = "root" if n == root else ("made" if n in items else "material")
            for u, v in tree.need[n].items():
                need_rows.append((root, n, u, v, tree.depth[n], kind))
    log(f"explosion: {len(need_rows)} need rows, {sum(traces.values())} items trace to rod, "
        f"{len(cut)} backward edges cut")

    # Rule 7: machines. items = cd_items whose representative route uses the machine.
    per_machine = collections.Counter(m for it in items.values() for m in {op[3] for op in it.ops})
    mach_rows = reference.machine_rows(model.machine_stats, per_machine)
    mop_rows = [(m, op, n) for (m, op), n in sorted(model.machine_ops.items())]
    log(f"machines: {sum(1 for r in mach_rows if r[-3])} of {len(mach_rows)} with Dynamics orders, "
        f"{len(mop_rows)} machine operations")

    # Rule 8: materials; is_rod = consumed by an item whose route has ROD_BREAK.
    rod = {x.input_item for it in items.values() if it.has_rod_break for x in it.inputs if x.kind == "material"}
    material_rows = [(m, name, unit, int(m in rod)) for m, (name, unit) in sorted(model.materials.items())]
    log(f"materials: {len(material_rows)} ({len(rod)} rod)")

    item_rows = [(it.item, it.pool, it.stage, it.prefix, it.material, it.mica, it.form, it.ends, it.size,
                  it.diameter_mm, it.kg_per_m, it.unit, it.order_no, it.order_status, it.order_date, it.real_orders,
                  it.live_orders, it.orders_52w, it.run_size, traces[it.item], steps[it.item])
                 for it in items.values()]
    op_rows = [(it.item, *o) for it in items.values() for o in it.ops]
    input_rows = [(it.item, x.line, x.input_item, x.qty_per_unit, x.unit, x.parallel_ends, x.kind)
                  for it in items.values() for x in it.inputs]
    alt_rows = [(i, op, m, n) for (i, op, m), n in sorted(model.alt.items())]

    run = (1, SCHEMA_VERSION, datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"), source_name,
           model.data_as_of, __version__, model.window_from, len(items),
           by_stage["conductor"] + by_stage["mica_taped"], sum(traces.values()), len(model.excluded))
    if model.excluded:
        log(f"left out: {', '.join(model.excluded)}")

    data = {
        "cd_machine": mach_rows, "cd_machine_operation": mop_rows, "cd_item": item_rows, "cd_operation": op_rows,
        "cd_input": input_rows, "cd_alt_machine": alt_rows, "cd_material": material_rows, "cd_need": need_rows,
        "cd_run": [run],
    }
    return {name: (COLS[name], data[name]) for name in ORDER}


def run_build(src: Source, extra: dict, source_name: str, db: Path, log=lambda m: None) -> tuple:
    tables = build_tables(src, extra, source_name, log)
    write_atomic(db, tables)
    return tables["cd_run"][1][0]
