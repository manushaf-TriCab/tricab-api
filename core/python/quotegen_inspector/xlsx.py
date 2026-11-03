"""XLSX / XLS inspection. Macros are never run and external links never followed."""
import datetime as dt
import io

from openpyxl import load_workbook
from openpyxl.utils import get_column_letter, range_boundaries

from .limits import MAX_CELLS_PER_READ, MAX_ITEMS


def _json(v):
    if isinstance(v, (dt.datetime, dt.date, dt.time)):
        return v.isoformat()
    if isinstance(v, (int, float, str, bool)) or v is None:
        return v
    return str(v)


def _colour(c):
    if c is None:
        return None
    if getattr(c, "type", None) == "rgb" and isinstance(c.rgb, str):
        return c.rgb
    if getattr(c, "type", None) == "theme":
        return f"theme:{c.theme}"
    if getattr(c, "type", None) == "indexed":
        return f"indexed:{c.indexed}"
    return None


def _open(path):
    # keep_links=False: external workbook links are dropped, never fetched.
    # Stored files have no extension (generated names), and openpyxl judges by
    # extension for paths, so read through a file object.
    with open(path, "rb") as f:
        buf = io.BytesIO(f.read())
    wf = load_workbook(buf, data_only=False, keep_links=False)
    wv = load_workbook(io.BytesIO(buf.getvalue()), data_only=True, keep_links=False)
    return wf, wv


def xlsx_overview(path):
    wf, wv = _open(path)
    sheets, unreadable = [], []
    for ws in wf.worksheets:
        try:
            hidden_rows = [r for r, d in ws.row_dimensions.items() if d.hidden]
            hidden_cols = [c for c, d in ws.column_dimensions.items() if d.hidden]
            comments = sum(1 for row in ws.iter_rows() for c in row if c.comment)
            sheets.append({
                "name": ws.title, "state": ws.sheet_state, "usedRange": ws.dimensions,
                "maxRow": ws.max_row, "maxColumn": ws.max_column,
                "mergedCells": [str(r) for r in ws.merged_cells.ranges][:MAX_ITEMS],
                "mergedCellsTruncated": len(ws.merged_cells.ranges) > MAX_ITEMS,
                "hiddenRows": hidden_rows[:MAX_ITEMS], "hiddenColumns": hidden_cols[:MAX_ITEMS],
                "commentCount": comments, "images": len(getattr(ws, "_images", [])),
                "charts": len(getattr(ws, "_charts", [])),
            })
        except Exception as e:
            unreadable.append({"sheet": ws.title, "reason": str(e)})
    return {"format": "xlsx", "sheets": sheets, "definedNames": list(wf.defined_names.keys())[:MAX_ITEMS],
            "hasVba": bool(getattr(wf, "vba_archive", None)), "truncated": False, "next_cursor": None,
            "unreadable": unreadable}


def xlsx_read(path, sheet, cell_range=None, cursor=None, max_cells=None):
    """Bounded read of a range, row-major. Each cell has formula AND cached value."""
    limit = min(max_cells or MAX_CELLS_PER_READ, MAX_CELLS_PER_READ)
    wf, wv = _open(path)
    if sheet not in wf.sheetnames:
        return {"error": f"no sheet {sheet!r}", "sheets": wf.sheetnames, "cells": [], "truncated": False,
                "next_cursor": None, "unreadable": [{"sheet": sheet, "reason": "not found"}]}
    ws, wsv = wf[sheet], wv[sheet]
    rng = cell_range or ws.dimensions
    min_c, min_r, max_c, max_r = range_boundaries(rng)
    min_c, min_r = min_c or 1, min_r or 1
    max_c, max_r = max_c or ws.max_column, max_r or ws.max_row
    ncols = max_c - min_c + 1
    total = ncols * (max_r - min_r + 1)
    start = int(cursor or 0)
    cells = []
    for idx in range(start, min(total, start + limit)):
        r, c = min_r + idx // ncols, min_c + idx % ncols
        cell, cv = ws.cell(r, c), wsv.cell(r, c)
        f = cell.value
        is_formula = isinstance(f, str) and f.startswith("=")
        has_style = cell.fill is not None and cell.fill.fill_type
        if f is None and not cell.comment and not has_style:
            continue
        rec = {"ref": f"{get_column_letter(c)}{r}", "value": _json(cv.value)}
        if is_formula:
            rec["formula"] = f
            if cv.value is None:
                rec["note"] = "no cached value (file never recalculated)"
        if has_style:
            rec["fill"] = _colour(cell.fill.fgColor)
        fc = _colour(cell.font.color) if cell.font else None
        if fc:
            rec["fontColor"] = fc
        if cell.font and cell.font.bold:
            rec["bold"] = True
        if cell.comment:
            rec["comment"] = cell.comment.text
        if ws.row_dimensions[r].hidden or ws.column_dimensions[get_column_letter(c)].hidden:
            rec["hidden"] = True
        cells.append(rec)
    end = min(total, start + limit)
    more = end < total
    merged = [str(m) for m in ws.merged_cells.ranges if not (m.max_row < min_r or m.min_row > max_r or m.max_col < min_c or m.min_col > max_c)]
    return {"sheet": sheet, "state": ws.sheet_state, "range": rng, "cells": cells, "merged": merged[:MAX_ITEMS],
            "truncated": more, "next_cursor": str(end) if more else None, "scannedCells": end - start,
            "totalCells": total, "unreadable": []}


