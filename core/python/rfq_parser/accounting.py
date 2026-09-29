"""Source accounting: every input row kept, excluded or skipped, with a reason (plan 3.4).

`file_parser` reads a file into work items and says nothing about the rows it did
not turn into one: a column header, a section banner carried forward as context, a
product code folded into the line above, a hidden sheet. The source never counted
them. Invariant 3 needs them counted, because the only way to tell "this row was
deliberately not a line" from "this row went missing" is to account for each row and
see whether the sums balance.

So this module walks the same file the same way and classifies each non-blank row:

- **line**: it produced work items. It is *kept* when any of its matched results
  is in the quote and *excluded* when every one of them is `exclusion_reason`'s
  "not in this quote". A line with no result at all is in neither, and the
  coverage does not balance.
- **skipped**, with the reason: a header row, a section heading or note carried as
  context, a hidden sheet, a row outside a model's column mapping.
- **continuation**: a lone product code that `file_parser` attached to the line
  above. It counts with that line.

Every predicate is `file_parser`'s own (`looks_like_header`,
`product_code_continuation`, the spanning-label rule), called in the order the
parser calls them, so the walk cannot disagree with the parse about what a row was.
Rows that produced items are taken from the items themselves; the walk only
classifies the rows that did not. A row the walk calls a line but that produced no
item is *unaccounted*, which is how drift between the two would show.

**Measured** for pasted text, `.txt`, `.csv`, `.xlsx`/`.xlsm`, `.xls` and `.docx`.
**Not measured** for PDFs (rows carry no row number and the page text is
de-duplicated against the tables) and for anything a model read: the reader's
claims are checked against the file by `attachment_validate`, but which source rows
it passed over is not known. Unmeasured coverage is reported as such and never as
complete (`ExtractionStatePolicy`: `partial`).
"""

from __future__ import annotations

import csv
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any, Iterable

from .file_parser import clean_cell, looks_like_header, product_code_continuation
from .quote_readiness import exclusion_reason

MEASURED_SUFFIXES = frozenset({".txt", ".csv", ".xlsx", ".xlsm", ".xls", ".docx"})

SKIP_HEADER = "column header row"
SKIP_SECTION = "section heading or note, carried as context for the rows below it"
SKIP_DOCX_HEADING = "short heading, carried as context for the paragraphs below it"
SKIP_HIDDEN = "hidden sheet"
SKIP_OUTSIDE_MAPPING = "outside the column mapping the table-shape model chose"
SKIP_ZERO = "the customer asked for zero of this line"

# How many skipped and unaccounted rows an answer lists by name. The counts are always
# exact; the lists are for a person reading them, and a 5,000-row sheet of notes is not.
MAX_LISTED_ROWS = 200


@dataclass
class Row:
    """One non-blank input row. `key` is (file name, sheet or "", row number)."""

    key: tuple[str, str, int]
    kind: str  # "line", "skipped" or "continuation"
    reason: str = ""
    anchor: tuple[str, str, int] | None = None  # continuation: the line it joined
    count: int = 1  # a hidden sheet is one entry standing for all its rows


@dataclass
class Coverage:
    input_rows: int = 0
    kept: int = 0
    excluded: int = 0
    skipped: int = 0
    measured: bool = True
    skipped_rows: list[dict[str, Any]] = field(default_factory=list)
    unaccounted_rows: list[dict[str, Any]] = field(default_factory=list)

    def add(self, other: "Coverage") -> None:
        self.input_rows += other.input_rows
        self.kept += other.kept
        self.excluded += other.excluded
        self.skipped += other.skipped
        self.measured = self.measured and other.measured
        self.skipped_rows = (self.skipped_rows + other.skipped_rows)[:MAX_LISTED_ROWS]
        self.unaccounted_rows = (self.unaccounted_rows + other.unaccounted_rows)[:MAX_LISTED_ROWS]

    def to_contract(self) -> dict[str, Any]:
        """`rfq-parser.v1` `coverage`. The four counts are what `ParseResponse` reads;
        `measured` and the two row lists say why they do or do not balance."""
        return {
            "inputRows": self.input_rows,
            "keptRows": self.kept,
            "excludedRows": self.excluded,
            "skippedRows": self.skipped,
            "measured": self.measured,
            "skipped": self.skipped_rows,
            "unaccounted": self.unaccounted_rows,
        }


def unmeasured() -> Coverage:
    return Coverage(measured=False)


# --- walking the input ----------------------------------------------------------------


