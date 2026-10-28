"""Copper conductor items, map-only representative orders, alternatives and machine stats (contract 4 rules 1-4, 6, 7, 9)."""

from __future__ import annotations

import collections
import re
import statistics
from dataclasses import dataclass, field
from datetime import date, timedelta

from cable_routes.loader import Source, num

from . import reference

STAGE_OF_POOL = {"15_WIR": "wire", "20_BUN": "bunch", "25_FCON": "conductor", "30_TAPE": "mica_taped"}
PREFIXES = {"NX", "DX", "DM", "MNX", "NM", "MNM", "VX", "VDX", "MDX", "MDM", "DXI"}  # copper-v2 rule
PLAIN = {"NX", "NM", "MNX", "MNM"}
TINNED = {"DX", "DM", "MDX", "MDM", "DXI"}
MICA = {"NM", "MNX", "MNM", "DM", "MDX", "MDM"}
_ITEM = re.compile(r"^[A-Z]+-(\d+)([CW])(\d*\.?\d+)")


@dataclass
class Input:
    line: int
    input_item: str
    qty_per_unit: float
    unit: str
    parallel_ends: int
    kind: str  # 'made' | 'material'


@dataclass
class Item:
    item: str
    pool: str
    stage: str
    prefix: str
    material: str
    mica: int
    form: str
    ends: int | None
    size: float | None
    diameter_mm: float | None
    kg_per_m: float | None
    unit: str
    order_no: str
    order_status: str
    order_date: str
    real_orders: int
    live_orders: int
    orders_52w: int
    run_size: float | None
    ops: list = field(default_factory=list)  # (seq, operation_number, operation, poster machine)
    inputs: list = field(default_factory=list)
    has_rod_break: bool = False


@dataclass
class Model:
    items: dict  # cd_item rows by item number
    excluded: list  # copper items with no all-on-map real order, sorted
    alt: dict  # (item, operation, poster machine) -> non-cancelled real orders
    machine_stats: dict  # poster machine -> [orders_total, orders_cancelled, orders_52w]
    machine_ops: dict  # (poster machine, operation) -> non-cancelled real orders
    materials: dict  # item -> (name, unit)
    data_as_of: str
    window_from: str


def prefix_of(item: str) -> str:
    return item.split("-", 1)[0].upper()


def parse_form(item: str):
    """(form, ends, size) by rule 3: NX-1C16 -> ('core', 1, 16.0); NX-32W.3 -> ('wires', 32, 0.3)."""
    m = _ITEM.match(item)
    if not m:
        return "other", None, None
    return ("core" if m.group(2) == "C" else "wires"), int(m.group(1)), float(m.group(3))


def material_of(prefix: str) -> str:
    return "plain" if prefix in PLAIN else "tinned" if prefix in TINNED else "unknown"


def _positive(x):
    v = num(x)
    return v if v > 0 else None


def _rank(o):
    return (o["ProductionOrderStatus"] != "Cancelled", o["ScheduledDate"], o["ProductionOrderNumber"])


def window(data_as_of: str) -> str:
    return (date.fromisoformat(data_as_of) - timedelta(days=364)).isoformat() if data_as_of else ""