def xlsx_images(path):
    """Embedded images (listed, not decoded)."""
    wf, _ = _open(path)
    out = []
    for ws in wf.worksheets:
        for im in getattr(ws, "_images", []):
            a = im.anchor._from if hasattr(im.anchor, "_from") else None
            out.append({"sheet": ws.title, "format": getattr(im, "format", None),
                        "anchor": f"{get_column_letter(a.col + 1)}{a.row + 1}" if a else None,
                        "width": im.width, "height": im.height})
    return {"images": out[:MAX_ITEMS], "truncated": len(out) > MAX_ITEMS, "next_cursor": None, "unreadable": []}


def xls_overview(path):
    import xlrd
    book = xlrd.open_workbook(path, formatting_info=True, on_demand=True)
    sheets = []
    for i in range(book.nsheets):
        sh = book.sheet_by_index(i)
        hidden_rows = [r for r, info in sh.rowinfo_map.items() if info.hidden]
        hidden_cols = [c for c, info in sh.colinfo_map.items() if info.hidden]
        sheets.append({"name": sh.name, "state": {0: "visible", 1: "hidden", 2: "veryHidden"}.get(sh.visibility, "visible"),
                       "maxRow": sh.nrows, "maxColumn": sh.ncols,
                       "mergedCells": [f"r{a}-{b}c{c}-{d}" for a, b, c, d in sh.merged_cells][:MAX_ITEMS],
                       "hiddenRows": [r + 1 for r in hidden_rows][:MAX_ITEMS], "hiddenColumns": [c + 1 for c in hidden_cols][:MAX_ITEMS],
                       "commentCount": len(sh.cell_note_map)})
    return {"format": "xls", "sheets": sheets, "truncated": False, "next_cursor": None, "unreadable": [],
            "note": ".xls stores cached values only; formulas are not exposed"}


def xls_read(path, sheet, cell_range=None, cursor=None, max_cells=None):
    import xlrd
    limit = min(max_cells or MAX_CELLS_PER_READ, MAX_CELLS_PER_READ)
    book = xlrd.open_workbook(path, formatting_info=True, on_demand=True)
    if sheet not in book.sheet_names():
        return {"error": f"no sheet {sheet!r}", "sheets": book.sheet_names(), "cells": [], "truncated": False,
                "next_cursor": None, "unreadable": [{"sheet": sheet, "reason": "not found"}]}
    sh = book.sheet_by_name(sheet)
    if cell_range:
        min_c, min_r, max_c, max_r = range_boundaries(cell_range)
    else:
        min_c, min_r, max_c, max_r = 1, 1, max(sh.ncols, 1), max(sh.nrows, 1)
    max_r, max_c = min(max_r, sh.nrows), min(max_c, sh.ncols)
    ncols = max(max_c - min_c + 1, 0)
    total = ncols * max(max_r - min_r + 1, 0)
    start = int(cursor or 0)
    cells = []
    for idx in range(start, min(total, start + limit)):
        r, c = min_r + idx // ncols, min_c + idx % ncols
        cell = sh.cell(r - 1, c - 1)
        if cell.ctype in (xlrd.XL_CELL_EMPTY, xlrd.XL_CELL_BLANK):
            continue
        v = cell.value
        if cell.ctype == xlrd.XL_CELL_DATE:
            v = xlrd.xldate_as_datetime(v, book.datemode).isoformat()
        elif cell.ctype == xlrd.XL_CELL_ERROR:
            v = xlrd.error_text_from_code.get(v, str(v))
        rec = {"ref": f"{get_column_letter(c)}{r}", "value": _json(v)}
        note = sh.cell_note_map.get((r - 1, c - 1))
        if note:
            rec["comment"] = note.text
        cells.append(rec)
    end = min(total, start + limit)
    more = end < total
    return {"sheet": sheet, "range": cell_range or f"A1:{get_column_letter(max_c or 1)}{max_r}", "cells": cells,
            "truncated": more, "next_cursor": str(end) if more else None, "totalCells": total, "unreadable": []}