def tabular_rows(rows: list[list[str]], file_name: str, sheet: str, item_rows: set[int], *, mapped: bool = False) -> list[Row]:
    """`file_parser._tabular_items`' decisions for the rows that produced no item.

    `mapped` is a sheet the table-shape model re-read with its own headers: there the
    parser's header detection is off, so a row that produced nothing is outside the
    mapping rather than something this walk can reconstruct.
    """
    out: list[Row] = []
    headers: list[str] = []
    last_line: tuple[str, str, int] | None = None
    for idx, row_values in enumerate(rows, start=1):
        cells = [value for value in row_values if value]
        if not cells:
            continue
        key = (file_name, sheet, idx)
        if idx in item_rows:
            out.append(Row(key, "line"))
            last_line = key
            continue
        if mapped:
            out.append(Row(key, "skipped", SKIP_OUTSIDE_MAPPING))
            continue
        # The parser's order: header, then continuation code, then section label.
        if looks_like_header(cells):
            headers = row_values
            out.append(Row(key, "skipped", SKIP_HEADER))
            continue
        if headers and last_line is not None and product_code_continuation(row_values, headers):
            out.append(Row(key, "continuation", anchor=last_line))
            continue
        spanning_label = len(cells) > 1 and len(set(cells)) == 1
        if spanning_label or (headers and len(cells) == 1):
            out.append(Row(key, "skipped", SKIP_SECTION))
            continue
        # The parser emits at least one item for every other row, so reaching here with
        # none is drift between the walk and the parse.
        out.append(Row(key, "line"))
        last_line = key
    return out


def _item_rows(items: Iterable[dict[str, Any]], sheet: str | None) -> set[int]:
    rows: set[int] = set()
    for item in items:
        if (item.get("sheet") or "") != (sheet or ""):
            continue
        number = item.get("row_number")
        if isinstance(number, int):
            rows.add(number)
    return rows


def _mapped_sheets(items: Iterable[dict[str, Any]]) -> set[str]:
    return {
        str(item.get("sheet") or "")
        for item in items
        if (item.get("metadata") or {}).get("source_table_shape") == "llm_column_mapping"
    }


def file_rows(path: Path, file_name: str, parsed: list[dict[str, Any]]) -> list[Row] | None:
    """Every non-blank input row of one file, or None when the format is not measured.

    `parsed` is what `parse_file` returned for `path`, before it became work items.
    """
    suffix = path.suffix.lower()
    if suffix not in MEASURED_SUFFIXES:
        return None
    mapped = _mapped_sheets(parsed)
    if suffix in {".xlsx", ".xlsm"}:
        from openpyxl import load_workbook

        out: list[Row] = []
        workbook = load_workbook(path, data_only=True, read_only=True)
        try:
            for sheet in workbook.worksheets:
                rows = [[clean_cell(value) for value in row] for row in sheet.iter_rows(values_only=True)]
                if sheet.sheet_state != "visible":
                    out.extend(_hidden(rows, file_name, sheet.title))
                    continue
                out.extend(tabular_rows(rows, file_name, sheet.title, _item_rows(parsed, sheet.title), mapped=sheet.title in mapped))
        finally:
            workbook.close()
        return out
    if suffix == ".xls":
        import xlrd

        from .xls_convert import xls_cell_value

        out = []
        workbook = xlrd.open_workbook(str(path))
        for sheet in workbook.sheets():
            rows = [
                [clean_cell(xls_cell_value(sheet.cell(r, c), workbook.datemode)) for c in range(sheet.ncols)]
                for r in range(sheet.nrows)
            ]
            if sheet.visibility != 0:
                out.extend(_hidden(rows, file_name, sheet.name))
                continue
            out.extend(tabular_rows(rows, file_name, sheet.name, _item_rows(parsed, sheet.name)))
        return out
    if suffix == ".csv":
        with path.open("r", newline="", encoding="utf-8-sig", errors="replace") as handle:
            rows = [[clean_cell(cell) for cell in row] for row in csv.reader(handle)]
        return tabular_rows(rows, file_name, "", _item_rows(parsed, None))
    if suffix == ".docx":
        return _docx_rows(path, file_name, _item_rows(parsed, None))
    return _text_rows(path.read_text(encoding="utf-8", errors="replace"), file_name, len(parsed))


def _hidden(rows: list[list[str]], file_name: str, sheet: str) -> list[Row]:
    count = sum(1 for row in rows if any(row))
    return [Row((file_name, sheet, 0), "skipped", SKIP_HIDDEN, count=count)] if count else []


def _docx_rows(path: Path, file_name: str, item_rows: set[int]) -> list[Row]:
    """`parse_docx`: paragraphs, then every table's rows, numbered in one sequence."""
    from docx import Document

    document = Document(path)
    out: list[Row] = []
    number = 0
    for paragraph in document.paragraphs:
        if not clean_cell(paragraph.text):
            continue
        number += 1
        key = (file_name, "", number)
        out.append(Row(key, "line") if number in item_rows else Row(key, "skipped", SKIP_DOCX_HEADING))
    for table in document.tables:
        for row in table.rows:
            if not [clean_cell(cell.text) for cell in row.cells if clean_cell(cell.text)]:
                continue
            number += 1
            key = (file_name, "", number)
            out.append(Row(key, "line") if number in item_rows else Row(key, "skipped", SKIP_HEADER))
    return out


