"""Representative orders, inputs, operations and alternative machines (contract 4.4 rules 1 to 8)."""

from __future__ import annotations

import collections
from dataclasses import dataclass, field

from .loader import Source, num
from . import parse
from .parse import FINISHED_POOLS


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
    diameter_mm: float | None
    kg_per_m: float | None
    unit: str
    order_no: str
    order_status: str
    order_date: str
    real_orders: int
    live_orders: int
    ops: list = field(default_factory=list)  # (seq, operation_number, operation, machine)
    inputs: list = field(default_factory=list)
    has_rod_break: bool = False


@dataclass
class Model:
    items: dict  # made items by item number
    finished: list  # sorted item numbers, pool 50_FMAN or 60_CAT
    no_real_reason: dict  # untraceable finished item -> 'no_orders' | 'cutting_only'
    alt: dict  # (item, operation, machine) -> live real-order count
    materials: dict  # item -> dict(name, unit, diameter_mm)
    products: dict
    pools: dict
    data_as_of: str


def _positive(x):
    v = num(x)
    return v if v > 0 else None


def build_model(src: Source) -> Model:
    orders = collections.defaultdict(list)
    for h in src.headers:
        orders[h["ItemNumber"]].append(h)
    bom = collections.defaultdict(list)
    for b in src.bom:
        bom[b["ProductionOrderNumber"]].append(b)
    route = collections.defaultdict(list)
    for r in src.route:
        route[r["ProductionOrderNumber"]].append(r)

    def real_orders(item):
        return [o for o in orders.get(item, ())
                if o["CsIsCuttingOrder"] != "Yes"
                and any(b["ItemNumber"] != item for b in bom.get(o["ProductionOrderNumber"], ()))]

    rep, real = {}, {}
    for item in orders:
        rs = real_orders(item)
        if rs:
            real[item] = rs
            rep[item] = max(rs, key=lambda o: (o["ProductionOrderStatus"] != "Cancelled",
                                               o["ScheduledDate"], o["ProductionOrderNumber"]))

    def pool_of(item):
        return src.pools.get(item) or rep[item]["ProductionPoolId"] or ""

    made = {i for i in rep if pool_of(i) in parse.MADE_POOLS}

    items: dict[str, Item] = {}
    alt: dict = collections.Counter()
    for item in sorted(made):
        o = rep[item]
        no = o["ProductionOrderNumber"]
        p = src.products.get(item, {})
        it = Item(
            item=item, pool=pool_of(item),
            diameter_mm=_positive(p.get("CsDiameter")), kg_per_m=_positive(p.get("NetProductWeight")),
            unit=p.get("InventoryUnitSymbol", ""),
            order_no=no, order_status=o["ProductionOrderStatus"], order_date=o["ScheduledDate"][:10],
            real_orders=len(real[item]),
            live_orders=sum(1 for r in real[item] if r["ProductionOrderStatus"] != "Cancelled"),
        )
        for seq, r in enumerate(sorted(route.get(no, ()), key=lambda r: int(num(r["OperationNumber"]))), 1):
            it.ops.append((seq, int(num(r["OperationNumber"])), r["OperationId"], r["CostingOperationResourceId"]))
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
                kind="made" if b["ItemNumber"] in made else "material"))
        for ro in real[item]:
            if ro["ProductionOrderStatus"] == "Cancelled":
                continue
            for op in route.get(ro["ProductionOrderNumber"], ()):
                if not op["CostingOperationResourceId"]:  # an operation booked with no resource is not a machine
                    continue
                alt[(item, op["OperationId"], op["CostingOperationResourceId"])] += 1
        items[item] = it

    finished = sorted(i for i, pl in src.pools.items() if pl in FINISHED_POOLS)
    no_real_reason = {i: ("cutting_only" if i in orders else "no_orders") for i in finished if i not in items}

    materials = {}
    for it in items.values():
        for inp in it.inputs:
            if inp.kind == "material" and inp.input_item not in materials:
                p = src.products.get(inp.input_item, {})
                name = src.descriptions.get(inp.input_item)
                materials[inp.input_item] = {
                    "name": name[:48].rstrip(" ,.") or None if name else None,
                    "unit": p.get("InventoryUnitSymbol") or None,
                    "diameter_mm": _positive(p.get("CsDiameter")),
                }

    dates = [h["ScheduledDate"][:10] for h in src.headers if h["ScheduledDate"][:10] not in ("", "1900-01-01")]
    return Model(items=items, finished=finished, no_real_reason=no_real_reason, alt=dict(alt),
                 materials=materials, products=src.products, pools=src.pools,
                 data_as_of=max(dates) if dates else "")
