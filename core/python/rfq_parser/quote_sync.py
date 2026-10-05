"""The 4js quote plan: everything about a sync that is decided before 4js is contacted (parity plan E7.3).

Platform-owned, over the generated `pipeline` and `quote_readiness`. This is the
source's `prepare_quote_push` (`matcher/pipeline.py`, pinned commit), with its
two helpers `push_unit_price` and `_row_needs_default_margin` and the header
comment rule, ported so the platform freezes the same plan the source does: the
header parameters, the priced line items, the exclusions and the fallback
margins. It is pure. It sends nothing and reads no database: the customer's
default margins and today's date are passed in, where the source reads them
(`resolve_push_margins`, `date.today()`).

**Nothing here writes to 4js.** The plan is data. PHP freezes it as the attempt's
payload and a gateway adapter (a recording fake in every test, no live adapter
wired) is what would send it.

Differences from the source, none of them in the plan's numbers:

* the header's `type` and `live` parameters are not in the plan: they belong to
  the transport, so the gateway adds them;
* a line's `line_note` of `None` is read as empty (the source raises);
* the margin rule value `"cost"` (the platform's pricing pass can return it) is
  resolved to the margin 4js itself falls back to for that line's cost value,
  as the pricing pass resolves it.
"""

from __future__ import annotations

from datetime import date, timedelta
from typing import Any

from . import pipeline, quote_pricing
from .quote_readiness import (
    exclusion_reason,
    normalize_currency,
    product_code as canonical_product_code,
    quote_quantity,
    quote_readiness,
    region_profile,
    reviewed_price,
    row_push_block_reason,
    row_reference,
)

QUOTE_ORIGIN_PORTAL = "portal"
QUOTE_ORIGIN_EMAIL = "email"

#: `MACHINE_MARGIN_SOURCE` / `MACHINE_MARGIN_FALLBACK`: a margin this code wrote, not a person.
MACHINE_MARGIN_SOURCE = "customer_default"
MACHINE_MARGIN_FALLBACK = "fallback"

FALLBACK_PUSH_MARGIN = pipeline.FALLBACK_PUSH_MARGIN

REQUIRED_HEADER_FIELDS = ("customer_id", "contact", "author", "salesoffice", "payterms", "region")


def _row_needs_default_margin(row: dict) -> bool:
    """Whether this row would otherwise be pushed at cost (the source's rule, unchanged)."""
    if row_push_block_reason(row, reviewed=True):
        return False
    if not canonical_product_code(row):
        return False
    if row.get("sell_price_locked"):
        return False
    if row.get("margin_source") in {MACHINE_MARGIN_SOURCE, MACHINE_MARGIN_FALLBACK}:
        return True
    return pipeline.to_float(row.get("margin_percent"), default=0.0) <= 0


def _margin_for(row: dict, margins: dict[str, Any]) -> Any:
    """The customer's rule for this line, or None when there is none."""
    value = margins.get(canonical_product_code(row).upper())
    if value in ("", None):
        return None
    if value == quote_pricing.COST_RULE:
        length = row.get("length") or {}
        return quote_pricing.cost_value_default_margin(row.get("unit_price"), length.get("total_length") or length.get("total_length_m"))
    return value


def push_unit_price(row: dict, margins: dict[str, Any]) -> tuple[float | None, str | None]:
    """The sell price this line goes to 4js with, and why it has none (the source's, unchanged)."""
    cost = pipeline.to_positive_float(row.get("unit_price"))
    sell = reviewed_price(row)
    if not _row_needs_default_margin(row):
        return sell, None
    if cost is None:
        return sell, None
    margin = _margin_for(row, margins)
    if margin is None:
        margin = FALLBACK_PUSH_MARGIN
    priced = pipeline.to_positive_float(pipeline.calculate_final_unit_price(cost, margin))
    if priced is None:
        return None, f"default margin {margin} produced no sell price"
    return priced, None


def quote_header_comment(quote_details: dict | None, origin: str) -> str:
    """The QOTHDR comment: what the rep typed, plus the portal marker (the source's, unchanged)."""
    typed = str((quote_details or {}).get("comment") or "").strip()
    if origin != QUOTE_ORIGIN_PORTAL:
        return typed
    author = str((quote_details or {}).get("author") or "").strip()
    marker = f"Auto-created from RFQ portal by {author}" if author else "Auto-created from RFQ portal"
    return " - ".join(part for part in (typed, marker) if part)


def next_quote_version(current_refver: Any) -> int:
    """Next 4js version for an edit after a sync: at least 2, one past the current."""
    try:
        current = int(str(current_refver).strip())
    except (TypeError, ValueError):
        current = 1
    return max(2, current + 1)