def _text_rows(text: str, file_name: str, items: int) -> list[Row]:
    """Pasted text and `.txt`: every non-blank line is input. `pair_schedule_lines`
    drops nothing, but folds a circuit label or a bare quantity into its neighbour, so
    the `items` lines it returns stand for all of them. Which line a folded one joined
    is not recorded, so the folded lines are counted together: kept when any line is
    kept, excluded when every line is (see `balance`)."""
    lines = [line for line in text.replace("\r\n", "\n").split("\n") if line.strip()]
    out = [Row((file_name, "", n), "line") for n in range(1, min(items, len(lines)) + 1)]
    folded = len(lines) - len(out)
    if folded > 0:
        out.append(Row((file_name, "", 0), "continuation", count=folded))
    return out


def text_rows(text: str, items: int) -> list[Row]:
    return _text_rows(text, "", items)


# --- balancing against the matched results --------------------------------------------


def _result_key(result: dict[str, Any], default_file: str) -> tuple[str, str, int] | None:
    source = result.get("source") or {}
    number = source.get("row_number", source.get("line_number"))
    if not isinstance(number, int):
        return None
    return (str(source.get("file_name") or default_file), str(source.get("sheet") or ""), number)


def _explicit_zero(items: list[dict[str, Any]]) -> bool:
    """Whether `match_items` returned nothing for these work items because every entry
    asked for zero (`pipeline.has_explicit_zero_length`, the one place a matched entry
    is dropped). Re-run deterministically, with the model off: `match_source_line` then
    returns an empty list for that reason and no other."""
    import copy

    from . import pipeline

    if not items:
        return False
    for item in items:
        found = pipeline.match_source_line(
            item.get("text") or "",
            copy.deepcopy(item.get("source") or {}),
            context=copy.deepcopy(item.get("context") or []),
            metadata=copy.deepcopy(item.get("metadata") or {}),
            allow_llm_fallback=False,
        )
        if found:
            return False
    return True


def balance(
    rows: list[Row] | None,
    results: list[dict[str, Any]],
    *,
    items: list[dict[str, Any]] | None = None,
    default_file: str = "",
) -> Coverage:
    """Counts for `rows` against the results `match_items` returned for them.

    `items` are the work items that went into `match_items`; a line row with no result
    is checked against them for an explicit zero before it is called unaccounted.
    """
    if rows is None:
        return unmeasured()
    by_key: dict[tuple[str, str, int], list[dict[str, Any]]] = {}
    for result in results:
        key = _result_key(result, default_file)
        if key is not None:
            by_key.setdefault(key, []).append(result)
    items_by_key: dict[tuple[str, str, int], list[dict[str, Any]]] = {}
    for item in items or []:
        key = _result_key(item, default_file)
        if key is not None:
            items_by_key.setdefault(key, []).append(item)

    disposition: dict[tuple[str, str, int], str] = {}
    coverage = Coverage()
    for row in rows:
        coverage.input_rows += row.count
        if row.kind == "skipped":
            coverage.skipped += row.count
            _listed(coverage.skipped_rows, row, row.reason)
            continue
        if row.kind == "continuation":
            if row.anchor is not None:
                outcome = disposition.get(row.anchor)
            else:
                seen = [d for d in disposition.values() if d]
                outcome = "kept" if "kept" in seen else ("excluded" if seen else None)
        else:
            answered = by_key.get(row.key)
            if not answered and _explicit_zero(items_by_key.get(row.key) or []):
                coverage.skipped += row.count
                _listed(coverage.skipped_rows, row, SKIP_ZERO)
                disposition[row.key] = "excluded"
                continue
            outcome = None if not answered else ("excluded" if all(exclusion_reason(r) for r in answered) else "kept")
            disposition[row.key] = outcome or ""
        if outcome == "kept":
            coverage.kept += row.count
        elif outcome == "excluded":
            coverage.excluded += row.count
        else:
            _listed(coverage.unaccounted_rows, row, "no quote line came from this row")
    return coverage


def _listed(target: list[dict[str, Any]], row: Row, reason: str) -> None:
    if len(target) >= MAX_LISTED_ROWS:
        return
    file_name, sheet, number = row.key
    entry: dict[str, Any] = {"fileName": file_name or None, "sheet": sheet or None, "row": number or None, "reason": reason}
    if row.count != 1:
        entry["rows"] = row.count
    target.append(entry)
