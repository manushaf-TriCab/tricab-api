"""Pricing a draft's rows as the source editor does (parity plan E5).

Platform-owned, over the generated `pipeline`. The source split this between
Python (`apply_quote_pricing`: cost, MOQ, prepack, currency) and the browser
(`refreshAllPrices` / `recalculateSellingPrice`: which margin a row keeps, the
customer default, the sell price and line total). The browser half moves here,
so the server is the one place a price is made and the editor only shows it.

A row carries three flags, as in the source: `sell_price_locked` (the rep typed
a sell price, so the margin is derived from it), `margin_locked` (the rep typed
a margin, which outranks a customer default) and neither (the margin is the
customer's default for the product, else the one the row had).
"""

from __future__ import annotations

import math
from typing import Any

from . import pipeline

ACCESSORY_DEFAULT_MARGIN = pipeline.ACCESSORY_DEFAULT_MARGIN


def normalize_margin(value: Any) -> int:
    """Whole numbers only, truncated: 4js truncates the margin it derives (`normalizeMargin`)."""
    try:
        margin = float(value)
    except (TypeError, ValueError):
        return 0
    return math.trunc(margin) if math.isfinite(margin) and margin >= 0 else 0


COST_RULE = "cost"


def cost_value_default_margin(unit_price: Any, total_length: Any) -> int | None:
    """Port of M047mktm.x_cval2mgnr for an already-priced row: the margin 4js falls back to
    when a style group has no rule for the customer, by the value of the line's cost
    (the source's `_cost_value_default_margin`). The legacy function converts cost value to
    AUD first; the Cost API already answers in the region's own currency, so this is exact
    for Australian quotes and an approximation elsewhere until exchange rates are wired in."""
    try:
        cost = float(unit_price)
        length = float(total_length)
    except (TypeError, ValueError):
        return None
    if cost < 0 or length <= 0:
        return None
    cval = cost * length
    margin = 70
    for i, threshold_base in enumerate(range(500, 15501, 500), start=1):
        margin -= 5 if i < 4 else 2 if i < 5 else 1
        if cval < threshold_base * (100 - margin) / 100:
            return margin
    return 25


def derive_margin(cost: Any, sell: Any) -> int | None:
    """The margin a sell price implies, truncated as 4js truncates it.

    The source's `calculate_margin_percent` is `trunc((1 - cost / sell) * 100)`, and in floats
    `1 - 10/12.5` is 0.19999999999999996, so a sell price that is exactly 20% over reads 19.
    A margin typed as 20 gives 12.5 and the price typed back would give 19. Rounding to six places
    before truncating keeps an exact margin exact, and a negative one (under cost) as it was.
    """
    try:
        cost_value, sell_value = float(cost), float(sell)
    except (TypeError, ValueError):
        return None
    if sell_value <= 0:
        return None
    return math.trunc(round((1 - cost_value / sell_value) * 100, 6))


def _is_accessory(row: dict[str, Any]) -> bool:
    return str(row.get("line_kind") or row.get("item_type") or "").lower() == "accessory"


def _blank(value: Any) -> bool:
    return value is None or value == ""


def price_rows(
    rows: list[dict[str, Any]],
    *,
    region: str,
    currency: str,
    default_margins: dict[str, Any],
    reprice: bool,
) -> list[dict[str, Any]]:
    """The rows priced and margined, in the order given.

    With `reprice` the Cost API is asked (`apply_quote_pricing`), which also
    converts to `currency`. Without it, cost is left as stored and only margin,
    sell price and total are worked out again: that is a margin edit or a
    default arriving, not a new price.
    """
    prior = [row.get("margin_percent") for row in rows]
    if reprice:
        pipeline.apply_quote_pricing(rows, region=region, currency=currency)
    for row, before in zip(rows, prior):
        _margin(row, before, default_margins)
    return rows


def _margin(row: dict[str, Any], before: Any, default_margins: dict[str, Any]) -> None:
    cost = row.get("unit_price")
    if row.get("sell_price_locked") and pipeline.to_positive_float(row.get("final_unit_price")):
        # The rep committed to this number; the margin absorbs a change in cost.
        row["margin_percent"] = derive_margin(cost, row["final_unit_price"]) or 0
        row["line_total"] = pipeline.calculate_line_total(row.get("length") or {}, row["final_unit_price"])
        return
    row.pop("sell_price_locked", None)

    code = pipeline.product_code_for_result(row).upper()
    default = default_margins.get(code)
    length = row.get("length") or {}
    if default == COST_RULE:
        default = cost_value_default_margin(cost, length.get("total_length") or length.get("total_length_m"))
    if row.get("margin_locked"):
        margin = normalize_margin(before)
    elif not _blank(default):
        margin = normalize_margin(default)
    elif _is_accessory(row) and _blank(before):
        margin = ACCESSORY_DEFAULT_MARGIN
    else:
        margin = normalize_margin(before)
    row["margin_percent"] = margin
    if _blank(cost):
        row["final_unit_price"] = None
        row["line_total"] = None
        return
    row["final_unit_price"] = pipeline.calculate_final_unit_price(cost, margin)
    row["line_total"] = pipeline.calculate_line_total(row.get("length") or {}, row["final_unit_price"])


def unpriced_count(rows: list[dict[str, Any]]) -> int:
    """Rows that ought to have a price and have none: the editor's "No price for X of N lines"."""
    count = 0
    for row in rows:
        if pipeline._row_pricing_mode(row) == "excluded" or not pipeline.product_code_for_result(row):
            continue
        if not pipeline._row_is_priced(row, row.get("pricing") if isinstance(row.get("pricing"), dict) else {}):
            count += 1
    return count
