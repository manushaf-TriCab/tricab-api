# Generated from the RFQ parser's matcher/schedule_source.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Read the customer's sheet through the column map the reader proposed.

Phase 1 put the circuit reference, the From/To pair, the row's own words and the conductor
specification on every item. Phase 2's live read showed what that cost: **205 items became
5**, because the response grew to 3.7x what fits and the model printed it instead of
returning it. Those fields were 42,700 of the 60,000 added tokens, and every one of them is
a verbatim copy of a cell.

So the reader no longer sends them. It says which column means what, once per sheet, and
this module reads the values out of the workbook. The item ends up carrying exactly the same
facts -- `schedule_block` and the clean-schedule export are unchanged -- and the response is
a third of the size.

This is the project's own invariant applied to text rather than to numbers: the model
proposes the meaning, deterministic code supplies the value. It is also strictly more
reliable, because a value read from a cell cannot be a transcription error.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any

# The fields that are simply cells, and the item keys they land on. `conductor_spec`'s parts
# are nested and handled separately below.
_DIRECT_FIELDS = (
    ("circuit_reference", "circuit_reference"),
    ("from", "from"),
    ("to", "to"),
)
_SPEC_FIELDS = (
    ("active_size", "active_size"),
    ("neutral_size", "neutral_size"),
    ("earth_size", "earth_size"),
    ("conductor_material", "conductor_material"),
    ("cores", "cores"),
    ("insulation", "insulation"),
)
# What the row's own words are built from, in the order a reader would read them.
_DESCRIPTION_ORDER = (
    "circuit_reference", "from", "to", "active_size", "neutral_size",
    "conductor_material", "earth_size", "cores", "route_length", "insulation",
)


@dataclass
class ColumnMap:
    """`field -> column letter`, per sheet, as the reader proposed it."""

    by_sheet: dict[str, dict[str, str]] = field(default_factory=dict)
    header_rows: dict[str, int | None] = field(default_factory=dict)

    @classmethod
    def from_reader(cls, column_map: list[dict[str, Any]] | None) -> "ColumnMap":
        out = cls()
        for entry in column_map or []:
            if not isinstance(entry, dict):
                continue
            sheet = str(entry.get("sheet") or "").strip()
            if not sheet:
                continue
            columns: dict[str, str] = {}
            for column in entry.get("columns") or []:
                if not isinstance(column, dict):
                    continue
                name = str(column.get("field") or "").strip()
                letter = str(column.get("column") or "").strip().upper()
                # `other` is the reader saying "I saw a column and it is not one of these",
                # which is information but not a field to read.
                if name and name != "other" and letter.isalpha():
                    columns[name] = letter
            out.by_sheet[sheet] = columns
            header = entry.get("header_row")
            out.header_rows[sheet] = header if isinstance(header, int) else None
        return out

    def column(self, sheet: str, name: str) -> str:
        return (self.by_sheet.get(sheet) or {}).get(name, "")

    def cells_for(self, sheet: str, row: Any) -> list[dict[str, str]]:
        """`[{field, cell}]` for every mapped column on this row.

        This is the per-item `source_cells` Phase 1 asked the model for, rebuilt from the
        map instead. Same information for a reviewer, at 1/481st of the payload.
        """
        if not isinstance(row, int):
            return []
        return [
            {"field": name, "cell": f"{sheet}!{letter}{row}"}
            for name, letter in sorted((self.by_sheet.get(sheet) or {}).items())
        ]

    def __bool__(self) -> bool:
        return any(self.by_sheet.values())


def cell_value(workbook: Any, sheet: str, letter: str, row: Any) -> Any:
    if not letter or not isinstance(row, int):
        return None
    try:
        worksheet = workbook[sheet]
    except KeyError:
        return None
    try:
        return worksheet[f"{letter}{row}"].value
    except (ValueError, TypeError):
        return None


def _text(value: Any) -> str:
    if value is None:
        return ""
    if isinstance(value, float) and value.is_integer():
        return str(int(value))
    return str(value).strip()


def enrich_items(
    items: list[dict[str, Any]],
    workbook: Any,
    column_map: list[dict[str, Any]] | None,
) -> None:
    """Fill each item's cell-derived fields in place, from the workbook.

    In place, and only where the reader left a gap: an item that already carries a value
    keeps it. Nothing here overwrites a reading -- this supplies what the contract stopped
    asking for, it does not second-guess what it still asks for.

    Silently does nothing without a usable column map. That is not a swallowed failure:
    `quantity_check` reports the missing map loudly and on the same pass, and duplicating
    the alarm here would put the same sentence on the draft twice.
    """
    mapping = ColumnMap.from_reader(column_map)
    if not mapping:
        return

    for item in items:
        if not isinstance(item, dict):
            continue
        sheet = str(item.get("sheet") or "").strip()
        row = item.get("row")
        if not sheet or not isinstance(row, int):
            continue

        for field_name, key in _DIRECT_FIELDS:
            if not str(item.get(key) or "").strip():
                value = _text(cell_value(workbook, sheet, mapping.column(sheet, field_name), row))
                if value:
                    item[key] = value

        spec = item.get("conductor_spec")
        spec = dict(spec) if isinstance(spec, dict) else {}
        for field_name, key in _SPEC_FIELDS:
            if not str(spec.get(key) or "").strip():
                value = _text(cell_value(workbook, sheet, mapping.column(sheet, field_name), row))
                if value:
                    spec[key] = value
        if spec:
            item["conductor_spec"] = spec

        # The customer's own words for the row: every mapped cell, in column order, joined.
        # Built rather than transcribed, so the items split from one row necessarily share
        # it -- which is the property the review comparison depends on.
        if not str(item.get("source_description") or "").strip():
            parts = [
                _text(cell_value(workbook, sheet, mapping.column(sheet, name), row))
                for name in _DESCRIPTION_ORDER
                if mapping.column(sheet, name)
            ]
            joined = " | ".join(part for part in parts if part)
            if joined:
                item["source_description"] = joined

        if not item.get("source_cells"):
            cells = mapping.cells_for(sheet, row)
            if cells:
                item["source_cells"] = cells

        # The route length is a cell too. The reader stopped sending it for the same
        # reason it stopped sending the sizes, and the clean schedule still has to show
        # route-versus-total explicitly, so read it here.
        #
        # This records the route the sheet states. It does NOT touch `quantity`, which
        # stays the reader's total -- writing a recomputed total back is the 2026-09-09
        # bug, and the distinction between recording a route and rewriting a total is the
        # whole of that lesson.
        model = item.get("quantity_model")
        if isinstance(model, dict) and str(model.get("basis") or "") == "route":
            route = cell_value(workbook, sheet, mapping.column(sheet, "route_length"), row)
            if isinstance(route, (int, float)) and not isinstance(route, bool):
                model = dict(model)
                model.setdefault("route_length_m", route)
                # One cut of a route-based item measures the route.
                model.setdefault("cut_length_m", route)
                item["quantity_model"] = model