def build_model(src: Source, extra: dict) -> Model:
    on_map = reference.on_map()  # D365 resource -> poster name
    orders = collections.defaultdict(list)
    for h in src.headers:
        orders[h["ItemNumber"]].append(h)
    bom = collections.defaultdict(list)
    for b in src.bom:
        bom[b["ProductionOrderNumber"]].append(b)
    route = collections.defaultdict(list)
    for r in src.route:
        route[r["ProductionOrderNumber"]].append(r)

    dates = [h["ScheduledDate"][:10] for h in src.headers if h["ScheduledDate"][:10] not in ("", "1900-01-01")]
    data_as_of = max(dates) if dates else ""
    window_from = window(data_as_of)

    def in_window(o):
        f = extra.get(o["ProductionOrderNumber"], ("", ""))[1]
        return bool(window_from) and window_from <= f <= data_as_of

    def resources(no):  # operations booked with no resource are not machines
        return [r for r in route.get(no, ()) if r["CostingOperationResourceId"]]

    # Rule 2: copper conductor items, before the map filter.
    copper: dict[str, list] = {}
    pool = {}
    for item, os_ in orders.items():
        if prefix_of(item) not in PREFIXES:
            continue
        rs = [o for o in os_ if o["CsIsCuttingOrder"] != "Yes"
              and any(b["ItemNumber"] != item for b in bom.get(o["ProductionOrderNumber"], ()))]
        if not rs:
            continue
        p = src.pools.get(item) or max(rs, key=_rank)["ProductionPoolId"] or ""
        if p in STAGE_OF_POOL:
            copper[item], pool[item] = rs, p

    # Rule 7: machine stats over every copper item's real orders.
    stats = {m: [0, 0, 0] for m in on_map.values()}
    machine_ops: dict = collections.Counter()
    for rs in copper.values():
        for o in rs:
            ops = [(r["OperationId"], on_map[r["CostingOperationResourceId"]])
                   for r in resources(o["ProductionOrderNumber"]) if r["CostingOperationResourceId"] in on_map]
            cancelled = o["ProductionOrderStatus"] == "Cancelled"
            fin = not cancelled and in_window(o)
            for m in {m for _, m in ops}:
                s = stats[m]
                s[0] += 1
                s[1] += cancelled
                s[2] += fin
            if not cancelled:
                for key in {(m, op) for op, m in ops}:
                    machine_ops[key] += 1

    # Rule 4: representative order among the all-on-map real orders.
    rep, excluded = {}, []
    for item, rs in copper.items():
        ok = [o for o in rs if all(r["CostingOperationResourceId"] in on_map
                                   for r in resources(o["ProductionOrderNumber"]))]
        if ok:
            rep[item] = max(ok, key=_rank)
        else:
            excluded.append(item)

    items: dict[str, Item] = {}
    alt: dict = collections.Counter()
    for item in sorted(rep):
        o, rs = rep[item], copper[item]
        no = o["ProductionOrderNumber"]
        prefix = prefix_of(item)
        form, ends, size = parse_form(item)
        p = src.products.get(item, {})
        live = [r for r in rs if r["ProductionOrderStatus"] != "Cancelled"]
        recent = [r for r in live if in_window(r)]
        received = [q for q in (num(extra.get(r["ProductionOrderNumber"], ("", ""))[0]) for r in recent) if q > 0]
        it = Item(
            item=item, pool=pool[item], stage=STAGE_OF_POOL[pool[item]], prefix=prefix, material=material_of(prefix),
            mica=int(prefix in MICA), form=form, ends=ends, size=size,
            diameter_mm=_positive(p.get("CsDiameter")), kg_per_m=_positive(p.get("NetProductWeight")),
            unit=p.get("InventoryUnitSymbol", ""),
            order_no=no, order_status=o["ProductionOrderStatus"], order_date=o["ScheduledDate"][:10],
            real_orders=len(rs), live_orders=len(live), orders_52w=len(recent),
            run_size=statistics.median(received) if received else None,
        )
        ops = sorted(resources(no), key=lambda r: int(num(r["OperationNumber"])))
        for seq, r in enumerate(ops, 1):
            it.ops.append((seq, int(num(r["OperationNumber"])), r["OperationId"], on_map[r["CostingOperationResourceId"]]))
        it.has_rod_break = any(op == "ROD_BREAK" for _, _, op, _ in it.ops)
        used: set[int] = set()
        for b in sorted(bom.get(no, ()), key=lambda r: num(r["LineNumber"])):
            if b["ItemNumber"] == item:
                continue
            line = int(num(b["LineNumber"]))
            while line in used:  # a repeated LineNumber on one order: keep the row, move it to the next free number
                line += 1
            used.add(line)
            it.inputs.append(Input(
                line=line, input_item=b["ItemNumber"],
                qty_per_unit=num(b["BOMLineQuantity"]) / (num(b["BOMLineQuantityDenominator"]) or 1),
                unit=b["BOMLineUnitSymbol"], parallel_ends=int(num(b["CsPrdParallelLines"])) or 1,
                kind="made" if b["ItemNumber"] in rep else "material"))
        for r in live:  # rule 6: alternatives, on-map resources only, distinct orders
            for key in {(item, op["OperationId"], on_map[op["CostingOperationResourceId"]])
                        for op in resources(r["ProductionOrderNumber"]) if op["CostingOperationResourceId"] in on_map}:
                alt[key] += 1
        items[item] = it

    materials = {}
    for it in items.values():
        for inp in it.inputs:
            if inp.kind == "material" and inp.input_item not in materials:
                name = src.descriptions.get(inp.input_item)
                materials[inp.input_item] = (name[:48].rstrip(" ,.") or None if name else None,
                                             src.products.get(inp.input_item, {}).get("InventoryUnitSymbol") or None)

    return Model(items=items, excluded=sorted(excluded), alt=dict(alt), machine_stats=stats,
                 machine_ops=dict(machine_ops), materials=materials, data_as_of=data_as_of, window_from=window_from)

