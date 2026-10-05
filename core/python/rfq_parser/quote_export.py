"""The quote's CSV tables and its product summary (parity plan E7.1).

Platform-owned, over the generated `pipeline`. The source builds these in two
places: `matcher/mail_ingest.build_quote_lines_csv` (the 8-column file behind
`POST /api/quote-csv`) and the editor's `summaryQuoteRow` in `rfq-app.js` (the
rows of the summary export). Both are ported here, at the pinned commit, so the
platform's export has the source's columns, order, serial/description split and
number formatting. The CSV text itself is written by PHP, which also
neutralises spreadsheet formula payloads (plan 5.5); this module returns the
cells.

Money: a row's `final_unit_price` and `line_total` are the sell side only. Cost
and margin are never cells. The two money columns are dropped, not blanked,
when `include_prices` is false, as in the source's simple view.
"""

from __future__ import annotations

import math
import re
from typing import Any

from . import pipeline

QUOTE_LINES_COLUMNS = (
    "Serial Number",
    "Product Description",
    "Product Code",
    "Cuts",
    "Cut Length",
    "Total Length",
    "Price",
    "Total Price",
)
PRICE_COLUMNS = ("Price", "Total Price")

_SERIAL_RE = re.compile(r"^([A-Za-z0-9][A-Za-z0-9._/-]*)\s*\|\s*(.+)$")


def _columns(include_prices: bool) -> list[str]:
    return [name for name in QUOTE_LINES_COLUMNS if include_prices or name not in PRICE_COLUMNS]


def _cell(value: Any) -> str:
    """Text as Python's csv module writes it: numbers by `str`, nothing as empty."""
    return "" if value is None else str(value)


def _csv_number(value: Any) -> Any:
    """`mail_ingest._csv_number`: a number prints without a trailing `.0`; text is kept."""
    if value in ("", None):
        return ""
    try:
        number = float(value)
    except (TypeError, ValueError):
        return value
    if not math.isfinite(number):
        return value
    return int(number) if number.is_integer() else number


def _row_original_text(row: dict) -> str:
    source = row.get("source") if isinstance(row.get("source"), dict) else {}
    return str(source.get("original_text") or row.get("raw_text") or "").strip()


def _split_serial_description(text: str, fallback_serial: int) -> tuple[str, str]:
    value = re.sub(r"\s+", " ", str(text or "")).strip()
    match = _SERIAL_RE.match(value)
    if match:
        return match.group(1).strip(), match.group(2).strip()
    return str(fallback_serial), value


def _row_total_price(row: dict) -> Any:
    if row.get("line_total") not in ("", None):
        return row.get("line_total")
    length = row.get("length") or {}
    total_length = length.get("total_length") or length.get("total_length_m")
    unit_price = row.get("final_unit_price")
    if unit_price in ("", None):
        unit_price = row.get("unit_price")
    if total_length in ("", None) or unit_price in ("", None):
        return ""
    try:
        return round(float(total_length) * float(unit_price), 2)
    except (TypeError, ValueError):
        return ""


def quote_lines_table(rows: list[dict], *, include_prices: bool) -> dict[str, Any]:
    """The cells of `quote-lines.csv`: every row given, in order."""
    columns = _columns(include_prices)
    out: list[list[str]] = []
    for index, row in enumerate(rows or [], start=1):
        serial, description = _split_serial_description(_row_original_text(row), index)
        length = row.get("length") or {}
        price = row.get("final_unit_price")
        if price in ("", None):
            price = row.get("unit_price")
        cells = {
            "Serial Number": serial,
            "Product Description": description,
            "Product Code": row.get("final_product_code") or row.get("selected_product_code") or "",
            "Cuts": _csv_number(length.get("number_of_lengths") or length.get("quantity")),
            "Cut Length": _csv_number(length.get("length_each") or length.get("length_each_m")),
            "Total Length": _csv_number(length.get("total_length") or length.get("total_length_m")),
            "Price": _csv_number(price),
            "Total Price": _csv_number(_row_total_price(row)),
        }
        out.append([_cell(cells[name]) for name in columns])
    return {"columns": columns, "rows": out}


def _js_round(value: float, decimals: int) -> float:
    """`Math.round(x * 10**d) / 10**d`: halves round up, as in the browser."""
    factor = 10**decimals
    return math.floor(value * factor + 0.5) / factor


def _normalize_length(value: float) -> Any:
    return int(value) if float(value).is_integer() else _js_round(value, 5)


def _number(value: Any) -> float:
    """JS `Number(value)` as far as these figures go; non-numbers are 0."""
    try:
        number = float(value)
    except (TypeError, ValueError):
        return 0.0
    return number if math.isfinite(number) else 0.0


def summary_quote_row(item: dict, rows: list[dict]) -> dict | None:
    """The editor's `summaryQuoteRow`: one product as a single line, or None when it
    has no code, no length or no sell price (such a product is listed but not exported)."""
    code = str(item.get("product_code") or "").strip()
    if not code:
        return None
    indexes = item.get("line_indexes") if isinstance(item.get("line_indexes"), list) else []
    source_rows = [rows[i] for i in indexes if isinstance(i, int) and 0 <= i < len(rows)]
    first = source_rows[0] if source_rows else {}
    total_length = (
        pipeline.to_positive_float(item.get("quoted_total_length"))
        or pipeline.to_positive_float(item.get("requested_total_length"))
        or sum(
            pipeline.to_positive_float((row.get("length") or {}).get("total_length") or (row.get("length") or {}).get("total_length_m")) or 0.0
            for row in source_rows
        )
    )
    if not total_length:
        return None
    source_total = sum(_number(row.get("line_total")) for row in source_rows)
    first_sell = pipeline.to_positive_float(first.get("final_unit_price")) or pipeline.to_positive_float(first.get("unit_price"))
    unit_price = _js_round(source_total / total_length, 3) if source_total > 0 else _js_round(first_sell or 0.0, 3)
    if not unit_price:
        return None
    length = _normalize_length(total_length)
    return {
        "raw_text": f"Summary quote - {code}",
        "source": {"original_text": f"Summary quote - {code}"},
        "final_product_code": code,
        "length": {"number_of_lengths": 1, "length_each": length, "total_length": length},
        "final_unit_price": unit_price,
        "line_total": _js_round(unit_price * total_length, 2),
    }


def summary_lines_table(summary: list[dict], rows: list[dict], *, include_prices: bool) -> dict[str, Any]:
    """The cells of `summary-quote-lines.csv`: one line per product that can be priced."""
    lines = [line for line in (summary_quote_row(item, rows) for item in summary) if line is not None]
    return quote_lines_table(lines, include_prices=include_prices)


def build_export(rows: list[dict], *, include_prices: bool) -> dict[str, Any]:
    """Everything the editor's Summary panel and both exports need, from priced rows."""
    summary = pipeline.build_product_summary(rows)
    return {
        "summary": summary,
        "quoteLines": quote_lines_table(rows, include_prices=include_prices),
        "summaryLines": summary_lines_table(summary, rows, include_prices=include_prices),
    }
