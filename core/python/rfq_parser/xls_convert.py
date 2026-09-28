# Generated from the RFQ parser's matcher/xls_convert.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Convert a legacy `.xls` (BIFF) workbook into `.xlsx` (OOXML) bytes.

[PORTAL_TERRA_PARITY_PLAN.md](../docs/PORTAL_TERRA_PARITY_PLAN.md) Phase A. `.xls` was
routed to the reader (`attachment_reader.READER_SUFFIXES`) with two faults that cancelled
each other out into silence:

* the instruction could not succeed -- both the system instructions and the per-request
  prompt tell terra to open the workbook with `openpyxl`, which reads OOXML only, on the
  file type one customer sends most;
* nothing read out of one could ever be substantiated -- §5's re-open-and-check runs on
  `openpyxl` too, so every row came back `0_unvalidatable` and was flagged.

Converting first fixes both halves at once, and it is preferred over teaching terra `xlrd`
because it leaves nothing to reason about: the prompt, the output schema and the
re-open-and-check all stay byte-identical (the reader's prompt is cached, and this does not
touch it), and the thing that gets re-opened is derived from the customer's bytes by our
own deterministic code rather than described by a model.

What is preserved, and why each matters:

* **Cell coordinates, 1:1.** A cell terra names is the same cell in the customer's original
  file, so a reviewer chasing `Sheet!G14` opens the original and finds it.
* **The used range.** openpyxl drops a cell that holds neither value nor formatting on
  save, so the bottom-right corner is anchored deliberately (`_anchor_used_range`).
  Without it the conversion silently shrinks the customer's grid and the coordinate
  guarantee above holds only for cells that happen to be populated.
* **Sheet names**, sanitised only where Excel's own rules force it, and any rename is
  reported by `xls_to_xlsx` so the caller can say so on the draft.
* **Sheet visibility**, mapped rather than filtered. `parse_xls` skips hidden sheets
  because it is deciding what an RFQ contains; a converter is not, and the `.xlsx` reader
  path sees hidden sheets today. Dropping them here would make the two paths disagree
  about what the workbook holds.
* **Merged ranges**, which the reader's own "read the header row, including grouped or
  merged header bands" instruction turns on.

