# Generated from the RFQ parser's matcher/currency.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Temporary fixed quote-currency conversion schedule.

The matrix was supplied by Finance with an effective date of 2026-09-30.  Rows are
the target currency and columns are the source/base currency.
"""
from __future__ import annotations

from typing import Any

RATE_EFFECTIVE_DATE = "2026-09-30"
SUPPORTED_CURRENCIES = ("AUD", "NZD", "USD", "SGD")

RATES = {
    "AUD": {"AUD": 1.0000, "USD": 1.4333, "NZD": 0.8097, "SGD": 1.1221},
    "USD": {"AUD": 0.6977, "USD": 1.0000, "NZD": 0.5649, "SGD": 0.7829},
    "NZD": {"AUD": 1.2351, "USD": 1.7702, "NZD": 1.0000, "SGD": 1.3858},
    "SGD": {"AUD": 0.8912, "USD": 1.2773, "NZD": 0.7216, "SGD": 1.0000},
}

REGION_BASE_CURRENCY = {
    "australia": "AUD",
    "newzealand": "AUD",  # NZ currently reads the Australian cost book.
    "usa": "USD",
    "asia": "USD",
}


def conversion_rate(source: str, target: str) -> float:
    return RATES[str(target).upper()][str(source).upper()]


def convert_amount(value: Any, source: str, target: str) -> Any:
    if value in (None, ""):
        return value
    return round(float(value) * conversion_rate(source, target), 5)


def convert_priced_rows(rows: list[dict], region: str, target_currency: str) -> None:
    """Convert Cost API results once, retaining provenance on each pricing record."""
    base = REGION_BASE_CURRENCY.get(str(region or "").lower(), "AUD")
    target = str(target_currency or base).upper()
    if target not in SUPPORTED_CURRENCIES:
        raise ValueError(f"Unsupported currency: {target!r}")

    for row in rows:
        if not isinstance(row.get("pricing"), dict) and all(
            row.get(field) in (None, "")
            for field in ("unit_price", "final_unit_price", "line_total")
        ):
            row.pop("_previous_quote_currency", None)
            continue
        pricing = row.get("pricing") if isinstance(row.get("pricing"), dict) else {}
        previous_currency = str(row.pop("_previous_quote_currency", None) or base).upper()
        pricing["base_currency"] = base
        pricing["quote_currency"] = target
        pricing["exchange_rate"] = conversion_rate(base, target)
        pricing["exchange_rate_date"] = RATE_EFFECTIVE_DATE
        if pricing.get("unit_price") is not None:
            pricing["unit_price"] = convert_amount(pricing["unit_price"], base, target)
        row["pricing"] = pricing
        if row.get("unit_price") is not None:
            row["unit_price"] = convert_amount(row["unit_price"], base, target)
        sell_source = previous_currency if row.get("sell_price_locked") else base
        if row.get("final_unit_price") is not None:
            row["final_unit_price"] = convert_amount(row["final_unit_price"], sell_source, target)
        length = (row.get("length") or {}).get("total_length")
        if length is None:
            length = (row.get("length") or {}).get("total_length_m")
        if row.get("final_unit_price") not in (None, "") and length not in (None, ""):
            row["line_total"] = round(float(row["final_unit_price"]) * float(length), 2)
