"""Issue-based weekly use of copper items (in-memory port of consumption_rate.py; contract 4.4 step 2).

Each posted picking line of a copper item is classified by who consumed it: `rod_in` (the rod), `internal` (an order
that makes copper), `downstream` (cable lines: the pull conductor stock has to cover).
"""

from __future__ import annotations

from datetime import date, timedelta

from . import config, source


def copper_basis(item: str, name: str, pool: str):
    prefix = item.split("-", 1)[0].upper()
    if prefix in config.COPPER_FAMILIES:
        return "prefix"
    if prefix in config.COPPER_REVIEW:
        return "review_prefix"
    if all(rx.search(name or "") for rx in config.COPPER_DESC_RE):
        return "description"
    if pool in config.STAGE_POOLS:
        return "stage_pool"
    return None


def compute(folder, products: dict, orders: dict, today: date) -> dict:
    """{(item, unit, flow): {"13w": weekly, "26w": weekly, "52w": weekly}}.

    orders: {order number: (item, pool)}. Windows are (today - N days, today]; qty is consumption + scrap (returns are
    negative, so a plain sum nets them).
    """
    starts = {k: today - timedelta(days=d) for k, d in config.WINDOWS.items()}
    weeks = {"13w": 13, "26w": 26, "52w": 52}
    basis_cache: dict[str, str | None] = {}

    def basis(item: str, pool_hint=None):
        if item not in basis_cache:
            p = products.get(item, {})
            basis_cache[item] = copper_basis(item, p.get("name", ""), p.get("pool", "") if pool_hint is None else pool_hint)
        return basis_cache[item]

    makes_cache: dict[str, bool] = {}

    def makes_copper(order: str) -> bool:
        if order not in makes_cache:
            oitem, opool = orders.get(order, ("", ""))
            p = products.get(oitem, {})
            makes_cache[order] = opool in config.STAGE_POOLS or copper_basis(oitem, p.get("name", ""), opool) is not None
        return makes_cache[order]

    acc: dict[tuple, dict] = {}
    for r in source.rows(folder, "production-picking-list-lines.csv"):
        if r["IsPosted"] != "Yes":
            continue
        item = r["ItemNumber"]
        if basis(item) is None and item != config.ROD_ITEM:
            continue
        try:
            d = date.fromisoformat(r["ConsumptionDate"][:10])
        except ValueError:
            continue
        if d <= starts["52w"] or d > today:
            continue
        flow = "rod_in" if item == config.ROD_ITEM else ("internal" if makes_copper(r["ProductionOrderNumber"]) else "downstream")
        qty = source.num(r["ConsumptionBOMQuantity"]) + source.num(r["ScrapBOMQuantity"])
        slot = acc.setdefault((item, r["BOMUnitSymbol"], flow), {"13w": 0.0, "26w": 0.0, "52w": 0.0})
        for k, start in starts.items():
            if d > start:
                slot[k] += qty
    return {key: {k: v / weeks[k] for k, v in slot.items()} for key, slot in acc.items()}