What is lost, and must be said out loud on any draft built from a converted read: **xlrd
surfaces the cached value of a formula cell, not the formula.** The instructions' "read the
workbook twice -- `data_only=False` for formulas, because a formula tells you what the
sheet bills on" heuristic is therefore unavailable here. The read is still cell-grounded;
it is grounded on one reading instead of two. `attachment_reader` records that in `note`.
"""

from __future__ import annotations

import io
import re
from typing import Any, Iterable

XLSX_CONTENT_TYPE = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"

# Excel's own sheet-title rules, which openpyxl enforces on `create_sheet`.
_MAX_SHEET_TITLE = 31
_ILLEGAL_TITLE_CHARS = re.compile(r"[\\/*?:\[\]]")
_FALLBACK_TITLE = "Sheet"

# xlrd's `sheet.visibility` -> openpyxl's `sheet_state`, on the same `0 = visible`
# convention `parse_xls` applies.
_SHEET_STATE = {0: "visible", 1: "hidden", 2: "veryHidden"}


class XlsConversionError(RuntimeError):
    """The `.xls` could not be converted.

    Raised rather than returned so no caller can mistake a failed conversion for an empty
    workbook -- CLAUDE.md: a degraded path records why, and the reader's caller stamps the
    draft.
    """


def xls_cell_value(cell: Any, datemode: int) -> Any:
    """Undo xlrd's everything-is-a-float typing so cells stringify the way
    openpyxl values do (300 not '300.0', real dates not serial numbers).

    Shared with `file_parser.parse_xls`: the deterministic parse of an `.xls` and the
    conversion handed to terra must agree about what a cell holds, or the two readers of
    one file disagree for a reason that has nothing to do with either of them.
    """
    import xlrd

    if cell.ctype == xlrd.XL_CELL_NUMBER:
        return int(cell.value) if cell.value == int(cell.value) else cell.value
    if cell.ctype == xlrd.XL_CELL_DATE:
        try:
            return xlrd.xldate.xldate_as_datetime(cell.value, datemode)
        except Exception:  # noqa: BLE001 - malformed date serial; raw number is still usable
            return cell.value
    if cell.ctype == xlrd.XL_CELL_ERROR:
        return ""
    if cell.ctype == xlrd.XL_CELL_BOOLEAN:
        return bool(cell.value)
    return cell.value


def safe_sheet_titles(names: Iterable[Any]) -> list[str]:
    """Sheet names Excel will accept, in order, one per input name.

    Truncation, illegal-character replacement and de-duplication in one pass, because a
    31-character truncation is what *creates* the collision it then has to resolve. A
    de-duplicating suffix is spent out of the 31, never appended past it.
    """
    titles: list[str] = []
    taken: set[str] = set()
    for name in names:
        base = _ILLEGAL_TITLE_CHARS.sub("_", str(name or "").strip())[:_MAX_SHEET_TITLE].strip()
        title = base or _FALLBACK_TITLE
        if title.casefold() in taken:  # Excel's sheet names are case-insensitive
            for suffix in range(2, len(titles) + 3):
                marker = f"~{suffix}"
                candidate = f"{title[:_MAX_SHEET_TITLE - len(marker)]}{marker}"
                if candidate.casefold() not in taken:
                    title = candidate
                    break
        taken.add(title.casefold())
        titles.append(title)
    return titles


def xls_to_xlsx(data: bytes) -> tuple[bytes, list[str]]:
    """`.xls` bytes -> (`.xlsx` bytes, renamed sheets).

    Each entry of the second element is ``"<original> -> <converted>"`` for a sheet whose
    name Excel's rules would not carry across unchanged. Empty in the ordinary case; when
    it is not, the coordinate guarantee holds for cells but not for sheet names, and the
    caller says so on the draft.
    """
    if not data:
        raise XlsConversionError("the .xls was empty")

    try:
        import xlrd
    except ImportError as exc:  # one message on the draft, not a traceback
        raise XlsConversionError(f"xlrd is not installed: {exc}") from exc
    import openpyxl
    from openpyxl.cell.cell import MergedCell
    from openpyxl.styles import Alignment

    try:
        # `formatting_info=True` is what carries the merged ranges. It is not supported for
        # every BIFF variant, and a workbook whose ranges we cannot see is still worth
        # converting -- so the second open is a real fallback, not a retry.
        book = xlrd.open_workbook(file_contents=data, formatting_info=True)
    except Exception:  # noqa: BLE001 - any refusal, fall back to the plain read
        try:
            book = xlrd.open_workbook(file_contents=data)
        except Exception as exc:  # noqa: BLE001 - not a readable .xls at all
            raise XlsConversionError(f"xlrd could not open the workbook: {type(exc).__name__}: {exc}") from exc

    sheets = book.sheets()
    if not sheets:
        raise XlsConversionError("the workbook has no sheets")

    workbook = openpyxl.Workbook()
    workbook.remove(workbook.active)
    renamed: list[str] = []
    for sheet, title in zip(sheets, safe_sheet_titles(s.name for s in sheets)):
        if title != sheet.name:
            renamed.append(f"{sheet.name} -> {title}")
        worksheet = workbook.create_sheet(title=title)
        worksheet.sheet_state = _SHEET_STATE.get(sheet.visibility, "visible")

        for row_idx in range(sheet.nrows):
            for col_idx in range(sheet.ncols):
                value = xls_cell_value(sheet.cell(row_idx, col_idx), book.datemode)
                # A blank cell and an error cell both arrive as "". Writing that would put
                # an empty string where the sheet has nothing, and §5.5 turns on blank not
                # being a value: leave the cell absent so it re-opens as None.
                if value is None or value == "":
                    continue
                worksheet.cell(row=row_idx + 1, column=col_idx + 1, value=value)

        # After the values: openpyxl blanks every cell of a merged range but the top-left,
        # which is the same thing BIFF does, so nothing is lost by merging second.
        for merged in getattr(sheet, "merged_cells", ()) or ():
            row_lo, row_hi, col_lo, col_hi = merged  # xlrd's bounds are half-open
            try:
                worksheet.merge_cells(
                    start_row=row_lo + 1, start_column=col_lo + 1, end_row=row_hi, end_column=col_hi
                )
            except (ValueError, TypeError):
                continue  # a range the original describes but Excel would not accept

        _anchor_used_range(worksheet, sheet.nrows, sheet.ncols, MergedCell, Alignment)

    buffer = io.BytesIO()
    try:
        workbook.save(buffer)
    except Exception as exc:  # noqa: BLE001 - a value openpyxl refuses to serialise
        raise XlsConversionError(f"the converted workbook could not be written: {type(exc).__name__}: {exc}") from exc
    finally:
        workbook.close()
    return buffer.getvalue(), renamed


def _anchor_used_range(worksheet: Any, rows: int, columns: int, merged_type: type, alignment: Any) -> None:
    """Keep the converted sheet's used range the size the original's was.

    `max_row`/`max_column` are what rule 1 bounds a claimed row against and what rule 2
    uses to decide a cited cell exists, and openpyxl computes both from the cells it
    actually parsed. A cell holding no value is not written out at all -- `ws.cell(5, 7)`
    with nothing in it survives in memory and is gone after a save -- so a sheet whose last
    rows or columns are empty comes back narrower than the customer's file. Giving the
    corner cell a style is enough to make openpyxl write it while leaving its value None,
    which is the one thing that must not change.
    """
    if rows < 1 or columns < 1:
        return
    corner = worksheet.cell(row=rows, column=columns)
    if isinstance(corner, merged_type):
        return  # a merge covering the corner already pins the range
    if corner.value is None:
        corner.alignment = alignment(horizontal="general")