def prepare_quote_push(
    rows: list[dict],
    quote_details: dict,
    *,
    margins: dict[str, Any],
    today: date,
    existing_refno: str | None = None,
    version: int | None = None,
    origin: str = "",
    reviewed: bool = True,
) -> tuple[dict | None, tuple[dict, int] | None]:
    """Decide everything that can be decided before 4js is contacted.

    Returns `(plan, problem)`; exactly one is set. `problem` is `(payload, status)`:
    400 for missing header fields, an unknown region or currency, lines that are not
    ready, or lines with no price; 409 when a person's reviewed price differs from
    what the customer's rule requires. `plan` is plain data: the header parameters,
    the priced line items, and the exclusions and fallback margins to report with them.
    """
    rows = rows or []
    qd = quote_details or {}
    is_update = bool(existing_refno) and version is not None

    missing = [field for field in REQUIRED_HEADER_FIELDS if not str(qd.get(field, "")).strip()]
    if missing:
        return None, ({"ok": False, "error": f"Missing required fields: {', '.join(missing)}"}, 400)

    profile = region_profile(qd.get("region"))
    if not profile:
        return None, ({"ok": False, "error": f"Unknown region: {qd.get('region')!r}"}, 400)
    debtor = profile["debtor"]
    currency = normalize_currency(qd.get("currency"), qd.get("region"))
    if not currency:
        return None, ({"ok": False, "error": f"Unsupported currency: {qd.get('currency')!r}"}, 400)

    readiness = quote_readiness(rows, qd, reviewed=reviewed)
    excluded = readiness["excluded"]
    if not readiness["ready"]:
        return None, ({
            "ok": False, "error": "Fix the listed issues before syncing. No quote was sent to 4js.",
            **readiness, "skipped": [],
        }, 400)

    line_items, unpriced, price_changes, fallback_margin = [], [], [], []
    for index, row in enumerate(rows):
        if exclusion_reason(row):
            continue
        code = canonical_product_code(row)
        quantity = quote_quantity(row)
        price, margin_reason = push_unit_price(row, margins)
        ref = row_reference(row, index)
        if _row_needs_default_margin(row) and _margin_for(row, margins) is None:
            fallback_margin.append({**ref, "margin": FALLBACK_PUSH_MARGIN,
                                    "reason": f"No customer margin for this product; quoted at {FALLBACK_PUSH_MARGIN}%. Check the price in 4js."})
        if margin_reason:
            unpriced.append({**ref, "reason": margin_reason})
            continue
        if not _row_needs_default_margin(row) and (
            price is None or f"{price:.5f}" != f"{reviewed_price(row):.5f}"
        ):
            price_changes.append({**ref, "reviewed_price": reviewed_price(row),
                                  "required_price": price,
                                  "reason": "Customer pricing differs from this draft. Refresh the customer margins and review the updated price."})
            continue
        is_accessory = str(row.get("line_kind") or "").lower() == "accessory"
        line_items.append({
            "code": code, "quantity": quantity, "price": price,
            "cprc": pipeline.to_float((row.get("pricing") or {}).get("unit_price"), default=0.0),
            "comment": str(row.get("line_note") or "")[:200],
            "unitsys": "U" if is_accessory else "M",
            "row_number": ref["row_number"], "row_id": ref["row_id"],
        })

    if unpriced:
        codes = ", ".join(sorted({item["code"] for item in unpriced}))
        return None, ({
            "ok": False,
            "error": f"Cannot price {len(unpriced)} line(s) — no default margin is configured for this customer: {codes}. Set a margin on the quote, or have the customer's margin set up in 4js, then sync again.",
            "unpriced": unpriced, "blocking_issues": unpriced, "excluded": excluded, "skipped": [],
        }, 400)
    if price_changes:
        return None, ({
            "ok": False, "error": "Prices have changed. Refresh customer margins and review the updated quote before syncing. Nothing was sent to 4js.",
            "price_changes": price_changes, "blocking_issues": price_changes,
            "excluded": excluded, "skipped": [],
        }, 409)

    valid_till = qd.get("validtill") or (today + timedelta(days=7)).strftime("%d/%m/%y")
    tx_date = today.strftime("%d/%m/%y")
    push_version = str(version) if is_update else "0"
    hdr_ref = str(existing_refno) if is_update else "0"

    plan = {
        "is_update": is_update,
        "existing_refno": str(existing_refno or ""),
        "push_version": push_version,
        "hdr_params": {
            "ref": hdr_ref,
            "cust": str(qd["customer_id"]),
            "debtor": debtor,
            "currency": currency,
            "unitsys": "M",
            "salesoffice": qd["salesoffice"],
            "payterms": qd["payterms"],
            "author": qd["author"],
            "validtill": valid_till,
            "carrier": qd.get("carrier", ""),
            "contact": qd["contact"],
            # Blank on purpose, as the source sends it: the contact name alone prints on
            # the PDF, and a blank value clears a phone an earlier version put there.
            "phone": "",
            "comment": quote_header_comment(qd, origin),
            "cusref": qd.get("cusref", ""),
            "status": "DRAFT",
            "date": tx_date,
            "version": push_version,
        },
        "line_items": line_items,
        "excluded": excluded,
        "fallback_margin": fallback_margin,
        "customer_id": str(qd["customer_id"]),
        "cusref": qd.get("cusref", ""),
    }
    return plan, None
