# Generated from the RFQ parser's matcher/table_shape.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""LLM table-shape layer: per-sheet column mapping for unrecognized sheets.

Phase 3 of docs/TABLE_SHAPE_LLM_HANDOUT.md. When the deterministic parser gets
no structured quantity out of an xlsx sheet (typically a headerless takeoff),
this layer asks the model ONE question per sheet -- "which row is the header,
and what role does each column play?" -- then re-parses the sheet through the
existing deterministic extractors with synthetic role-named headers.

The model never authors work items: it cannot invent a quantity, a size, or a
product code, because it only ever names columns, and every claim it makes is
verified deterministically against the actual cells before anything is used
(see _validate_mapping). Routing derives from those validation outcomes, never
from the model's self-reported confidence. On any failure -- call error, bad
JSON, failed validation, or a re-parse that does not keep every field the
deterministic parse had while adding more -- the sheet's original items are kept
untouched.

Off by default; enable with RFQ_TABLE_SHAPE_LLM_ENABLED=true (plus the usual
OPENROUTER_API_KEY). Junk-flagged sheets (low_relevance_sheet) are never sent.
"""

from __future__ import annotations

from . import model_settings

import json
import os
import re
from collections import Counter
from pathlib import Path
from typing import Any

from rfq_parser.llm_fallback import (
    DEFAULT_MODEL,
    _float_env,
    _loads_json_object,
    _openrouter_chat,
    openrouter_enabled,
)

# Role vocabulary the model may use, and the synthetic header each role maps
# to. The headers are chosen so matcher.file_parser.header_role() reads them
# back into exactly the intended role -- the re-parse runs the SAME
# deterministic machinery as a sheet whose real headers were recognized.
ROLE_HEADERS = {
    "description": "Description",
    "quantity_total": "Total Length (m)",
    "quantity_each": "Length",
    "cuts": "Runs",
    "unit": "Unit",
    "reference": "Item No",
    "product_code": "Item Code",
    "earth_size": "Earth Size",
    "earth_length": "Earth Length (m)",
    "from": "From",
    "to": "To",
    "other": "",
}
_QUANTITYISH = {"quantity_total", "quantity_each", "cuts", "earth_length"}

_MAX_GRID_ROWS = 40
_MAX_GRID_COLS = 15
_MIN_SHEET_ITEMS = 3

_SYSTEM_PROMPT = (
    "You map spreadsheet columns to roles for an RFQ cable-quoting parser. "
    "You are given the raw cell grid of one sheet. Identify the header row (or "
    "null if the data starts immediately with no header) and assign each "
    "meaningful column a role from the provided vocabulary. Do not extract "
    "items, quantities, sizes, or product codes -- only name the columns. "
    "quantity_total is a column holding the total metres to supply; "
    "quantity_each is a per-run/route length; cuts is a count of separate "
    "runs/lengths; description is the free-text cable spec. Use \"other\" for "
    "columns that are none of these (prices, notes, voltage drop, ...). "
    "Respond with strict JSON only: "
    '{"header_row": <1-based row number or null>, '
    '"columns": [{"index": <0-based column index>, "role": "<role>"}]}'
)


def table_shape_enabled() -> bool:
    if not openrouter_enabled():
        return False
    return os.environ.get("RFQ_TABLE_SHAPE_LLM_ENABLED", "").strip().lower() in {
        "1", "true", "yes", "on",
    }


def table_shape_model() -> str:
    return (
        model_settings.get("RFQ_TABLE_SHAPE_MODEL")
        or model_settings.get("RFQ_NORMALIZER_MODEL")
        or model_settings.get("RFQ_LLM_MODEL", DEFAULT_MODEL)
    )


def table_shape_max_sheets() -> int:
    try:
        return max(0, int(os.environ.get("RFQ_TABLE_SHAPE_MAX_SHEETS", "4")))
    except ValueError:
        return 4


def table_shape_config() -> dict[str, Any]:
    """Health-endpoint view of this layer's configuration."""
    return {
        "enabled": table_shape_enabled(),
        "model": table_shape_model(),
        "max_sheets": table_shape_max_sheets(),
        "timeout_seconds": _float_env("RFQ_TABLE_SHAPE_TIMEOUT_SECONDS", 30),
    }


def maybe_remap_sheets(path: Path, items: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """Best-effort LLM column mapping for the sheets the parser couldn't structure.

    Returns ``items`` (possibly with some sheets' items replaced by structured
    ones). Never raises; never touches sheets that already have any structured
    quantity or that were junk-flagged.
    """
    if not table_shape_enabled():
        return items
    try:
        candidates = _candidate_sheets(items)
        if not candidates:
            return items
        sheet_rows = _read_sheet_rows(path)
    except Exception:
        return items

    remapped = 0
    for sheet_title in candidates:
        if remapped >= table_shape_max_sheets():
            break
        rows = sheet_rows.get(sheet_title)
        if not rows:
            continue
        try:
            # The deterministic parse of this sheet is the thing the remap has to beat,
            # and it is already in hand -- no need to re-derive it.
            old_items = [item for item in items if item.get("sheet") == sheet_title]
            new_items = _remap_sheet(path.name, sheet_title, rows, old_items)
        except Exception:
            new_items = None
        if new_items:
            items = _replace_sheet_items(items, sheet_title, new_items)
            remapped += 1
    return items


def _candidate_sheets(items: list[dict[str, Any]]) -> list[str]:
    """Sheets with enough rows, no structured quantity anywhere, and no junk flag."""
    by_sheet: dict[str, list[dict[str, Any]]] = {}
    for item in items:
        sheet = item.get("sheet")
        if sheet:
            by_sheet.setdefault(sheet, []).append(item)
    candidates = []
    for sheet, sheet_items in by_sheet.items():
        if len(sheet_items) < _MIN_SHEET_ITEMS:
            continue
        metadata = [item.get("metadata") or {} for item in sheet_items]
        if any(md.get("low_relevance_sheet") for md in metadata):
            continue
        if any(md.get("table_quantity") not in (None, "") for md in metadata):
            continue
        candidates.append(sheet)
    return candidates


def _read_sheet_rows(path: Path) -> dict[str, list[list[str]]]:
    from openpyxl import load_workbook

    from rfq_parser.file_parser import clean_cell

    workbook = load_workbook(path, data_only=True, read_only=True)
    try:
        return {
            sheet.title: [
                [clean_cell(value) for value in row]
                for row in sheet.iter_rows(values_only=True)
            ]
            for sheet in workbook.worksheets
        }
    finally:
        workbook.close()


# What a row is worth keeping for: the customer facts a reading either captured or lost.
# `unmapped_columns` is deliberately absent -- it records what was NOT understood, so
# counting it as coverage would reward a mapping for understanding less.
_COVERAGE_KEYS = (
    "table_quantity",
    "number_of_lengths",
    "reference",
    "product_code",
    "conductor_material",
    "earth_size",
    "from",
    "to",
)

_SIZED_RE = re.compile(r"\d+(?:\.\d+)?\s*mm(?:2|²)", re.I)


def _coverage(items: list[dict[str, Any]]) -> Counter:
    """How much of the customer's row survived a reading, counted per field.

    Text is counted two ways on purpose. A row with words is worth something; a row whose
    words still carry a conductor size is worth more, and that is the specific thing the
    flash-lite mapping dropped while every other measure looked fine.
    """
    counts: Counter = Counter()
    for item in items:
        metadata = item.get("metadata") or {}
        for key in _COVERAGE_KEYS:
            if metadata.get(key) not in (None, "", {}, []):
                counts[key] += 1
        text = str(item.get("text") or "").strip()
        if text:
            counts["text"] += 1
        if _SIZED_RE.search(text):
            counts["sized"] += 1
    return counts


def _unmapped_count(items: list[dict[str, Any]]) -> int:
    return sum(len((item.get("metadata") or {}).get("unmapped_columns") or {}) for item in items)


def _is_improvement(old_items: list[dict[str, Any]], new_items: list[dict[str, Any]]) -> bool:
    """Whether a remapped sheet is strictly better than the deterministic parse.

    The check this replaces asked only whether the remap produced any structured quantity
    at all (``structured == 0``). That cannot see a mapping that gains a quantity while
    losing something else, which is exactly the regression recorded in
    BODY_TABLE_FIDELITY_HANDOFF.md §3: flash-lite dropped the conductor size on two tables
    and still passed, because the size lives in the row text and nothing counted it.

    So: keep everything, gain something. A mapping that trades one field for another is
    not an improvement, it is a different set of losses -- and the deterministic parse is
    the one we can reason about, so it wins ties.
    """
    if not new_items:
        return False
    old = _coverage(old_items)
    new = _coverage(new_items)
    if any(new[key] < old[key] for key in old):
        return False
    if sum(new.values()) <= sum(old.values()):
        return False
    # A remap that understands fewer columns than the deterministic parse did is not a
    # better reading of the sheet, whatever else it gained.
    return _unmapped_count(new_items) <= _unmapped_count(old_items)


def _remap_sheet(
    file_name: str,
    sheet_title: str,
    rows: list[list[str]],
    old_items: list[dict[str, Any]] | None = None,
) -> list[dict[str, Any]] | None:
    """One sheet end to end: grid -> LLM -> validate -> deterministic re-parse."""
    from rfq_parser.file_parser import _tabular_items

    grid = [
        {"row": index, "cells": row[:_MAX_GRID_COLS]}
        for index, row in enumerate(rows[:_MAX_GRID_ROWS], start=1)
        if any(row)
    ]
    if len(grid) < _MIN_SHEET_ITEMS:
        return None

    mapping = _call_llm(file_name, sheet_title, grid)
    validated = _validate_mapping(mapping, rows) if mapping else None
    if not validated:
        return None

    header_row, roles = validated
    width = max(len(row) for row in rows)
    forced_headers = [ROLE_HEADERS.get(roles.get(col, "other"), "") for col in range(width)]
    new_items = _tabular_items(
        rows,
        sheet_title=sheet_title,
        forced_headers=forced_headers,
        first_data_row=(header_row + 1) if header_row else 1,
    )
    # The mapping must keep every field the deterministic parse had and add more; the
    # provenance is recorded on every item so downstream review can see where it came from.
    if not _is_improvement(old_items or [], new_items):
        return None
    for item in new_items:
        item.setdefault("metadata", {})["source_table_shape"] = "llm_column_mapping"
    return new_items


def _call_llm(file_name: str, sheet_title: str, grid: list[dict[str, Any]]) -> dict[str, Any] | None:
    packet = {
        "task": "Identify the header row and map each column to a role. Do not extract items.",
        "file": file_name,
        "sheet": sheet_title,
        "roles": [role for role in ROLE_HEADERS if role != "other"] + ["other"],
        "grid": grid,
    }
    payload: dict[str, Any] = {
        "model": table_shape_model(),
        "messages": [
            {"role": "system", "content": _SYSTEM_PROMPT},
            {"role": "user", "content": json.dumps(packet, ensure_ascii=True)},
        ],
        "max_tokens": 1000,
    }
    data, error = _openrouter_chat(
        payload, timeout=_float_env("RFQ_TABLE_SHAPE_TIMEOUT_SECONDS", 30)
    )
    if error or not data:
        return None
    try:
        content = data["choices"][0]["message"]["content"]
        return _loads_json_object(content)
    except (KeyError, IndexError, ValueError, TypeError):
        return None


def _validate_mapping(
    mapping: dict[str, Any], rows: list[list[str]]
) -> tuple[int | None, dict[int, str]] | None:
    """Deterministic checks over the model's column mapping.

    Returns ``(header_row, {column_index: role})`` or ``None``. Every check is
    against the actual cells; the model's confidence (if any) is ignored.
    """
    width = max((len(row) for row in rows), default=0)
    header_row = mapping.get("header_row")
    if header_row is not None:
        if not isinstance(header_row, int) or not (1 <= header_row < len(rows)):
            return None

    columns = mapping.get("columns")
    if not isinstance(columns, list) or not columns:
        return None
    roles: dict[int, str] = {}
    for column in columns:
        if not isinstance(column, dict):
            return None
        index, role = column.get("index"), column.get("role")
        if not isinstance(index, int) or not (0 <= index < width):
            return None
        if role not in ROLE_HEADERS:
            return None
        roles[index] = role

    if "description" not in roles.values():
        return None
    if not any(role in _QUANTITYISH for role in roles.values()):
        return None

    data_rows = rows[header_row:] if header_row else rows
    for index, role in roles.items():
        cells = [row[index] for row in data_rows if index < len(row) and row[index]]
        if role in _QUANTITYISH:
            if not cells:
                return None
            numeric = sum(1 for cell in cells if _parses_number(cell))
            if numeric / len(cells) < 0.6:
                return None
        elif role == "description":
            if not cells:
                return None
            texty = sum(1 for cell in cells if re.search(r"[A-Za-z]", cell))
            if texty / len(cells) < 0.5:
                return None
    return header_row, roles


def _parses_number(cell: str) -> bool:
    from rfq_parser.file_parser import parse_number, split_quantity_cell

    quantity, _cuts = split_quantity_cell(cell)
    return parse_number(quantity) is not None


def _replace_sheet_items(
    items: list[dict[str, Any]], sheet_title: str, new_items: list[dict[str, Any]]
) -> list[dict[str, Any]]:
    """Swap one sheet's items for the remapped ones, preserving overall order."""
    result: list[dict[str, Any]] = []
    inserted = False
    for item in items:
        if item.get("sheet") == sheet_title:
            if not inserted:
                result.extend(new_items)
                inserted = True
            continue
        result.append(item)
    if not inserted:
        result.extend(new_items)
    return result
