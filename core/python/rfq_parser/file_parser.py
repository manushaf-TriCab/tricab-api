# Generated from the RFQ parser's matcher/file_parser.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
from __future__ import annotations

import csv
import difflib
from pathlib import Path
import re
from typing import Any

import pdfplumber
from openpyxl import load_workbook

from rfq_parser import schedule_arithmetic
from tricab_cable_rules.accessories import ACCESSORY_CODE_RE
from tricab_cable_rules.extractor import CABLE_SIGNAL_RE, PRODUCT_CODE_RE, pair_schedule_lines
from rfq_parser.reel_quantity import per_reel_metadata
# The xlrd cell-typing rules live with the .xls -> .xlsx conversion the reader uses, so
# the deterministic parse of an .xls and the copy terra reads agree about what a cell
# holds. Two copies of that mapping is the pair that drifts the first time one is fixed.
from rfq_parser.xls_convert import xls_cell_value

# Re-exported: these live in table_units so the pricing path can read quantity
# headers without importing this module, and with it pdfplumber and openpyxl.
from rfq_parser.table_units import (  # noqa: F401
    _COUNT_HEADER_RE,
    _METRE_HEADER_RE,
    _METRE_VALUE_RE,
    is_metre_valued,
    normalize_header,
    unit_from_header,
)


# How many section headings a free-text item carries as context. Deliberately
# shallower than the 4 the sheet paths keep: an email body or txt RFQ has none of
# a spreadsheet's structural discipline, so a heading four sections back is far
# more likely to be stale haystack noise than useful.
BODY_CONTEXT_DEPTH = 2


def parse_file(path: Path) -> list[dict[str, Any]]:
    suffix = path.suffix.lower()
    # .xlsm is the same OOXML openpyxl already reads -- it differs only in carrying a
    # macro part -- and it had a terra route (`attachment_reader.READER_SUFFIXES`) with no
    # deterministic route at all: with the reader off or failing, a macro-enabled workbook
    # returned "Unsupported file type" and the message contributed nothing.
    if suffix in {".xlsx", ".xlsm"}:
        items = parse_xlsx(path)
        # Optional LLM column-mapping pass for sheets the deterministic parser
        # couldn't structure (docs/TABLE_SHAPE_LLM_HANDOUT.md Phase 3). Off by
        # default; a no-op unless RFQ_TABLE_SHAPE_LLM_ENABLED is set.
        from rfq_parser.table_shape import maybe_remap_sheets

        return maybe_remap_sheets(path, items)
    if suffix == ".xls":
        return parse_xls(path)
    if suffix == ".docx":
        return parse_docx(path)
    if suffix == ".pdf":
        return parse_pdf(path)
    if suffix == ".csv":
        return parse_csv(path)
    if suffix == ".txt":
        return parse_text(path)
    return [{"text": f"Unsupported file type: {path.name}", "context": [], "row_number": 1}]


def parse_text(path: Path) -> list[dict[str, Any]]:
    """Plain-text RFQ: one item per paired schedule line, under its section heading.

    Every line still becomes an item (pair_schedule_lines drops nothing) -- the
    heading pass only populates `context`, which was previously always empty here,
    leaving the heading machinery structurally dead on this path. A heading item
    carries the context that preceded it, not itself.
    """
    text = path.read_text(encoding="utf-8", errors="replace")
    items: list[dict[str, Any]] = []
    context: list[str] = []
    for idx, (line_text, reference) in enumerate(pair_schedule_lines(text.splitlines())):
        items.append(
            {
                "text": line_text,
                "context": list(context),
                "metadata": {"reference": reference} if reference else {},
                "row_number": idx + 1,
            }
        )
        if looks_like_section_heading(line_text):
            context = (context + [line_text])[-BODY_CONTEXT_DEPTH:]
    return items


def parse_csv(path: Path) -> list[dict[str, Any]]:
    # The same header/role machinery as xlsx (audit fix #8/F8): a clean
    # "Item ID | Part No. | Item Name | Quantity" CSV gets structured items,
    # not raw pipe-joined text.
    with path.open("r", newline="", encoding="utf-8-sig", errors="replace") as handle:
        rows = [[clean_cell(cell) for cell in row] for row in csv.reader(handle)]
    return _tabular_items(rows, sheet_title="")


def parse_xlsx(path: Path) -> list[dict[str, Any]]:
    workbook = load_workbook(path, data_only=True, read_only=True)
    items: list[dict[str, Any]] = []
    for sheet in workbook.worksheets:
        # Skip sheets the author hid (hidden/veryHidden). These are typically
        # stale master schedules or working tabs the customer deliberately kept
        # out of the visible order, so treating them as RFQ content is wrong.
        if sheet.sheet_state != "visible":
            continue
        rows = [
            [clean_cell(value) for value in row]
            for row in sheet.iter_rows(values_only=True)
        ]
        items.extend(_tabular_items(rows, sheet_title=sheet.title))
    workbook.close()
    return items


def parse_xls(path: Path) -> list[dict[str, Any]]:
    # xlrd is imported lazily so an environment without it only loses .xls
    # support (the per-attachment try/except turns the ImportError into a
    # "could not be parsed" note) instead of breaking every import of this module.
    import xlrd

    workbook = xlrd.open_workbook(str(path))
    items: list[dict[str, Any]] = []
    for sheet in workbook.sheets():
        # 0 = visible; 1/2 = hidden/very hidden, same policy as parse_xlsx.
        if sheet.visibility != 0:
            continue
        rows = [
            [clean_cell(xls_cell_value(sheet.cell(row_idx, col_idx), workbook.datemode)) for col_idx in range(sheet.ncols)]
            for row_idx in range(sheet.nrows)
        ]
        items.extend(_tabular_items(rows, sheet_title=sheet.name))
    return items


def _tabular_items(
    rows: list[list[str]],
    sheet_title: str = "",
    forced_headers: list[str] | None = None,
    first_data_row: int = 1,
) -> list[dict[str, Any]]:
    """Turn one sheet's (or CSV's) cleaned rows into work items.

    Shared by parse_xlsx, parse_csv, and the LLM table-shape layer: the latter
    passes ``forced_headers`` (synthetic role-named headers from a validated
    column mapping) plus ``first_data_row``, in which case in-sheet header
    detection is disabled and every row is read against the given mapping.
    """
    items: list[dict[str, Any]] = []
    base_context = [sheet_title] if sheet_title else []
    context: list[str] = list(base_context)
    headers: list[str] = list(forced_headers or [])
    decomposed_cols = decomposed_schedule_columns(headers) if headers else {}
    price_cols = price_column_indexes(headers) if headers else set()
    construction = is_construction_schedule(headers) if headers else False
    last_main_token = ""
    last_main_markers: list[str] = []

    for idx, row_values in enumerate(rows, start=1):
        if idx < first_data_row:
            continue
        cells = [value for value in row_values if value]
        if not cells:
            continue
        text = " | ".join(cells)
        if forced_headers is None and looks_like_header(cells):
            headers = row_values
            decomposed_cols = decomposed_schedule_columns(headers)
            price_cols = price_column_indexes(headers)
            construction = is_construction_schedule(headers)
            # Keep any section banner that arrived BEFORE this header row. A
            # material banner ("Copper", then later "Aluminium") routinely sits
            # above the header, and it is the only thing distinguishing the two
            # halves of the table; replacing context outright would drop it.
            # Empty in the ordinary case, so this is a no-op for tables whose
            # header is the first row.
            carried = [entry for entry in context if entry not in base_context]
            context = (base_context + carried + [text])[-4:]
            continue
        if headers:
            # A row carrying only a catalog code (description/qty columns blank)
            # is a continuation of the cable above, not a new line -- attach its
            # code there instead of dropping it into context as a section note.
            continuation_code = product_code_continuation(row_values, headers)
            if continuation_code and items:
                attach_product_code(items[-1], continuation_code)
                continue
        # A section header written as ONE cell spanning the table arrives here
        # already expanded into N copies of itself -- colspan in an email table
        # (_expand_table_spans), a merged range in xlsx. MacKillop's "Copper" /
        # "Aluminium" material headers became quote lines that way, resolving to
        # XL-PAXN/1C10OR and XL-PAXA/1C1000BK out of a row saying nothing but
        # "CU | CU | CU | ..." (docs/EMAIL_CORPUS_AUDIT.md 4.3). A real data row
        # cannot repeat one value across every populated column -- name, size
        # and length would all have to coincide.
        #
        # Unlike the lone-cell rule below this does NOT require headers to be
        # established: a material/section banner routinely sits ABOVE the header
        # row, which is exactly where MacKillop's "Copper" was. A single cell
        # with no headers stays ambiguous and is left alone.
        spanning_label = len(cells) > 1 and len(set(cells)) == 1
        if spanning_label or (headers and len(cells) == 1):
            # A lone-cell row under an established header ("CONTROLS Cable
            # Types", "VSD CABLES", "Please Refer to Cable Note Below") is a
            # section label/note, not an RFQ line -- it has no quantity, so
            # there's nothing to quote. Carry it forward as context for the
            # rows that follow instead of emitting it as its own unmatchable
            # item; the AI normalizer reads context when a row's spec (e.g.
            # a bare "1.5mm2") is too sparse to resolve on its own.
            context = (context + [cells[0]])[-4:]
            continue
        if headers and construction:
            # Decomposed construction schedule (Active/Neutral/Earth/Configuration
            # columns): rebuild each row into clean per-conductor items, exactly
            # as the PDF path does. The raw pipe-join otherwise leaks the EARTH
            # cell into the main cable's text (matching an earth product for the
            # active run) and loses the earth as its own line.
            built = build_construction_schedule_items(row_values, headers, context)
            if built:
                reference = str((built[0].get("metadata") or {}).get("reference") or "")
                ref_token = reference.split()[0] if reference.split() else ""
                earth_only = all(item["text"].lower().endswith("earth") for item in built)
                for item in built:
                    item["context"] = (context + (item.get("context") or []))[-4:]
                    item["row_number"] = idx
                    if sheet_title:
                        item["sheet"] = sheet_title
                if earth_only:
                    # An earth on its own row belongs to the cable row above it
                    # (same reference token: "BT-1 EARTH" under "BT-1 FIRE
                    # RATED"). A paired earth is LP unless its cable is fire-rated,
                    # so propagate the relationship / FIRE RATED signal
                    # the same way earth_inherited_markers does for same-line
                    # earths -- without it these resolve to the standard earth.
                    if ref_token and ref_token == last_main_token and last_main_markers:
                        for item in built:
                            item["context"] = (item["context"] + last_main_markers)[-4:]
                else:
                    # A separate earth emitted beside its mains on the same row is not
                    # standalone. Preserve that relationship after the row becomes two
                    # resolver calls, so a small ordinary earth stays on LP.
                    for item in built:
                        if item["text"].lower().endswith("earth"):
                            item["context"] = (item["context"] + ["PAIRED MAINS CABLE"])[-4:]
                    last_main_token = ref_token
                    last_main_markers = _sibling_heat_markers(reference, [item["text"] for item in built])
                items.extend(built)
                continue
        if headers:
            active_earth_items = build_active_earth_summary_items(row_values, headers)
            if active_earth_items:
                if len(active_earth_items) > 1:
                    active_earth_items[-1]["context"] = ["PAIRED MAINS CABLE"]
                for item in active_earth_items:
                    _finish_item(item, context, sheet_title, idx)
                items.extend(active_earth_items)
                continue
            if decomposed_cols:
                built = build_decomposed_schedule_items(row_values, headers, decomposed_cols)
                if built:
                    if len(built) > 1 and built[-1]["text"].lower().endswith("earth"):
                        built[-1]["context"] = ["PAIRED MAINS CABLE"]
                    for item in built:
                        _finish_item(item, context, sheet_title, idx)
                    items.extend(built)
                    continue
            structured = build_structured_xlsx_item(row_values, headers)
            if structured:
                _finish_item(structured, context, sheet_title, idx)
                items.append(structured)
                continue
        if headers and price_cols:
            spec_cells = [
                value for col, value in enumerate(row_values) if value and col not in price_cols
            ]
            if spec_cells:
                text = " | ".join(spec_cells)
        item: dict[str, Any] = {"text": text}
        _finish_item(item, context, sheet_title, idx)
        if headers:
            metadata = schedule_row_metadata(row_values, headers)
            if metadata:
                item["metadata"] = metadata
        items.append(item)

    # Sheets with no cable signal at all (supplier questionnaires, legal tabs,
    # #REF!-ridden costing sheets) still parse, but are flagged so downstream
    # stages (AI normalization, the table-shape LLM router) skip them rather
    # than treating ~500 junk rows as candidate cable lines (audit fix #8).
    if items and not _has_cable_signal(items):
        for item in items:
            item.setdefault("metadata", {})["low_relevance_sheet"] = True
    return items


def _finish_item(item: dict[str, Any], context: list[str], sheet_title: str, row_number: int) -> None:
    item["context"] = (context + (item.get("context") or []))[-4:]
    item["row_number"] = row_number
    if sheet_title:
        item["sheet"] = sheet_title


def _has_cable_signal(items: list[dict[str, Any]]) -> bool:
    for item in items:
        metadata = item.get("metadata") or {}
        if metadata.get("table_quantity") not in (None, "") or metadata.get("product_code"):
            return True
        text = str(item.get("text") or "")
        if CABLE_SIGNAL_RE.search(text) or PRODUCT_CODE_RE.search(text.upper()):
            return True
    return False


def _quantity_basis_from_header(header: str) -> str:
    """Label the selected source value before it leaves the file parser."""
    return "total" if re.search(r"\b(?:total|qty|quantity)\b", normalize_header(header or "")) else "per_cut"


def schedule_row_metadata(row_values: list[str], headers: list[str]) -> dict[str, Any]:
    """Metadata for a row that has no description cell to structure on.

    Decomposed schedules (PowerCAD exports, consultant cable schedules) spread
    the spec across cores/size/insulation columns -- either the sheet has no
    description column at all, or it has one that most rows leave blank. Either
    way build_structured_xlsx_item returns None and the row falls back to raw
    joined text for the normalizer to read. But the header still names the
    length column ("Cable Length (m)"), which is trustworthy on its own -- and
    setting table_quantity here stops the pipeline's trailing-number heuristic
    from recovering whatever figure happens to sit in the row's last column (a
    voltage-drop % or let-through energy) as the line's length."""
    pairs = [
        (header, row_values[index] if index < len(row_values) else "")
        for index, header in enumerate(headers)
        if header
    ]
    pairs = [(header, value) for header, value in pairs if value]
    quantity_header, quantity, derived_cuts = select_quantity(pairs)
    quantity, cell_cuts = split_quantity_cell(quantity)
    number = parse_number(quantity) if quantity else None
    # An explicit zero would silently drop the whole line downstream
    # (has_explicit_zero_length); leave those rows raw instead.
    if not number:
        return {}

    metadata: dict[str, Any] = {
        "table_quantity": number,
        "table_quantity_raw": quantity,
        "table_quantity_header": quantity_header,
        "quantity_basis": _quantity_basis_from_header(quantity_header),
    }
    unit = first_by_role(pairs, "unit") or unit_from_header(quantity_header)
    if unit:
        metadata["table_unit"] = unit
    for role in ("reference", "from", "to", "product_code"):
        value = first_by_role(pairs, role)
        if value:
            metadata[role] = value
    cut_count = parse_number(first_by_role(pairs, "cuts")) or derived_cuts or cell_cuts
    if cut_count:
        metadata["number_of_lengths"] = cut_count
    return metadata


_ACTIVE_EARTH_SIZE_RE = re.compile(
    r"(\d+(?:\.\d+)?)\s*(?:mm(?:2|\u00b2)?|mrn)?\b",
    re.I,
)
_ACTIVE_EARTH_1C_RE = re.compile(
    r"(?P<prefix>(?:\d+\s*[x\u00d7]\s*)*)1\s*c(?:ore)?\b\s*"
    r"(?P<size>\d+(?:\.\d+)?)\s*(?:mm(?:2|\u00b2)?|mrn)?",
    re.I,
)
_ACTIVE_EARTH_MULTIPLIER_RE = re.compile(r"(\d+)\s*[x\u00d7]", re.I)


def build_active_earth_summary_items(
    row_values: list[str], headers: list[str]
) -> list[dict[str, Any]]:
    """Split active/earth summary rows into separate quoteable cables.

    Some RFQs give total active conductor length and total earth length on the
    same row (e.g. ``4x1C 70mm`` plus ``25mm`` earth). The generic structured
    parser would emit only the active cable because the earth cells are not
    description columns. This table-shape rule turns that row into one active
    item and, when earth length/size is present, one earth item.
    """
    columns = active_earth_summary_columns(headers)
    if not columns:
        return []

    active_size_text = row_value(row_values, columns["cable_size"])
    active_spec = parse_active_earth_single_core_spec(active_size_text)
    if not active_spec:
        return []
    active_runs, earth_runs, active_size = active_spec

    active_length_header = headers[columns["active_length"]]
    active_length_raw = row_value(row_values, columns["active_length"])
    active_total = parse_number(active_length_raw)
    if active_total in ("", None):
        return []

    cable_type = row_value(row_values, columns.get("cable_type"))
    active_description = " ".join(part for part in (cable_type, f"1C {active_size}mm") if part)
    items = [
        {
            "text": active_description,
            "metadata": active_earth_summary_metadata(
                active_length_raw,
                active_total,
                active_length_header,
                active_runs,
            ),
        }
    ]

    earth_size_header_index = columns.get("earth_size")
    earth_length_header_index = columns.get("earth_length")
    earth_size = summary_size(row_value(row_values, earth_size_header_index))
    earth_length_raw = row_value(row_values, earth_length_header_index)
    earth_total = parse_number(earth_length_raw)
    if earth_size and earth_total not in ("", None) and earth_length_header_index is not None:
        items.append(
            {
                "text": f"1C {earth_size}mm earth",
                "metadata": active_earth_summary_metadata(
                    earth_length_raw,
                    earth_total,
                    headers[earth_length_header_index],
                    earth_runs,
                ),
            }
        )
    return items


def active_earth_summary_columns(headers: list[str]) -> dict[str, int]:
    columns: dict[str, int] = {}
    for index, header in enumerate(headers):
        value = normalize_header(header)
        if not value:
            continue
        if "earth" in value and "length" in value:
            columns.setdefault("earth_length", index)
            continue
        if "active" in value and "length" in value:
            columns.setdefault("active_length", index)
            continue
        if "earth" in value and ("size" in value or "csa" in value):
            columns.setdefault("earth_size", index)
            continue
        if (
            ("cable" in value or "active" in value or "phase" in value)
            and ("size" in value or "csa" in value)
        ):
            columns.setdefault("cable_size", index)
            continue
        if value in {"type", "cable type", "cable description"}:
            columns.setdefault("cable_type", index)

    required = {"active_length", "earth_length", "cable_size", "earth_size"}
    return columns if required <= columns.keys() else {}


def parse_active_earth_single_core_spec(value: str) -> tuple[int, int, str] | None:
    text = str(value or "").replace("mrn", "mm").replace("MRN", "MM")
    match = _ACTIVE_EARTH_1C_RE.search(text)
    if not match:
        return None

    multipliers = [
        int(number)
        for number in _ACTIVE_EARTH_MULTIPLIER_RE.findall(match.group("prefix") or "")
    ]
    active_runs = 1
    for multiplier in multipliers:
        active_runs *= multiplier

    earth_runs = 1
    if len(multipliers) > 1:
        for multiplier in multipliers[:-1]:
            earth_runs *= multiplier

    return active_runs, earth_runs, format_numeric_text(match.group("size"))


def summary_size(value: str) -> str:
    text = str(value or "").replace("mrn", "mm").replace("MRN", "MM")
    match = _ACTIVE_EARTH_SIZE_RE.search(text)
    return format_numeric_text(match.group(1)) if match else ""


def active_earth_summary_metadata(
    total_raw: str,
    total_value: float | int,
    header: str,
    runs: int,
) -> dict[str, Any]:
    quantity = split_total_length(total_value, runs)
    metadata: dict[str, Any] = {
        "table_quantity": quantity,
        "table_quantity_raw": total_raw,
        "table_quantity_header": header,
        "quantity_basis": "per_cut",
        "table_unit": unit_from_header(header) or "m",
        "number_of_lengths": runs,
        "source_table_shape": "active_earth_summary",
        "source_total_length": total_value,
    }
    return metadata


def split_total_length(total: float | int, runs: int) -> float | int:
    if runs <= 1:
        return total
    quantity = round(float(total) / float(runs), 6)
    return int(quantity) if quantity.is_integer() else quantity


def format_numeric_text(value: str) -> str:
    number = float(value)
    return str(int(number)) if number.is_integer() else str(number).rstrip("0").rstrip(".")


def row_value(row_values: list[str], index: int | None) -> str:
    if index is None or index >= len(row_values):
        return ""
    return row_values[index]


# --- Column-earth recomposition (Type | Size | Earth tables, audit fix #9) -----------
_NO_EARTH_RE = schedule_arithmetic.NO_EARTH_RE
_COLUMN_PARALLEL_1C_RE = re.compile(
    r"(?P<prefix>(?:\d+\s*[x×]\s*)*)(?<![\d.])1\s*c(?:ore)?\b(?:\s*\+\s*\d*\s*e\b)?",
    re.I,
)
_TEXT_SIZE_RE = re.compile(r"(\d+(?:\.\d+)?)\s*(?:sq\.?\s*mm|mm2?\b)", re.I)
_MULTICORE_TOKEN_RE = re.compile(r"(?<![\d.x×])([2-9]|[1-9]\d)\s*c(?:ore)?s?\b", re.I)
# "/" joins the earth as often as "+" or "&" ("4C/E"); all three mean the count
# already includes the earth, so none of them may gain a second "+ EARTH" below.
_EARTH_IN_COUNT_RE = re.compile(r"[+&/]\s*(\d*)\s*E\b", re.I)


def recompose_column_earth(text: str, earth_cell: str) -> str:
    """Fold a separate earth-size column back into the description text.

    ``Type "4x1c+e XLPE X-90" | Size "50mm2" | Earth "16mm2"`` recomposes to the
    canonical inline form ``4x 50mm2 + 1x 16mm2 earth XLPE X-90`` so the proven
    PARALLEL_SINGLE_CORE_RE + AI-split path fires; the ``x1c`` glue that defeats
    the extractor's word boundaries never reaches it. A nested quantity prefix
    (``6x4x1c``) multiplies out the way the active/earth summary shape does:
    actives = product of all multipliers, earths = product of all but the last.
    A plain multicore with no earth marker gains ``+ EARTH`` (the extractor
    promotes it to an extra core); a multicore that already counts its earth
    (``2c+e``) is left alone -- the column is the internal earth's size.
    Returns "" when nothing should change.
    """
    earth_text = str(earth_cell or "")
    esize = summary_size(earth_text)
    if not esize or _NO_EARTH_RE.match(earth_text):
        return ""
    if re.search(r"\bearth\b|\becc\b|\be\s*/\s*w\b", text, re.I):
        return ""
    match = _COLUMN_PARALLEL_1C_RE.search(text)
    if match:
        multipliers = [int(n) for n in re.findall(r"(\d+)\s*[x×]", match.group("prefix") or "")]
        active_count = 1
        for value in multipliers:
            active_count *= value
        earth_count = 1
        for value in multipliers[:-1]:
            earth_count *= value
        remainder = f"{text[: match.start()]} {text[match.end():]}".strip()
        size_match = _TEXT_SIZE_RE.search(remainder)
        if not size_match:
            return ""
        size = format_numeric_text(size_match.group(1))
        remainder = f"{remainder[: size_match.start()]} {remainder[size_match.end():]}"
        remainder = " ".join(remainder.split())
        recomposed = f"{active_count}x {size}mm2 + {earth_count}x {esize}mm2 earth"
        return f"{recomposed} {remainder}".strip()
    if _MULTICORE_TOKEN_RE.search(text) and not _EARTH_IN_COUNT_RE.search(text):
        return f"{text} + EARTH"
    return ""


# --- Decomposed xlsx cable schedules (Cores | Size | Earth Size | Length, fix #4) ----
# PowerCAD exports and consultant schedules spread one cable across attribute
# columns with no free-text description. The PDF path has had a rebuilder for
# the Active/Configuration variant (build_construction_schedule_items); this is
# the xlsx-side equivalent for the Cores-column variant, keeping the same
# strict earth rules: multicore + sized earth -> one NC+E line (the earth is a
# core); single-core/SDI construction or a dedicated earth length/ccts column
# -> a separate 1C earth line.
# One owner for the notation. These were defined here and are now shared with the
# deterministic check that grades what the reader returned -- the active, neutral and earth
# columns must be parsed by identical code on both paths, or a "disagreement" could be two
# spellings of the same rule rather than a real one.
_SIZE_RUNS_RE = schedule_arithmetic.SET_NOTATION_RE
_CORES_SINGLE_RE = schedule_arithmetic.SINGLE_CORE_RE
_CORES_MULTI_RE = schedule_arithmetic.MULTI_CORE_RE
_X1C_EARTHS_RE = re.compile(r"\+\s*(\d+)\s*[x×]\s*e\b", re.I)


def decomposed_schedule_columns(headers: list[str]) -> dict[str, Any]:
    """Column map for a decomposed cable schedule, or {} when the sheet isn't one.

    Requires a size column, a cores column, and at least one length column, and
    bails when a real description column exists (those sheets use the standard
    structured path)."""
    columns: dict[str, Any] = {"lengths": []}
    for index, header in enumerate(headers):
        value = normalize_header(header)
        if not value:
            continue
        role = header_role(header)
        if role == "description":
            return {}
        if role in {"earth_size", "earth_length", "earth_cuts"}:
            columns.setdefault(role, index)
            continue
        if "earth" in value:
            if "conductor" in value:
                columns.setdefault("earth_conductor", index)
            continue  # e.g. "Separate Earth for Multicore" -- not a size/length
        if "neutral" in value and "active" not in value:
            columns.setdefault("neutral_size", index)
            continue
        # "size" is a substring test, so "Cond. Size [mm2]" is found; CSA needs the
        # same tolerance. It used to be an exact `value == "csa"`, which meant the
        # unit suffix real senders write -- "CSA(mm2)", "CSA (mm2)" -- missed, the
        # whole column map came back empty, and an 8-column schedule collapsed to a
        # bare "95mm2" (docs/EMAIL_CORPUS_AUDIT.md 4.1). Word-boundary so it cannot
        # fire inside an unrelated word, and the dotted "C.S.A." form is accepted.
        if ("size" in value or re.search(r"\bc\.?s\.?a\b", value)) and not any(
            word in value for word in ("conduit", "tray", "drum", "diameter", "strand")
        ):
            columns.setdefault("size", index)
            continue
        if re.search(r"\bcores?\b|core count", value):
            columns.setdefault("cores", index)
            continue
        if "insulation" in value:
            columns.setdefault("insulation", index)
            continue
        if "sheath" in value:
            columns.setdefault("sheath", index)
            continue
        if "conductor" in value or value == "material":
            columns.setdefault("conductor", index)
            continue
        if role in {"quantity", "route_length"}:
            # route_length ("Run Length/m") is a per-run length candidate here just
            # like a plain length column; _decomposed_quantity separates per-run
            # from total by the word "total", not by the role.
            columns["lengths"].append(index)
            continue
        if role == "cuts":
            columns.setdefault("cuts", index)
            continue
        if role in {"reference", "from", "to"}:
            columns.setdefault(role, index)
    if "size" not in columns or "cores" not in columns or not columns["lengths"]:
        return {}
    return columns


def _integer_ratio(total: Any, each: Any) -> int | None:
    """total / each when it divides out to a whole cut count > 1, else None."""
    try:
        total, each = float(total), float(each)
    except (TypeError, ValueError):
        return None
    if each <= 0 or total <= 0:
        return None
    ratio = total / each
    if ratio > 1 and abs(ratio - round(ratio)) <= 0.01:
        return int(round(ratio))
    return None


def _decomposed_quantity(
    columns: dict[str, Any], headers: list[str], row_values: list[str], size_runs: int
) -> tuple[Any, int, str, str]:
    """(length_each, cuts, header, raw) for the active side of a decomposed row.

    A labelled total that divides cleanly by a per-run sibling proves the cut
    count (65-Julie: Run 40 x Total 640 -> 16 cuts; 25-Neil: 25 x 300 -> 12);
    otherwise the route length is the per-cut length and any ``N x``/``N SETS
    OF`` prefix on the size cell is the cut count (28-Neil ``2x240`` -> 2 cuts).
    """
    entries = []
    earth_index = columns.get("earth_size")
    for index in columns["lengths"]:
        value = row_value(row_values, index)
        if not value or parse_number(value) is None:
            continue
        header = normalize_header(headers[index]) if index < len(headers) else ""
        is_total = "total" in header
        # A second total-length column sitting past the earth-size column is
        # the earth's own total (25-Neil), not an active candidate.
        if is_total and earth_index is not None and index > earth_index and any(
            other != index and "total" in normalize_header(headers[other])
            for other in columns["lengths"]
        ):
            continue
        entries.append((index, headers[index], value, is_total))
    if not entries:
        return None, size_runs, "", ""
    totals = [entry for entry in entries if entry[3]]
    per_runs = [entry for entry in entries if not entry[3]]
    if totals and per_runs:
        total_value = parse_number(totals[0][2])
        each_value = parse_number(per_runs[0][2])
        cuts = _integer_ratio(total_value, each_value)
        if cuts:
            return each_value, cuts, per_runs[0][1], per_runs[0][2]
    chosen = totals[0] if totals and not per_runs else (per_runs[0] if per_runs else totals[0])
    return parse_number(chosen[2]), size_runs, chosen[1], chosen[2]


def _decomposed_metadata(
    each: Any, cuts: int, header: str, raw: str, reference: str, from_value: str, to_value: str
) -> dict[str, Any]:
    metadata: dict[str, Any] = {}
    if reference:
        metadata["reference"] = reference
    if from_value:
        metadata["from"] = from_value
    if to_value:
        metadata["to"] = to_value
    if each is not None:
        metadata["table_quantity"] = each
        metadata["table_quantity_raw"] = raw
        metadata["table_quantity_header"] = header
        metadata["quantity_basis"] = _quantity_basis_from_header(header)
        metadata["table_unit"] = unit_from_header(header) or "m"
    if cuts and cuts > 1:
        metadata["number_of_lengths"] = cuts
        metadata["parallel_runs"] = cuts
    metadata["source_table_shape"] = "decomposed_schedule"
    return metadata


def build_decomposed_schedule_items(
    row_values: list[str], headers: list[str], columns: dict[str, Any]
) -> list[dict[str, Any]]:
    """Rebuild one decomposed-schedule row into one or two quoteable items."""
    size_cell = row_value(row_values, columns.get("size"))
    size_match = _SIZE_RUNS_RE.match(size_cell)
    cores_cell = row_value(row_values, columns.get("cores"))
    if not size_match or not cores_cell:
        return []  # not a cable row (transformer notes, blank spacers, ...)
    size_runs = int(size_match.group(1) or 1)
    size = format_numeric_text(size_match.group(2))

    material = _cell_material(row_value(row_values, columns.get("conductor"))) or _cell_material(size_cell) or "Cu"
    insulation = row_value(row_values, columns.get("insulation"))
    sheath = row_value(row_values, columns.get("sheath"))
    construction = " ".join(part for part in (material, insulation, sheath) if part)
    reference = row_value(row_values, columns.get("reference"))
    from_value = row_value(row_values, columns.get("from"))
    to_value = row_value(row_values, columns.get("to"))
    flexish = "flex" in cores_cell.lower() or "flex" in insulation.lower()

    earth_cell = row_value(row_values, columns.get("earth_size"))
    earth_match = _SIZE_RUNS_RE.match(earth_cell)
    earth_size = (format_numeric_text(earth_match.group(2)) if earth_match else
                  ("" if _NO_EARTH_RE.match(earth_cell or "") else summary_size(earth_cell)))
    earth_material = _cell_material(row_value(row_values, columns.get("earth_conductor"))) or "Cu"

    each, cuts, length_header, length_raw = _decomposed_quantity(columns, headers, row_values, size_runs)

    single = bool(_CORES_SINGLE_RE.search(cores_cell))
    multi = None if single else _CORES_MULTI_RE.search(cores_cell)
    if not single and not multi:
        bare = parse_number(cores_cell) if re.fullmatch(r"\s*\d+\s*", cores_cell) else None
        if bare == 1:
            single = True
        elif bare and bare > 1:
            multi_cores = int(bare)
        else:
            return []
    if multi:
        multi_cores = int(multi.group(1))

    if single:
        # "4 x 4 x 1C + 3 x E" style cores expressions carry their own counts.
        x1c = _COLUMN_PARALLEL_1C_RE.search(cores_cell)
        multipliers = (
            [int(n) for n in re.findall(r"(\d+)\s*[x×]", x1c.group("prefix") or "")] if x1c else []
        )
        if multipliers:
            expr_runs = 1
            for value in multipliers:
                expr_runs *= value
            if cuts == size_runs:  # no arithmetic-proven cut count; trust the expression
                cuts = expr_runs * size_runs
        # Separate active and neutral columns define this three-phase schedule dialect.
        # Counts in each size cell are per phase / neutral, not total cable counts.
        neutral_match = _SIZE_RUNS_RE.match(row_value(row_values, columns.get("neutral_size")))
        neutral_size = format_numeric_text(neutral_match.group(2)) if neutral_match else None
        neutral_runs = int(neutral_match.group(1) or 1) if neutral_match else 0
        separate_neutral = neutral_match is not None and not multipliers
        if separate_neutral:
            each, cuts, length_header, length_raw = _decomposed_quantity(
                columns, headers, row_values,
                3 * size_runs + (neutral_runs if neutral_size == size else 0),
            )
        sdi = bool(re.search(r"s\.?\s*d\.?\s*i", cores_cell, re.I))
        descriptor = " ".join(
            part
            for part in (f"1C {size}mm2", construction, "Flex" if flexish else "", "SDI" if sdi else "")
            if part
        )
        items = [
            {
                "text": " ".join(descriptor.split()),
                "metadata": _decomposed_metadata(each, cuts, length_header, length_raw, reference, from_value, to_value),
            }
        ]
        if separate_neutral and neutral_size != size:
            neutral_each, neutral_cuts, neutral_header, neutral_raw = _decomposed_quantity(
                columns, headers, row_values, neutral_runs,
            )
            items.append({
                "text": descriptor.replace(f"1C {size}mm2", f"1C {neutral_size}mm2") + " neutral",
                "metadata": _decomposed_metadata(neutral_each, neutral_cuts, neutral_header,
                    neutral_raw, f"{reference} neutral".strip(), from_value, to_value),
            })
        if earth_size:
            explicit_earths = _X1C_EARTHS_RE.search(cores_cell)
            earth_each, earth_cuts = _decomposed_earth_quantity(
                columns,
                headers,
                row_values,
                each,
                default_cuts=(int(earth_match.group(1)) if earth_match and earth_match.group(1) else
                              int(explicit_earths.group(1)) if explicit_earths else None),
            )
            earth_meta = _decomposed_metadata(
                earth_each, earth_cuts, "Earth Length", str(earth_each or ""), f"{reference} earth".strip(), from_value, to_value
            )
            earth_insulation = insulation
            if separate_neutral:
                earth_insulation = ("LS" if re.search(r"\b(?:X-125|R-110)\b", insulation, re.I)
                                    else "PAIRED MAINS CABLE")
                if earth_insulation == "LS":
                    earth_insulation = "LS flex"
            items.append(
                {
                    "text": " ".join(f"1C {earth_size}mm2 {earth_material} {earth_insulation} earth".split()),
                    "metadata": earth_meta,
                }
            )
        return items

    # Keep an explicit earth multiplicity: "3C+3E" is three SPLIT earths, which
    # FIELD_SEMANTICS reads as core_count=4 with earth_multiplicity=3. Collapsing
    # it to a bare "+E" threw the 3 away before anything downstream could see it
    # (docs/EMAIL_CORPUS_AUDIT.md 4.1, item 8).
    #
    # This is not cosmetic. The resolver already routes a symmetric split-earth
    # construction to the XU/KU/EU/EA family, which exists specifically for that
    # config (_vsd_target_style_code); a single earth goes to DP instead. Losing
    # the "3" sent OP18847's X-HF-110 VSD row to DP-PAZN, the 90C style, when the
    # customer had asked for 110C -- so the rule that would have caught it could
    # never fire. The core count is unchanged either way (split earths still
    # collapse to one earth core), so only the style moves, and only onto the
    # temperature the line actually states.
    earth_in_count = _EARTH_IN_COUNT_RE.search(cores_cell)
    earth_multiplicity = (earth_in_count.group(1) if earth_in_count else "") or ""
    if earth_size or earth_in_count:
        cores_expr = f"{multi_cores}C+{earth_multiplicity}E"
    else:
        cores_expr = f"{multi_cores}C"
    descriptor = " ".join(
        part for part in (cores_expr, f"{size}mm2", construction, "Flex" if flexish else "") if part
    )
    metadata = _decomposed_metadata(each, cuts, length_header, length_raw, reference, from_value, to_value)
    if earth_size:
        metadata["integral_earth_size_mm2"] = earth_size
    if earth_size and earth_size != size and not earth_multiplicity:
        # Existing extractor grammar preserves the reduced integral core without splitting.
        descriptor += f" ({multi_cores}x{size}+1x{earth_size}mm2)"
    return [{"text": " ".join(descriptor.split()), "metadata": metadata}]


def _decomposed_earth_quantity(
    columns: dict[str, Any],
    headers: list[str],
    row_values: list[str],
    active_each: Any,
    default_cuts: int | None = None,
) -> tuple[Any, int]:
    """(length_each, cuts) for a separate earth line of a decomposed row.

    Prefers the dedicated earth length column, splitting it by the earth run
    count -- the ccts column (25-Neil: 75 total / 3 ccts -> 3 x 25) or the
    cores expression's explicit ``+ N x E`` (65-Julie) -- and with no earth
    columns at all the earth runs the same route as the active."""
    earth_cuts = parse_number(row_value(row_values, columns.get("earth_cuts"))) or default_cuts or 1
    earth_cuts = int(earth_cuts) if earth_cuts else 1
    earth_length_value = parse_number(row_value(row_values, columns.get("earth_length")))
    if earth_length_value is None:
        # 25-Neil labels the earth's own total "Total Length (m)" (again); it was
        # excluded from the active candidates positionally in _decomposed_quantity.
        earth_index = columns.get("earth_size")
        for index in columns["lengths"]:
            header = normalize_header(headers[index]) if index < len(headers) else ""
            if earth_index is not None and index > earth_index and "total" in header:
                earth_length_value = parse_number(row_value(row_values, index))
                break
    if earth_length_value is not None:
        if earth_cuts > 1 and earth_length_value:
            each = float(earth_length_value) / earth_cuts
            return (int(each) if each.is_integer() else round(each, 6)), earth_cuts
        return earth_length_value, earth_cuts
    return active_each, earth_cuts


def build_structured_xlsx_item(row_values: list[str], headers: list[str]) -> dict[str, Any] | None:
    pairs = []
    for index, header in enumerate(headers):
        if not header:
            continue
        value = row_values[index] if index < len(row_values) else ""
        if value:
            pairs.append((header, value))
    if not pairs:
        return None

    # Colour contributes to the text but is not a description role, so it is collected
    # here rather than through header_role. Only the FIRST colour column counts: a sheet
    # can hold two logical tables side by side -- the live stock order pairs a cable
    # table with a BOOTLACE/FERRULE one and so repeats COLOUR, and taking both appended
    # the ferrule's colour to every cable row ("1mm2 PINK ... RED" -> LS-XXXD/1C1RE).
    # First-wins mirrors first_by_role, which is how this function already resolves the
    # other single-valued columns that sheet repeats (Part No., Supplier, Price).
    description_parts = []
    colour_taken = False
    described = False
    for header, value in pairs:
        if is_colour_header(header):
            if colour_taken:
                continue
            colour_taken = True
            part = fold_description_value(header, value)
        elif header_role(header) in {"description", "extra_description"}:
            part = fold_description_value(header, value)
            described = described or bool(part)
        else:
            continue
        if part:
            description_parts.append(part)
    # A colour on its own does not make a row a cable line -- "RED" in an otherwise
    # empty row is a stray cell, and before colour was read at all such a row fell
    # through to the raw pipe-join. Keep it falling through.
    if not described:
        return None

    # Every column header_role could not name, kept rather than dropped
    # (BODY_TABLE_FIDELITY_HANDOFF.md §7 item 1). This changes no reading on its own --
    # nothing downstream consumes it yet -- and that is the point: it is the record of
    # what the customer wrote and this parser did not understand, which is what lets the
    # system know when it should be asking rather than assuming. On the Cairns schedules
    # this is where Material, Cores and the lug columns went, silently, before the aliases
    # below existed, and it is where the next unrecognised column will go too.
    unmapped = {
        header: value
        for header, value in pairs
        if header_role(header) == "other" and not is_colour_header(header) and value
    }

    ref = first_by_role(pairs, "reference")
    product_code = canonical_code_cell(
        first_by_role(pairs, "product_code") or code_shaped_cell(pairs)
    )
    quantity_header, quantity, derived_cuts = select_quantity(pairs)
    quantity, cell_cuts = split_quantity_cell(quantity)
    unit = first_by_role(pairs, "unit")
    from_value = first_by_role(pairs, "from")
    to_value = first_by_role(pairs, "to")

    metadata: dict[str, Any] = {}
    if unmapped:
        metadata["unmapped_columns"] = unmapped
    if ref:
        metadata["reference"] = ref
    if product_code:
        metadata["product_code"] = product_code
    if from_value:
        metadata["from"] = from_value
    if to_value:
        metadata["to"] = to_value
    reel_meta = per_reel_metadata(
        " ".join(description_parts),
        quantity,
        quantity_header="per reel",
    )
    # A stock order keeps the package size in its own UOM column ("100m ROLL") rather
    # than in the description, so the quantity beside it is a count of rolls and reading
    # it as metres underquotes by the roll size -- 5 against 500. Checked only after the
    # description, which stays the primary source and is unaffected.
    if not reel_meta and unit:
        reel_meta = per_reel_metadata(
            unit, quantity, quantity_header="per reel", allow_juxtaposed=True
        )
    if reel_meta:
        metadata.update(reel_meta)
    elif quantity:
        metadata["table_quantity"] = parse_number(quantity)
        metadata["table_quantity_raw"] = quantity
        metadata["table_quantity_header"] = quantity_header
        metadata["quantity_basis"] = _quantity_basis_from_header(quantity_header)
        detected_unit = unit or unit_from_header(quantity_header)
        if detected_unit:
            metadata["table_unit"] = detected_unit
    if not reel_meta:
        cut_count = parse_number(first_by_role(pairs, "cuts")) or derived_cuts or cell_cuts
        if cut_count:
            metadata["number_of_lengths"] = cut_count

    text = " ".join(description_parts)
    # A sized earth in its own column is invisible in the joined description --
    # recompose it into the inline form the extractor already understands
    # (audit fix #9), so a "Type | Size | Earth" row can split into its phase
    # and earth products downstream.
    earth_cell = first_by_role(pairs, "earth_size")
    if earth_cell:
        recomposed = recompose_column_earth(text, earth_cell)
        if recomposed:
            text = recomposed
            metadata["source_table_shape"] = "column_earth"
    # The conductor metal, folded into the row text as a token the extractor reads. Fed
    # nothing, the resolver assumes copper -- so an aluminium row on a schedule whose
    # Material column said "Al" came back as a copper product, `matched`, at high
    # confidence. Recorded as a typed field too, so a reviewer and the agent can both see
    # it was read from a column rather than inferred from the description.
    material_header, material_raw = first_pair_by_role(pairs, "conductor_material")
    material = canonical_conductor_material(material_raw)
    if material:
        metadata["conductor_material"] = material
        if material not in text.upper().split():
            text = f"{text} {material}".strip()
    elif material_raw:
        # A "Material" column holding something that is not a conductor metal -- a sheath
        # ("PVC", "LSZH"), or a spelling this closed set does not know. Naming the column
        # is not the same as understanding the cell, so it goes back to unmapped_columns
        # rather than being dropped: before this branch existed, claiming the column for
        # the material role made the value LESS visible than leaving the column unmapped.
        metadata.setdefault("unmapped_columns", {})[material_header] = material_raw
    # Fold the customer's own catalog code into the matched text so the resolver's
    # exact-code shortcut uses it directly (see find_exact_product_code) rather than
    # re-deriving a code from the description. The circuit label / reference stays in
    # metadata and becomes the line comment, so it is never mixed into the spec text.
    if product_code and product_code.upper() not in text.upper():
        text = f"{text} {product_code}".strip()

    return {
        "text": text,
        "metadata": metadata,
    }


_CORE_SIZE_HEADERS = {
    "size", "csa", "cable size", "conductor size", "core size",
    "cores size", "cable csa", "conductor csa", "nominal size", "nom size",
}


# A column headed by nothing but its unit -- "mm2", "mm²", "sq mm". Narrow email
# tables routinely label the size column this way and nothing else, so before this
# the whole column was role "other" and dropped: a row reading "2,5mm" in the
# description lost the authoritative 2.5 sitting beside it (DIRECTIVE_CAPTURE_PLAN
# §1.3 row 3). Checked ahead of the quantity branch in header_role, which is safe
# because a real length column reads "Length (mm)" and keeps its root word.
_CSA_UNIT_ONLY_RE = re.compile(r"^(?:sq\.?\s*mm|mm\s*(?:sq|2|²)?)$", re.I)


def is_core_size_header(header: str) -> bool:
    """Whether a column is a bare conductor-CSA column ("Core Size (mm2)").

    Strips a trailing unit ("(mm2)", "mm2", "sq mm") so "Core Size (mm2)" and
    "Size (mm²)" match the same way "Size" does, and accepts a header that is
    only the unit.
    """
    value = normalize_header(header)
    if _CSA_UNIT_ONLY_RE.fullmatch(value):
        return True
    root = re.sub(r"\(.*?\)|mm2|mm²|sq\.?\s*mm", "", value).strip(" .")
    return root in _CORE_SIZE_HEADERS


# Colour kept in its own column, exactly like conductor CSA above. The extractor reads
# colour out of the item TEXT, so a colour that never reaches the text is a colour the
# resolver cannot see: every row of the sheet then collapses onto the style's house
# default. On a live stock order (Mega Electrics, 2026-08-27) that silently turned ten
# distinct 1.5mm2 colours into ten identical lines of green/yellow EARTH cable, all
# reported `matched`. Deliberately NOT a header_role: role feeds header detection,
# quantity selection and the continuation rule, and this only ever needs to change how
# the row's text is assembled.
_COLOUR_HEADERS = {
    "colour", "color", "colours", "colors",
    "cable colour", "cable color", "core colour", "core color",
    "sheath colour", "sheath color", "insulation colour", "insulation color",
    "wire colour", "wire color",
}


def is_colour_header(header: str) -> bool:
    """Whether a column is a bare cable-colour column ("COLOUR", "Core Color")."""
    return normalize_header(header) in _COLOUR_HEADERS


def fold_description_value(header: str, value: str) -> str:
    """A description/extra cell as it should read inside the item text.

    A conductor CSA kept in its own column arrives as a bare number ("240",
    "25"); the extractor only reads it as a size when it carries a unit ("EARTH
    25mm" resolves, "EARTH 25" does not), so re-attach mm2. Non-numeric CSA cells
    ("NA", "-", "N/A" on fibre/patch rows) carry no size and are dropped rather
    than glued onto the description as noise.
    """
    text = str(value or "").strip()
    if is_core_size_header(header):
        # A bare number needs a unit; a value that already carries one ("6MM",
        # "50mm2") passes through untouched. Explicit "no size" placeholders are
        # dropped so they don't glue onto the description.
        if re.fullmatch(r"\d+(?:\.\d+)?", text):
            return f"{text}mm2"
        if text.lower() in {"na", "n/a", "-", "--", "nil", "none"}:
            return ""
    return text


def header_role(header: str) -> str:
    value = normalize_header(header)
    # A customer-supplied catalog/part code (e.g. an "ITEM CODE" or "Product Code"
    # column). Checked before "reference" so it is captured as an explicit code to
    # match against, not confused with a line-item number. When present, the
    # matcher resolves straight to this code instead of re-deriving one from the
    # description (which can pick a slightly wrong variant).
    if value in {
        "item code", "product code", "code", "cat no", "cat. no", "catalogue no",
        "catalog no", "catalogue number", "catalog number", "tricab code",
        "tricab part", "part number", "cable code",
    }:
        return "product_code"
    # The same headers carrying a vendor/system qualifier -- "TRIANGLE Part Number",
    # "RMS Product Code". Customers name the column after whoever's catalogue it is,
    # and against the exact set above every one of those fell through to "other" and
    # the column was dropped: the row lost its explicit code and the style had to be
    # re-derived from the description alone. On a "2.5mm red - LSFLEX X-HF-110" row
    # that is not recoverable -- LSFLEX is claimed by ~60 styles and X-HF-110 is
    # shared with the sheathed ladder -- so an order for LS panel wire came back KL
    # on 10 of 14 lines, six of them silently `matched`. The "description" role below
    # already matches on substring for exactly this reason ("RMS Description" works
    # today), so this closes an asymmetry rather than inventing a looser rule.
    #
    # Only the unambiguous multi-word phrases match loosely. A bare "code" stays out:
    # as a substring it fires on "postcode", "colour code" and "cost code". "part no"
    # stays out too, so it keeps the "reference" reading directly below -- that
    # spelling is the line-item number in the sheets that use it.
    if any(
        token in value
        for token in (
            "part number", "product code", "item code", "catalogue number",
            "catalog number", "tricab code", "tricab part", "cable code",
        )
    ):
        return "product_code"
    if value in {
        "id", "id no", "id number", "ref", "reference", "part no", "item id",
        "itemid", "cable no", "cable reference", "cable id", "cable tag", "circuit",
        "circuit id", "circuit ref", "circuit no", "tag", "tag no", "tag number",
        "s/n", "sn", "s.n", "item", "item no", "item number", "line no",
        "line number", "cable drum id", "drum id",
    }:
        return "reference"
    # Earth columns get their own roles so a sized earth can become its own
    # quoteable line (or fold into the multicore count) instead of being
    # dropped -- and, critically, so an "Earth Length (m)" column never wins
    # the row's quantity role and underquotes the active cable (65-Julie).
    # Checked before cuts/quantity because the substrings overlap.
    if "earth" in value:
        if "length" in value:
            return "earth_length"
        if re.search(r"\bccts?\b", value):
            return "earth_cuts"
        # "Cuts (Earth)" -- the earth's own cut count, qualified by the column it belongs
        # to rather than spelled "ccts". Safe inside this branch because "length" has
        # already been taken above, so an "Earth Cut Length" column keeps its length role.
        if re.search(r"\bcuts?\b", value):
            return "earth_cuts"
        if "size" in value or "csa" in value or "min" in value or value == "earth":
            return "earth_size"
    # A metre/length-qualified "run" header ("Run/M", "Run (m)", "Run Length",
    # "Run m") is the cable's per-run ROUTE LENGTH in metres -- NOT a run COUNT.
    # The metre/length qualifier is exactly what separates it from a bare
    # "Runs"/"Run" cut-count column (handled just below). In cable schedules a
    # later generic "Length"/"Total M"/"Qty" column frequently belongs to a
    # conduit sub-table (23-Allison), so this explicit cable run-length must win
    # the quantity role over those (see select_quantity). Checked before the
    # cuts and quantity rules, whose substrings would otherwise mis-claim it.
    if re.match(r"runs?\b[\s./\\()-]*(?:m|lm|metres?|meters?|length)\b", value):
        return "route_length"
    # Number of runs / cuts (how many separate lengths of this cable). Checked
    # before the "quantity" substring rule so a bare "runs"/"cuts" column becomes
    # the cut count, while a length column like "Qty per run" still reads as the
    # per-cut length. Distinct from quantity so both can coexist on one row.
    # "# Lengths" / "No. of Lengths" count cuts too (26-Neil's quote import
    # view), as do circuit-count columns ("A/N-no. of ccts", 25-Neil).
    if value in {
        "runs", "run", "no of runs", "no. of runs", "number of runs", "qty of runs",
        "cuts", "no of cuts", "no. of cuts", "number of cuts",
        "# lengths", "# of lengths", "no of lengths", "no. of lengths",
        "number of lengths",
    } or re.search(r"\bccts?\b", value):
        return "cuts"
    # The same count qualified by the conductor it belongs to: "Cuts (Mains)",
    # "Cuts/Mains", "No. of Cuts (Mains)", "Mains Cuts". The `cuts` role already exists
    # and already feeds number_of_lengths, so this is a pure alias gap -- on the Cairns
    # schedules the column was read as `other` and the cut count was lost outright.
    #
    # Deliberately narrow: only the mains-qualified spellings, never a bare "cut"
    # substring. "Qty per cut" is a per-cut LENGTH and must keep reading as a quantity,
    # which a looser rule would break.
    if re.fullmatch(r"(?:no\.?\s*of\s+)?cuts?\s*[\s(/\\-]\s*mains\)?", value) or re.fullmatch(
        r"mains\s*[\s(/\\-]\s*cuts?\)?", value
    ):
        return "cuts"
    # The conductor metal, as its own column. It sets the price and it is the field the
    # resolver is otherwise left to assume: fed nothing it defaults to copper, so every
    # aluminium row on the Cairns schedules came back KL-PAXN/1C150OR -- `matched`, high
    # confidence, wrong metal (BODY_TABLE_FIDELITY_HANDOFF.md §7 item 3, §1).
    #
    # Exact match only, and bare "conductor" is deliberately NOT here even though
    # transposed_row_role and the spec-block mapper both accept it. In a transposed block
    # "Conductor: Al" is unambiguous; as a table column "Conductor" holds a size at least
    # as often as a metal, and claiming it would lose the size on those sheets. A sized
    # "Conductor (mm2)" is already excluded by exact matching.
    if value in {"material", "conductor material", "cable material", "conductor metal"}:
        return "conductor_material"
    if value in {"from", "supply from", "source"}:
        return "from"
    if value in {"to", "supply to", "destination"}:
        return "to"
    # Terse description headers ("Details | Length", "Cable | Qty", "DES | QTY")
    # dominate real takeoff spreadsheets. These words are only safe as *exact*
    # cell matches: as substrings they would fire on data cells ("TRICABLE",
    # "SUBMAIN CABLE 95MM..."), and looks_like_header additionally requires a
    # qty/unit/id-role sibling cell before any of this makes a row a header.
    if value in {
        "cable", "cables", "cabling", "submain cable", "details", "detail",
        "data", "des", "desc", "product", "products", "type",
    }:
        return "description"
    # "name" (e.g. a bare "Name" or "Cable Name" column) is a common description
    # header in RFQ schedules alongside qty/units. "spec"/"name" must be matched
    # as whole words: "spec" is a substring of "prospective", which made PowerCAD
    # fault-current columns ("Prospective Fault at End of Cable (kA)") read as
    # description and leak kA figures into the cable text.
    if any(
        token in value
        for token in [
            "description",
            "fredon cable",
            "cable type",
            "types of cable",
            "type of cable",
            "item name",
            # "Cable Selection (cables incl. neutrals)" carries the full
            # free-text spec ("3x 4x1C 300mm² AL/XLPE/PVC") in consultant
            # schedules (22-Railey).
            "cable selection",
        ]
    ):
        return "description"
    if re.search(r"\b(?:specs?|specifications?|names?)\b", value):
        return "description"
    # Customer spreadsheets routinely misspell "Description" (e.g. "Descriprtion").
    # Without this, the whole column goes unrecognised, build_structured_xlsx_item
    # finds no description column, and every row in the sheet falls back to a raw
    # "Number | Description | <unlabelled columns...>" blob instead of clean text.
    if any(
        difflib.SequenceMatcher(None, word, "description").ratio() >= 0.82 for word in value.split()
    ):
        return "description"
    # Conductor CSA columns ("Core Size (mm2)", "Size", "CSA", "Conductor Size").
    # Folded into the description so the resolver picks the right size variant --
    # without it a summary that keeps size in its own column (every "1C, EARTH"
    # row at 25/70/95mm2) collapses to a single default size.
    if is_core_size_header(header):
        return "extra_description"
    if any(token in value for token in ["extra spec", "comment", "notes"]):
        return "extra_description"
    # Bare metre-word headers ("Cable Description | Metres") are quantity
    # columns. Exact matches only: "meter"/"metre" as substrings would turn
    # "Outside Diameter (mm)" into a quantity column.
    if value in {
        "metres", "meters", "metre", "meter", "mtrs", "mtr", "lm",
        "total m", "total metres", "total meters",
    }:
        return "quantity"
    # "Req" / "Required" is how a stock order spells the order quantity (Dulan's
    # September flex sheet: `Description | UOM | Code | Req`). Exact matches only --
    # as a substring "req" fires on "Frequency". "Qty Req" already lands via "qty".
    if value in {"req", "reqd", "req'd", "required"}:
        return "quantity"
    if any(token in value for token in ["qty", "quantit", "length", "cable length", "total length"]):
        return "quantity"
    if value in {"unit", "units", "uom", "u/m", "um"}:
        return "unit"
    # Costing columns ("Unit Rate", "Price ea", "Cost ($)", "Ex GST"). Money is
    # not cable data: left in the fallback joined text, an unpriced schedule's
    # "0 | 0" rate cells read as an explicit 0m length and the whole row was
    # dropped (MSB-D1 submains). Only unambiguous money words match here; a bare
    # "Total"/"Amount" is resolved by price_column_indexes, which can see whether
    # another column already carries the quantity.
    if re.search(r"\brates?\b|\bprices?\b|\bcosts?\b|\bgst\b|\$", value):
        return "price"
    return "other"


def price_column_indexes(headers: list[str]) -> set[int]:
    """Column positions carrying money, to exclude from fallback joined text.

    Unambiguous money headers come straight from header_role. A bare "Total" /
    "Amount" / "Value" is money only when some other column already claimed the
    quantity role (LENGTH / TOTAL METERS beside UNIT RATE | TOTAL); without a
    quantity sibling it may be the sheet's only metres column, and dropping it
    would lose the length entirely."""
    roles = [header_role(header) for header in headers]
    price_cols = {idx for idx, role in enumerate(roles) if role == "price"}
    if "quantity" in roles:
        for idx, header in enumerate(headers):
            if normalize_header(header) in {"total", "totals", "amount", "value", "sell"}:
                price_cols.add(idx)
    return price_cols


def canonical_code_cell(value: str) -> str:
    """A code column's cell with the spaces customers type around the separators closed up.

    Sales sheets spell one code three ways -- ``LS-XXXD/1C2.5WH``, ``LS - XXXD/1C2.5WH``,
    ``LS- XXXD/1C2.5WH`` -- and :data:`PRODUCT_CODE_RE` matches only the first. The row
    then loses its explicit code and falls back to resolving from the description alone.
    On Dulan's September flex order that returned XL-PAXN (XLPE power core) where the
    sheet said LS-XXXD (LS flex panel wire) on 2 of 9 lines, at 0.80 -- high enough to
    read ``matched`` and auto-sync, so the wrong cable would have been quoted with no
    signal anywhere. One line went ``unresolved`` and was the only visible symptom.

    Guarded so it can only ever rescue: a cell that already parses is returned untouched,
    and the closed-up form is used only when it turns a non-code into a code. A cell that
    is not a code either way is unchanged, so a "Code" column holding something else is
    not quietly rewritten.

    Widening ``PRODUCT_CODE_RE`` itself was the alternative and is the wrong place -- it
    scans free prose, where tolerating spaces around the hyphen would let it straddle
    unrelated words. Here the header has already told us the cell is a code, which is
    exactly the ambiguity prose does not have. A trailing gloss needs no help:
    ``LS-XXXD/1C10BK BLACK`` is searched, not matched whole.
    """
    text = str(value or "").strip()
    if not text or PRODUCT_CODE_RE.search(text.upper()):
        return text
    closed = re.sub(r"(?<=[A-Za-z0-9])\s*([-/])\s*(?=[A-Za-z0-9])", r"\1", text)
    return closed if PRODUCT_CODE_RE.search(closed.upper()) else text


CONDUCTOR_MATERIAL_TOKENS = {
    "al": "AL", "alu": "AL", "alum": "AL", "aluminium": "AL", "aluminum": "AL",
    "cu": "CU", "copper": "CU",
    "tc": "TINNED", "tcu": "TINNED", "tinned": "TINNED",
    "tinned copper": "TINNED", "tinned cu": "TINNED",
}


def canonical_conductor_material(value: str) -> str:
    """The conductor metal as a token the extractor already reads, or "".

    A closed set, per BODY_TABLE_FIDELITY_HANDOFF.md §7 item 3. The returned tokens are
    exactly the spellings ``extract_entities`` already matches -- AL, CU, TINNED -- so the
    material reaches the resolver through the reading it already does rather than through
    a second, parallel path that could disagree with it.

    Closed rather than pass-through on purpose: a "Material" column can hold the sheath
    ("PVC", "LSZH") as easily as the conductor, and folding that into the row text would
    change how the row resolves. Anything unrecognised returns "" and stays in
    ``unmapped_columns``, where a human can see it.
    """
    return CONDUCTOR_MATERIAL_TOKENS.get(str(value or "").strip().lower(), "")


def first_pair_by_role(pairs: list[tuple[str, str]], role: str) -> tuple[str, str]:
    """The first (header, value) for a role -- the header matters when the value is not
    understood and has to be reported back under the name the customer gave it."""
    for header, value in pairs:
        if header_role(header) == role:
            return header, value
    return "", ""


def first_by_role(pairs: list[tuple[str, str]], role: str) -> str:
    for header, value in pairs:
        if header_role(header) == role:
            return value
    return ""


def code_shaped_cell(pairs: list[tuple[str, str]]) -> str:
    """A catalogue code sitting in a column whose header nothing recognised.

    ``header_role`` can only name the labels it has been shown, and customers keep
    inventing new ones for the same column. So when no column claimed the
    product_code role, fall back to the SHAPE of the cell: ``LS-XXXD/1C2.5RE`` and
    ``B12-ACXX/10H240`` are unmistakable whatever the header above them says. This
    is the half of the fix that survives the next customer's spelling, where the
    header vocabulary in :func:`header_role` only covers the ones already seen.

    Restricted to unclassified ("other") columns so it can never overrule a column
    that WAS recognised -- a reference/tag column whose values happen to look like
    codes keeps its own role. The cell is searched rather than matched whole
    because these columns routinely carry a trailing gloss ("LS-XXXD/1C2.5RE Red").
    """
    for header, value in pairs:
        if header_role(header) != "other":
            continue
        cell = str(value or "").strip().upper()
        if not cell:
            continue
        match = PRODUCT_CODE_RE.search(cell) or ACCESSORY_CODE_RE.search(cell)
        if match:
            return match.group(0)
    return ""


def _prefer_length(pairs: list[tuple[str, str]]) -> tuple[str, str]:
    """Among columns that otherwise tie, prefer a lone metre/length column.

    Cable is priced by the metre, so when the tie-break set holds exactly one
    length column and one or more count siblings (a procurement summary's "Total
    Qty" vs "Total Est. Length (m)"), the length is the quantity to trust -- but
    only when that length is actually filled in. Per-unit rows (fibre trunks,
    patch leads) leave the metre column blank/zero; there the count is the real
    quantity and must not be zeroed out, or the whole line drops downstream.
    """
    metres = [p for p in pairs if is_metre_valued(*p)]
    counts = [p for p in pairs if not is_metre_valued(*p)]
    if len(metres) == 1 and counts:
        if parse_number(metres[0][1]):
            return metres[0]
        return counts[0]
    return pairs[0]


def _numbers_reconcile(each, cuts, total) -> bool:
    """cuts x each == total, within rounding slack (proves a per-run column)."""
    try:
        each, cuts, total = float(each), float(cuts), float(total)
    except (TypeError, ValueError):
        return False
    if each <= 0 or cuts <= 1 or total <= 0:
        return False
    return abs(each * cuts - total) <= max(0.5, 0.01 * total)


def select_quantity(pairs: list[tuple[str, str]]) -> tuple[str, str, int | None]:
    """(header, value, derived_cuts) of the quantity column to trust.

    Typed ranking over the columns that share the quantity role, instead of
    first-column-wins (audit fix #3):

    - With a cuts column ("# Lengths", "Runs", "ccts"): a sibling column whose
      value x cuts equals another sibling is the per-run length -- take it, and
      let the cuts role carry the run count (26-Neil: ``# Lengths 4 | Length
      400 | Quantity 1600`` -> 4 x 400).
    - Without a cuts column: a length-ish x qty-ish pair that multiplies out to
      a "total"-labelled sibling derives the cut count arithmetically
      (23-Allison: ``Length 15 | Qty 8 | Total M 120`` -> 8 x 15).
    - "Order" columns are what the customer wants to buy and outrank takeoff
      figures (27-Neil: ``QUANTITY (m) 1083 | ORDER QUANTITY (m) 1400``).
    - A "total" column outranks per-run siblings (unchanged behaviour).

    ``derived_cuts`` is only set by the arithmetic derivation; a cuts column
    keeps flowing through the cuts role as before.
    """
    # An explicit cable run-length column ("Run/M", "Run Length (m)") is the most
    # specific length signal on the row and wins outright: a generic "Length" /
    # "Total M" / "Qty" sibling in a cable schedule is usually the conduit
    # sub-table, not the cable (23-Allison). The cuts role still comes separately,
    # so a real run count on the same row still multiplies this per-run length.
    route = [(header, value) for header, value in pairs if header_role(header) == "route_length"]
    if route:
        return route[0][0], route[0][1], None

    candidates = [(header, value) for header, value in pairs if header_role(header) == "quantity"]
    if not candidates:
        return "", "", None

    cuts = parse_number(first_by_role(pairs, "cuts"))
    if cuts and cuts > 1:
        for header, value in candidates:
            each = parse_number(value)
            for other_header, other_value in candidates:
                if other_header is header:
                    continue
                if _numbers_reconcile(each, cuts, parse_number(other_value)):
                    return header, value, None

    totals = [(h, v) for h, v in candidates if "total" in normalize_header(h)]
    orders = [(h, v) for h, v in candidates if "order" in normalize_header(h)]
    if not cuts and totals:
        rest = [(h, v) for h, v in candidates if (h, v) not in totals]
        length_like = [(h, v) for h, v in rest if "length" in normalize_header(h)]
        count_like = [(h, v) for h, v in rest if (h, v) not in length_like]
        for each_header, each_value in length_like:
            for _unused_header, cuts_value in count_like:
                derived = parse_number(cuts_value)
                if _numbers_reconcile(parse_number(each_value), derived, parse_number(totals[0][1])):
                    return each_header, each_value, int(derived)
    if orders:
        header, value = _prefer_length(orders)
        return header, value, None
    if totals:
        # A summary sheet carries both "Total Qty (No. of Cables)" and "Total Est.
        # Length (m)"; the length is what a cable quote prices, so a lone metre
        # column beats the count siblings even though both say "Total".
        header, value = _prefer_length(totals)
        return header, value, None

    # Both a length and a count, with nothing to reconcile them against: the column
    # carrying metres is the per-cut length, the other number is how many cuts.
    # Without this, first-column-wins could read the count ("Qty 4") as the length.
    if not cuts:
        metres = [(h, v) for h, v in candidates if is_metre_valued(h, v)]
        counts = [(h, v) for h, v in candidates if not is_metre_valued(h, v)]
        if len(metres) == 1 and counts:
            count = parse_number(counts[0][1])
            derived = int(count) if count and count > 1 else None
            return metres[0][0], metres[0][1], derived

    return candidates[0][0], candidates[0][1], None


_QTY_CELL_CUTS_RE = re.compile(r"^\s*(\d+)\s*[x×]\s*(\d+(?:\.\d+)?)\s*(?:m|mtrs?)?\s*$", re.I)


def split_quantity_cell(value: str) -> tuple[str, int | None]:
    """A quantity cell of ``"8 X 40"`` means 8 cuts of 40 m, not 8 m (44-Amanda).
    Returns ``(quantity_text, cuts)``; plain cells pass through with no cuts."""
    match = _QTY_CELL_CUTS_RE.match(str(value or ""))
    if not match:
        return value, None
    return match.group(2), int(match.group(1))


def role_column_value(row_values: list[str], headers: list[str], role: str) -> str:
    """Value of the first column whose header maps to ``role``, reading straight
    from ``row_values`` (unlike :func:`first_by_role`, which works off the
    empties-dropped pairs list) so a row's blank/filled shape per column can be
    inspected."""
    for index, header in enumerate(headers):
        if header_role(header) != role:
            continue
        value = row_values[index] if index < len(row_values) else ""
        if value:
            return value
    return ""


def product_code_continuation(row_values: list[str], headers: list[str]) -> str:
    """Return the product code from a "code-only" continuation row, else "".

    Some schedules (often pivot-table exports) put a cable's catalog code on the
    line *below* its description rather than beside it -- the code sits alone in
    the product-code column with the description and quantity columns blank. Such
    a row is not its own quotable line; its code belongs to the item just above.
    A row that also carries a description or quantity is a normal line, not a
    continuation, so this returns "" for it."""
    code = role_column_value(row_values, headers, "product_code")
    if not code:
        return ""
    has_description = any(
        header_role(header) in {"description", "extra_description"}
        and (row_values[index] if index < len(row_values) else "")
        for index, header in enumerate(headers)
    )
    if has_description or role_column_value(row_values, headers, "quantity"):
        return ""
    return code


def attach_product_code(item: dict[str, Any], code: str) -> None:
    """Fold a continuation row's catalog code into the preceding item, so the
    resolver's exact-code shortcut uses it (mirrors build_structured_xlsx_item)."""
    text = str(item.get("text") or "").strip()
    if code and code.upper() not in text.upper():
        item["text"] = f"{text} {code}".strip()
    item.setdefault("metadata", {}).setdefault("product_code", code)


def parse_number(value: str) -> float | int | None:
    text = str(value or "").replace(",", "").strip()
    match = re.search(r"-?\d+(?:\.\d+)?", text)
    if not match:
        return None
    number = float(match.group(0))
    return int(number) if number.is_integer() else number


def parse_docx(path: Path) -> list[dict[str, Any]]:
    try:
        from docx import Document
    except ImportError as exc:
        raise RuntimeError(
            "DOCX parsing requires the python-docx package. Install dependencies "
            "with `python -m pip install -r requirements.txt` using the same "
            "Python interpreter that runs rfq_cli.py."
        ) from exc

    document = Document(path)
    items: list[dict[str, Any]] = []
    context: list[str] = []
    row_number = 0

    for paragraph in document.paragraphs:
        text = clean_cell(paragraph.text)
        if not text:
            continue
        row_number += 1
        if len(text.split()) <= 5 and not any(char.isdigit() for char in text):
            context.append(text)
            continue
        items.append({"text": text, "context": context[-4:], "row_number": row_number})

    for table in document.tables:
        header_context: list[str] = []
        for row in table.rows:
            cells = [clean_cell(cell.text) for cell in row.cells if clean_cell(cell.text)]
            if not cells:
                continue
            row_number += 1
            text = " | ".join(cells)
            if looks_like_header(cells):
                header_context = [text]
                continue
            items.append({"text": text, "context": context[-3:] + header_context, "row_number": row_number})

    return items


# --- Drawing take-off annotations (FreeText markup, audit: WARRENS take-off) --------
# Consultants issue cable schedules as drawings with no length column; the
# contractor then does the take-off by typing FreeText comments ("230MTS X")
# over each row in a PDF editor. That text lives in the annotation layer, which
# extract_text/extract_tables never see, so every row silently defaulted to 1 m.
_ANNOT_LENGTH_RE = re.compile(
    r"^\s*(\d+(?:\.\d+)?)\s*(?:mtrs?|mts?|metres?|meters?|m)\b\.?\s*(?:x\s*)?$",
    re.I,
)


def page_freetext_notes(page: Any) -> list[dict[str, Any]]:
    """FreeText markup on a PDF page, with the boxes they were drawn at."""
    try:
        annots = page.annots or []
    except Exception:
        return []
    notes: list[dict[str, Any]] = []
    for annot in annots:
        data = annot.get("data") or {}
        if "FreeText" not in str(data.get("Subtype") or ""):
            continue
        text = clean_cell(annot.get("contents"))
        if not text:
            continue
        try:
            notes.append(
                {
                    "x0": float(annot["x0"]),
                    "x1": float(annot["x1"]),
                    "top": float(annot["top"]),
                    "bottom": float(annot["bottom"]),
                    "text": text,
                }
            )
        except (KeyError, TypeError, ValueError):
            continue
    return notes


def assign_notes_to_rows(table: Any, notes: list[dict[str, Any]]) -> dict[int, list[dict[str, Any]]]:
    """Map FreeText notes onto the table rows they were drawn over.

    FreeText draws its text from the top of its box, so a note belongs to the
    row band containing a point a quarter of the way down the box -- an
    oversized box drawn across two rows still anchors to the row the author
    typed on, where the midpoint would tip into the row below. The note must
    horizontally overlap the table at all (the drawing's title banner is also
    a FreeText note; its anchor sits outside every row band)."""
    if not notes:
        return {}
    table_x0, _, table_x1, _ = table.bbox
    by_row: dict[int, list[dict[str, Any]]] = {}
    for note in notes:
        if note["x1"] < table_x0 or note["x0"] > table_x1:
            continue
        anchor = note["top"] + (note["bottom"] - note["top"]) / 4
        best_index, best_overlap = None, 0.0
        for index, row in enumerate(table.rows):
            _, top, _, bottom = row.bbox
            if top <= anchor <= bottom:
                best_index = index
                break
            overlap = min(note["bottom"], bottom) - max(note["top"], top)
            if overlap > best_overlap:
                best_index, best_overlap = index, overlap
        if best_index is not None:
            by_row.setdefault(best_index, []).append(note)
    for grouped in by_row.values():
        grouped.sort(key=lambda note: note["x0"])
    return by_row


def annotation_route_length(note_texts: list[str]) -> tuple[float | int, str] | None:
    """The first note that reads as a route length ("230MTS X" -> 230 m)."""
    for text in note_texts:
        match = _ANNOT_LENGTH_RE.match(text)
        if match:
            value = parse_number(match.group(1))
            if value is not None:
                return value, text
    return None


def route_length_metadata(length_note: tuple[float | int, str], shape: str) -> dict[str, Any]:
    value, raw = length_note
    return {
        "table_quantity": value,
        "table_quantity_raw": raw,
        "table_quantity_header": "Route Length",
        "quantity_basis": "per_cut",
        "table_unit": "m",
        "source_table_shape": shape,
    }


def route_schedule_columns(cells: list[str]) -> dict[str, int]:
    """Column map when the row heads a route schedule, else {}.

    The shape is a drawing's cable schedule: FROM | TO | Main Cable Size |
    Earth Cable Size, with no length/qty column (lengths, if any, arrive as
    FreeText take-off notes). Tables that do carry a length or quantity column
    are left to the structured paths, which know how to read them."""
    columns: dict[str, int] = {}
    for index, cell in enumerate(cells):
        value = normalize_header(cell)
        if not value:
            continue
        if any(word in value for word in ("length", "qty", "quantity")):
            return {}
        if "earth" in value and ("cable" in value or "size" in value):
            columns.setdefault("earth", index)
        elif ("cable" in value and "size" in value) or ("main" in value and "cable" in value):
            columns.setdefault("main", index)
    return columns if {"main", "earth"} <= columns.keys() else {}


def build_route_schedule_items(
    row_values: list[str],
    columns: dict[str, int],
    note_texts: list[str],
    context: list[str],
) -> list[dict[str, Any]]:
    """One item for the main cable and one for its separate earth.

    Both run the same route, so a take-off note's length applies to each; the
    runs multiplier ("2x1C-70mm2") stays in the cell text for the extractor."""
    main_cell = row_value(row_values, columns.get("main"))
    earth_cell = row_value(row_values, columns.get("earth"))
    if not main_cell and not earth_cell:
        return []
    route_cells = [
        value
        for index, value in enumerate(row_values)
        if value and index not in {columns.get("main"), columns.get("earth")}
    ]
    length_note = annotation_route_length(note_texts)

    def build(spec_cells: list[str]) -> dict[str, Any]:
        metadata: dict[str, Any] = {"source_table_shape": "route_schedule"}
        if length_note:
            metadata = route_length_metadata(length_note, "route_schedule")
        return {
            "text": " | ".join(route_cells + spec_cells + note_texts),
            "context": context[-4:],
            "row_number": None,
            "metadata": metadata,
        }

    items: list[dict[str, Any]] = []
    if main_cell:
        items.append(build([main_cell]))
    if earth_cell and not _NO_EARTH_RE.match(earth_cell):
        earth_item = build(["EARTH", earth_cell])
        if main_cell:
            earth_item["context"] = (earth_item["context"] + ["PAIRED MAINS CABLE"])[-4:]
        items.append(earth_item)
    return items


def parse_pdf(path: Path) -> list[dict[str, Any]]:
    items: list[dict[str, Any]] = []
    with pdfplumber.open(path) as pdf:
        for page_number, page in enumerate(pdf.pages, start=1):
            try:
                table_objects = page.find_tables() or []
            except Exception:
                table_objects = []
            notes = page_freetext_notes(page)
            tables: list[tuple[Any, dict[int, list[dict[str, Any]]]]] = []
            for table_object in table_objects:
                try:
                    data = table_object.extract() or []
                except Exception:
                    continue
                tables.append((data, assign_notes_to_rows(table_object, notes)))
            table_signatures: set[str] = set()
            page_has_construction = False
            page_has_route_schedule = False
            for table, notes_by_row in tables:
                if is_transposed_cable_table(table):
                    # Attribute-per-row schedules (e.g. "Submain C1/C2/C3" columns) are
                    # pivoted so each cable becomes one line. Register every cell and
                    # joined-row signature so the page-text pass below does not re-emit
                    # the same content as flattened lines.
                    for row in table:
                        cells = [clean_cell(cell) for cell in row if clean_cell(cell)]
                        if not cells:
                            continue
                        table_signatures.add(content_signature(" ".join(cells)))
                        for cell in cells:
                            table_signatures.add(content_signature(cell))
                    items.extend(pivot_transposed_cable_table(table, page_number))
                    continue
                context: list[str] = [f"Page {page_number}"]
                headers: list[str] = []
                construction = False
                route_columns: dict[str, int] = {}
                for row_index, row in enumerate(table):
                    row_values = [clean_cell(cell) for cell in row]
                    cells = [value for value in row_values if value]
                    if not cells:
                        continue
                    value = " | ".join(cells)
                    table_signatures.add(content_signature(" ".join(cells)))
                    note_texts = [note["text"] for note in notes_by_row.get(row_index, [])]
                    found_route = route_schedule_columns(row_values)
                    if found_route:
                        route_columns = found_route
                        headers = []
                        construction = False
                        context = [f"Page {page_number}", value]
                        continue
                    if route_columns and all(
                        normalize_header(cell) in {"from", "to"} for cell in cells
                    ):
                        # The CABLE ROUTE column's FROM/TO sub-header row.
                        context = (context + [value])[-4:]
                        continue
                    if route_columns:
                        built = build_route_schedule_items(
                            row_values, route_columns, note_texts, context
                        )
                        if built:
                            items.extend(built)
                            page_has_route_schedule = True
                            continue
                    if looks_like_header(cells):
                        headers = row_values
                        construction = is_construction_schedule(headers)
                        context = [f"Page {page_number}", value]
                        continue
                    if headers and construction:
                        # Decomposed schedule (Active/Earth/Insulation/Configuration
                        # columns, no description): rebuild each cable's spec from its
                        # columns, emitting a separate line for any sized trefoil earth.
                        built = build_construction_schedule_items(
                            row_values, headers, context, page_number
                        )
                        if built:
                            items.extend(built)
                            page_has_construction = True
                            continue
                    if headers:
                        structured = build_structured_xlsx_item(row_values, headers)
                        if structured and structured.get("metadata", {}).get("table_quantity") is not None:
                            structured["context"] = context[-4:]
                            structured["row_number"] = None
                            items.append(structured)
                            continue
                        if structured:
                            context = (context + [value])[-4:]
                            continue
                    item: dict[str, Any] = {"text": value, "context": context, "row_number": None}
                    if note_texts:
                        # A take-off note over a plain row: keep it visible in the
                        # text and, when it reads as a length, carry it as the
                        # quantity the extractor would otherwise never see.
                        item["text"] = " | ".join(cells + note_texts)
                        length_note = annotation_route_length(note_texts)
                        if length_note:
                            item["metadata"] = route_length_metadata(length_note, "pdf_annotation")
                    items.append(item)
            # A construction-schedule page is fully tabular; the text layer reflows the
            # rows into run-on lines, so skip it to avoid noisy duplicate items. A route
            # schedule is a drawing sheet: its text layer is the same reflowed rows
            # (side-by-side tables merge into one line, defeating the signature dedupe)
            # plus title-block boilerplate, so it gets the same treatment.
            text = (
                ""
                if page_has_construction or page_has_route_schedule
                else (page.extract_text() or "")
            )
            for idx, line in enumerate(text.splitlines(), start=1):
                value = clean_cell(line)
                if value and content_signature(value) not in table_signatures:
                    items.append(
                        {
                            "text": value,
                            "context": [f"Page {page_number}"],
                            "row_number": idx,
                        }
                    )
    if not items:
        items.append(
            {
                "text": "No extractable PDF text found. This file may need OCR.",
                "context": [],
                "row_number": 1,
            }
        )
    return items


# Attribute rows whose presence (3+ distinct) marks a transposed cable schedule,
# where each cable is a column rather than a row.
CABLE_ATTRIBUTE_ANCHORS = {
    "cores",
    "conductor_size",
    "conductor_material",
    "insulation",
    "sheathing",
    "cable_type",
    "length",
}
# Roles, in order, that make up the synthetic per-cable description fed to the extractor.
TRANSPOSED_DESCRIPTION_ROLES = (
    "cable_type",
    "cores",
    "conductor_size",
    "conductor_material",
    "insulation",
    "sheathing",
)
_PARALLEL_RUNS_RE = re.compile(r"^\s*(\d+)\s*[x×]\s*(\d+(?:\.\d+)?)", re.I)
_LENGTH_UNIT_RE = re.compile(r"\d+(?:\.\d+)?\s*([a-z]+)", re.I)


def transposed_row_role(label: str) -> str:
    """Map a left-column attribute label to a normalized role, or '' / 'other'."""
    value = normalize_header(label)
    if not value:
        return ""
    if "conductor size" in value or value in {"size", "csa", "cable size"}:
        return "conductor_size"
    if "conductor material" in value or value in {"material", "conductor"}:
        return "conductor_material"
    if "core" in value:  # "n° of cores", "no of cores", "number of cores"
        return "cores"
    if "insulation" in value:
        return "insulation"
    if "sheath" in value:
        return "sheathing"
    if "cable type" in value or value == "type":
        return "cable_type"
    if "nominal length" in value or value == "length" or value == "route length":
        return "length"
    if "breaker" in value:
        return "breaker"
    if "feed from" in value or value in {"from", "source"}:
        return "from"
    if value in {"supply to", "to", "destination"}:
        return "to"
    if "power supply" in value:
        return "power_supply"
    if "voltage drop" in value:
        return "voltage_drop"
    return "other"


def is_transposed_cable_table(table: list[list[Any]]) -> bool:
    """True when a table lays cables out as columns and attributes as rows.

    Detected by reading the first column's labels: a transposed cable schedule
    carries several recognizable attribute rows (cores, conductor size, etc.)
    against two or more data columns."""
    rows = [row for row in (table or []) if any(clean_cell(cell) for cell in row)]
    if len(rows) < 4:
        return False
    ncols = max(len(row) for row in rows)
    if ncols < 3:  # one label column + at least two cable columns
        return False
    data_columns = [
        j
        for j in range(1, ncols)
        if any(clean_cell(row[j]) for row in rows if j < len(row))
    ]
    if len(data_columns) < 2:
        return False
    roles = {transposed_row_role(clean_cell(row[0])) for row in rows[1:]}
    anchors = roles & CABLE_ATTRIBUTE_ANCHORS
    return len(anchors) >= 3 and ("cores" in roles or "conductor_size" in roles)


def pivot_transposed_cable_table(table: list[list[Any]], page_number: int) -> list[dict[str, Any]]:
    """Turn each cable column of a transposed schedule into one quoteable item."""
    rows = [row for row in (table or []) if any(clean_cell(cell) for cell in row)]
    if not rows:
        return []
    header = rows[0]
    ncols = max(len(row) for row in rows)

    items: list[dict[str, Any]] = []
    for j in range(1, ncols):
        attrs: dict[str, str] = {}
        for row in rows[1:]:
            label = clean_cell(row[0])
            value = clean_cell(row[j]) if j < len(row) else ""
            if not label or not value:
                continue
            role = transposed_row_role(label)
            if role and role != "other" and role not in attrs:
                attrs[role] = value
        if not (attrs.get("conductor_size") or attrs.get("cores")):
            continue  # empty or non-cable column
        reference = clean_cell(header[j]) if j < len(header) else ""
        items.append(_build_transposed_item(attrs, reference, page_number))
    return items


def _build_transposed_item(attrs: dict[str, str], reference: str, page_number: int) -> dict[str, Any]:
    description = " ".join(
        attrs[role] for role in TRANSPOSED_DESCRIPTION_ROLES if attrs.get(role)
    )

    metadata: dict[str, Any] = {}
    if reference:
        metadata["reference"] = reference
    if attrs.get("from"):
        metadata["from"] = attrs["from"]
    if attrs.get("to"):
        metadata["to"] = attrs["to"]

    length_raw = attrs.get("length", "")
    length_value = parse_number(length_raw)
    if length_value is not None:
        metadata["table_quantity"] = length_value
        metadata["table_quantity_raw"] = length_raw
        metadata["table_quantity_header"] = "Nominal length"
        metadata["quantity_basis"] = "per_cut"
        unit_match = _LENGTH_UNIT_RE.search(length_raw)
        metadata["table_unit"] = (unit_match.group(1).lower() if unit_match else "m") or "m"

    runs = _parallel_runs(attrs.get("conductor_size", ""))
    if runs > 1:
        # "2 x 300mm²" = two parallel runs; total purchased length is runs x route length.
        metadata["parallel_runs"] = runs
        metadata["number_of_lengths"] = runs

    context = [part for part in (f"Page {page_number}", reference) if part]
    return {"text": description, "context": context, "metadata": metadata, "row_number": None}


def _parallel_runs(conductor_size: str) -> int:
    match = _PARALLEL_RUNS_RE.match(str(conductor_size or ""))
    return int(match.group(1)) if match else 1


# --- Decomposed construction schedules (Active/Neutral/Earth/Insulation/Config) -------
_CONFIG_TREFOIL_RE = re.compile(r"(\d+)\s*[x×]\s*1\s*core", re.I)  # "3 x 1 core Trefoil"
_CONFIG_MULTICORE_RE = re.compile(r"(\d+)\s*core", re.I)          # "4 core Circular"
_CELL_SIZE_RE = re.compile(r"(\d+(?:\.\d+)?)\s*(?:sq\.?\s*)?mm", re.I)
_CELL_MATERIAL_RE = re.compile(r"\b(cu|copper|al|alu|aluminium|aluminum)\b", re.I)


def construction_column_role(header: str) -> str:
    """Map a construction-schedule header cell to a normalized role."""
    value = normalize_header(header)
    if not value:
        return ""
    if "cable no" in value or value in {"cable number", "cable reference", "cable ref", "tag", "ref", "reference"}:
        return "reference"
    if value in {"from", "source", "supply from"}:
        return "from"
    if value in {"to", "destination", "supply to"}:
        return "to"
    # The count columns are checked BEFORE the size columns they name: "Active + Neutral
    # cores" contains "active" and "Earth cores" contains "earth", so on the substring
    # tests below they would both be read as a size cell holding a bare conductor count.
    if "core" in value:
        return "earth_cores" if "earth" in value else "cores"
    if "active" in value:
        return "active"
    if "neutral" in value:
        return "neutral"
    if "earth" in value:
        return "earth"
    if "insulation" in value:
        return "insulation"
    # A dedicated conductor-material column ("Conductor: Al"). Exact match only: a sized
    # "Conductor (mm2)" header is a size column, not a material one.
    if value in {"conductor", "conductor material", "material"}:
        return "conductor"
    # "Construction" is the other name sheets give the configuration column, holding
    # either a core count ("4 core Circular") or a construction class ("Flex S.D.I.").
    if "configuration" in value or "construction" in value:
        return "configuration"
    if "accurate" in value:
        return "length_accurate"
    if "original" in value:
        return "length_original"
    # A route/run length is the per-cut distance, the same role "Accurate Length" plays.
    if value == "length" or "cable length" in value or "route length" in value or "run length" in value:
        return "length_accurate"
    # The sheet's own total (route length x conductor count). Not a length role:
    # build_construction_schedule_items uses it to check whether the "24 x
    # 300mm2" style counts are absolute or per-phase.
    # Set membership, not a substring test: "total cable (m)" keeps its bracketed unit
    # through normalize_header, so match on the leading words instead.
    if value in {"total meters", "total metres", "total m", "total lm", "total length"} or (
        value.startswith(("total cable", "total metres", "total meters", "total length"))
    ):
        return "length_total"
    return "other"


_SIBLING_FIRE_RE = re.compile(r"\b(?:FIRE\s*(?:RATED|RESISTANT)|FR\s*110)\b", re.I)


def _sibling_heat_markers(reference: str, texts: list[str]) -> list[str]:
    """Relationship and fire marker a separated earth inherits from its mains row.

    ``PAIRED MAINS CABLE`` keeps an ordinary split earth on LP. FIRE RATED overrides
    that default to LS; a mains temperature does not, matching earth_inherited_markers.
    """
    signals = [reference, *texts]
    markers = ["PAIRED MAINS CABLE"]
    if any(_SIBLING_FIRE_RE.search(text) for text in signals):
        markers.append("FIRE RATED")
    return markers


def is_construction_schedule(headers: list[str]) -> bool:
    """True for schedules that decompose a cable across Active/Earth/Configuration columns
    rather than giving a free-text description."""
    roles = {construction_column_role(header) for header in (headers or [])}
    return "active" in roles and "configuration" in roles and ("earth" in roles or "insulation" in roles)


# A size cell whose unit lives in the column header instead of the cell: "4x300" under an
# "Active (mm2)" header. The optional leading factor is the number of parallel conductors
# per phase, the same "N x size" grammar _cell_runs reads from a self-describing cell.
_BARE_SIZE_RE = re.compile(r"^\s*(?:(\d+)\s*[x×]\s*)?(\d+(?:\.\d+)?)\s*$")


def _sized_cell(cell: str) -> tuple[str, int]:
    """``(size, runs)`` from a construction-schedule size cell, in either dialect:
    self-describing ("2 x 150mm2") or bare with the unit in the header ("2×150")."""
    text = clean_cell(cell)
    size = _cell_size(text)
    if size:
        return size, _cell_runs(text)
    match = _BARE_SIZE_RE.match(text)
    if not match:
        return "", 1
    return match.group(2), int(match.group(1)) if match.group(1) else 1


def _cores_column_items(
    pairs: dict[str, str], base_context: list[str], length_raw: str,
    from_value: str, to_value: str,
) -> list[dict[str, Any]] | None:
    """Decompose a schedule that states its conductor counts in their own columns.

    The shape: sizes in "Active (mm2)"/"Earth (mm2)", the number of singles those sizes
    are run in as "Active + Neutral cores" / "Earth cores", and the per-cut distance in
    "Route Length (m)". The configuration cell here is a construction class ("Flex
    S.D.I."), not a core count, so the trefoil/multicore branches below cannot read it and
    the whole sheet used to fall through to the raw pipe-joined fallback -- which handed
    an AI a sentence like "1 | N01 | ... | 4×300 | 4×300 | 300 | ... | 16 | 1 | 595" and
    got back a single 17-core cable priced at the row's nominal OD.

    Each row is two lines, the split the counts already describe: the actives and neutrals
    as one single-core run count, and the earth as its own. Quantities come from the cores
    x route length the sheet states, NOT from its own "Total cable (m)" column -- that
    total counts the earth conductors too, so using it for the phase line double-counts
    them. Decomposing strictly reconciles to the sheet's own totals tab exactly, size by
    size, which the total-column reading does not.

    Returns ``None`` (not ``[]``) when the row is not this shape, so the caller can fall
    through to the configuration-driven branches.
    """
    cores_cell = pairs.get("cores", "")
    earth_cores_cell = pairs.get("earth_cores", "")
    if not cores_cell and not earth_cores_cell:
        return None

    size, _ = _sized_cell(pairs.get("active", ""))
    cores = parse_number(cores_cell)
    if not size or not cores:
        return None

    neutral_size, _ = _sized_cell(pairs.get("neutral", ""))
    if neutral_size and neutral_size != size:
        # The cores column counts actives and neutrals together, so two different sizes
        # cannot be apportioned between them. Leave the row to the branches below.
        return None

    material = _cell_material(pairs.get("conductor", "")) or _cell_material(pairs.get("active", "")) or "Cu"
    insulation = pairs.get("insulation", "")
    reference = pairs.get("reference", "")
    length_header = "Route Length"

    items = [
        _construction_item(
            f"1C {size}mm2 {material} {insulation}",
            reference, length_raw, length_header, base_context,
            runs=int(cores), from_value=from_value, to_value=to_value,
        )
    ]

    earth_size, earth_cell_runs = _sized_cell(pairs.get("earth", ""))
    earth_cores = parse_number(earth_cores_cell) or earth_cell_runs
    if earth_size and "n/a" not in pairs.get("earth", "").lower() and earth_cores:
        # No insulation on the earth line: the Insulation column describes the phase
        # conductor, and reading it onto the earth would push it off the standard rubber
        # earth (LP) onto the 110C LS. TriCab quoted this schedule's earths LP-XXXA
        # against LSFLEX R-70 (110C) phases, so the earth keeps its own default. A row
        # that really is hot in its own right still upgrades via earth_inherited_markers.
        earth_material = _cell_material(pairs.get("earth", "")) or material
        items.append(
            _construction_item(
                f"1C {earth_size}mm2 {earth_material} earth",
                f"{reference} earth".strip(), length_raw, length_header, base_context,
                runs=int(earth_cores), from_value=from_value, to_value=to_value,
            )
        )
    return items


def build_construction_schedule_items(
    row_values: list[str], headers: list[str], context: list[str], page_number: int | None = None
) -> list[dict[str, Any]]:
    """Rebuild one construction-schedule row into one or two quoteable items.

    Multicore (``N core Circular``) → a single ``(N+1)C`` line when a sized earth is
    present (the earth counts as a core), else ``N``C. Single-core trefoil
    (``M x 1 core``) → an ``M``-run ``1C`` line plus a separate ``1C`` earth line."""
    pairs: dict[str, str] = {}
    for index, header in enumerate(headers):
        role = construction_column_role(header)
        if not role or role == "other":
            continue
        cell = clean_cell(row_values[index]) if index < len(row_values) else ""
        if cell and role not in pairs:
            pairs[role] = cell

    active = pairs.get("active", "")
    configuration = pairs.get("configuration", "")
    size = _cell_size(active)

    insulation = pairs.get("insulation", "")
    reference = pairs.get("reference", "")
    from_value = pairs.get("from", "")
    to_value = pairs.get("to", "")
    length_raw = pairs.get("length_accurate") or pairs.get("length_original") or ""
    length_header = "Accurate Length" if pairs.get("length_accurate") else "Original Length"

    earth_cell = pairs.get("earth", "")
    earth_size = _cell_size(earth_cell)
    has_earth = bool(earth_size) and "n/a" not in earth_cell.lower()
    earth_material = _cell_material(earth_cell) or "Cu"

    page_part = f"Page {page_number}" if page_number is not None else ""
    base_context = [part for part in (page_part, reference) if part]

    cores_items = _cores_column_items(pairs, base_context, length_raw, from_value, to_value)
    if cores_items is not None:
        return cores_items

    if not size or not configuration:
        # A row carrying only a sized EARTH cell is a trefoil cable's separate
        # earth on its own row ("D1-7 EARTH | 1 x 70mm² | 10 | 10"). It is a real
        # quoteable line whose route length is the asked CUT length -- left to
        # the raw-join fallback it reads the bulk TOTAL METERS column instead,
        # loses its run count, and gets rounded up to whole prepack spools.
        if has_earth and not size:
            return [
                _construction_item(
                    f"1C {earth_size}mm2 {earth_material} {insulation} earth",
                    reference,
                    length_raw,
                    length_header,
                    base_context,
                    runs=_cell_runs(earth_cell),
                    from_value=from_value,
                    to_value=to_value,
                )
            ]
        return []  # not a cable row (e.g. a stray note)

    material = _cell_material(active) or "Cu"
    neutral_cell = pairs.get("neutral", "")
    neutral_size = _cell_size(neutral_cell)
    neutral_material = _cell_material(neutral_cell) or material

    trefoil = _CONFIG_TREFOIL_RE.search(configuration)
    if trefoil:
        phase_cores = int(trefoil.group(1))
        active_runs = _cell_runs(active)
        neutral_runs = _cell_runs(neutral_cell) if neutral_size else 0
        active_total_runs = phase_cores * active_runs
        # "24 x 300mm2" counts conductors two ways in the wild: per trefoil
        # group (24 groups x 3 phases = 72 singles) or absolute (24 singles).
        # A TOTAL METERS column settles it: when route x (active + neutral)
        # equals the sheet's own total, the counts are absolute and the phase
        # multiplication must not apply -- it triple-quoted a real submains
        # schedule (24 x 37m became 72 cuts).
        route_length = parse_number(length_raw)
        sheet_total = parse_number(pairs.get("length_total", ""))
        if route_length and sheet_total:
            conductors = sheet_total / route_length
            if abs(conductors - (active_runs + neutral_runs)) < 0.01:
                active_total_runs = active_runs
        items = [
            _construction_item(
                f"1C {size}mm2 {material} {insulation}",
                reference,
                length_raw,
                length_header,
                base_context,
                runs=active_total_runs,
                from_value=from_value,
                to_value=to_value,
            )
        ]
        if neutral_size:
            if neutral_size == size and neutral_material == material:
                items[0]["metadata"]["number_of_lengths"] = active_total_runs + neutral_runs
                items[0]["metadata"]["parallel_runs"] = active_total_runs + neutral_runs
            else:
                items.append(
                    _construction_item(
                        f"1C {neutral_size}mm2 {neutral_material} {insulation} neutral",
                        f"{reference} neutral".strip(),
                        length_raw,
                        length_header,
                        base_context,
                        runs=neutral_runs,
                        from_value=from_value,
                        to_value=to_value,
                    )
                )
        if has_earth:
            items.append(
                _construction_item(
                    f"1C {earth_size}mm2 {earth_material} {insulation} earth",
                    f"{reference} earth".strip(),
                    length_raw,
                    length_header,
                    base_context,
                    runs=_cell_runs(earth_cell),
                    from_value=from_value,
                    to_value=to_value,
                )
            )
        return items

    multicore = _CONFIG_MULTICORE_RE.search(configuration)
    if multicore:
        cores = int(multicore.group(1))
        cores_expr = f"{cores}C+E" if has_earth else f"{cores}C"
        return [
            _construction_item(
                f"{cores_expr} {size}mm2 {material} {insulation}",
                reference,
                length_raw,
                length_header,
                base_context,
                from_value=from_value,
                to_value=to_value,
            )
        ]
    return []


def _construction_item(
    description: str, reference: str, length_raw: str, length_header: str,
    base_context: list[str], runs: int = 1, from_value: str = "", to_value: str = "",
) -> dict[str, Any]:
    metadata: dict[str, Any] = {}
    if reference:
        metadata["reference"] = reference
    if from_value:
        metadata["from"] = from_value
    if to_value:
        metadata["to"] = to_value
    length_value = parse_number(length_raw)
    if length_value is not None:
        metadata["table_quantity"] = length_value
        metadata["table_quantity_raw"] = length_raw
        metadata["table_quantity_header"] = length_header
        metadata["quantity_basis"] = _quantity_basis_from_header(length_header)
        metadata["table_unit"] = "m"
    if runs > 1:
        metadata["parallel_runs"] = runs
        metadata["number_of_lengths"] = runs
    return {
        "text": " ".join(description.split()),
        "context": base_context,
        "metadata": metadata,
        "row_number": None,
    }


def _cell_size(cell: str) -> str:
    match = _CELL_SIZE_RE.search(str(cell or ""))
    return match.group(1) if match else ""


def _cell_material(cell: str) -> str:
    match = _CELL_MATERIAL_RE.search(str(cell or ""))
    if not match:
        return ""
    return "Al" if match.group(1).lower().startswith("a") else "Cu"


def _cell_runs(cell: str) -> int:
    match = re.search(r"^\s*(\d+)\s*[x×]\s*\d+(?:\.\d+)?\s*mm", str(cell or ""), re.I)
    return int(match.group(1)) if match else 1


def clean_cell(value: Any) -> str:
    if value is None:
        return ""
    text = str(value).replace("\u00a0", " ").strip()
    return " ".join(text.split())


def content_signature(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "", str(value or "").lower())


# Attribute words that mark a column of a decomposed cable schedule (cores /
# insulation / conductor / earth size ... with no free-text description column).
# Word-boundary matched against a single short cell, never a joined row.
HEADER_ATTRIBUTE_RE = re.compile(
    r"\b(?:cores?|insulation|conductors?|sheath(?:ing)?|active|neutral|earth|"
    r"configuration|arrangement|sizes?|csa)\b"
)
# Real column labels are short. A spec cell like "POWER CABLE,0.6/1 KV,3C+E,240
# SQ. MM,CU,XLPE INSUL,..." contains attribute words but is far longer than any
# genuine header label, so long cells get no header-label role at all.
MAX_HEADER_LABEL_LEN = 40


def header_cell_role(cell: str) -> str:
    """Role of a single cell considered *as a header label*, or "".

    Unlike :func:`header_role` (which trusts that its input is already a header
    row), this must reject data cells, so it works per cell with a length cap
    rather than scanning joined row text: "1C 240 Earth Conductor, Cu, PVC/PVC"
    contains "conductor" but is not a column label.
    """
    value = normalize_header(cell)
    if not value or len(value) > MAX_HEADER_LABEL_LEN:
        return ""
    role = header_role(cell)
    if role == "price" and any(ch.isdigit() for ch in value):
        return ""  # "$1,234.00" is a money VALUE, not a "Rate ($)" column label
    if role != "other":
        return role
    if HEADER_ATTRIBUTE_RE.search(value):
        return "attribute"
    return ""


# A free-text line that reads as a section heading rather than a quote line:
# "CONTROLS Cable Types", "VSD CABLES", "Solar cables". Same instinct as the docx
# paragraph rule (short, wordy, no digits), plus a domain-keyword requirement.
#
# The keyword gate is what makes this safe to feed into `context`: a row's context
# joins the ranking haystack (resolver.rank_styles) and becomes `current_section`
# for the AI normalizer, so an ordinary courtesy line promoted to a heading would
# be pure noise there. Requiring the heading to actually name a cable family means
# context is only ever populated when it can carry a cable_type_hint -- which is
# the entire reason the heading machinery exists.
_HEADING_GREETING_RE = re.compile(
    r"^(?:hi|hello|hey|dear|good\s+(?:morning|afternoon|evening)|thanks?|cheers)\b", re.I
)
_HEADING_KEYWORD_RE = re.compile(
    r"\b(?:cable|cables|control|controls|power|instrument\w*|comms?|communication|"
    r"data|vsd|vfd|solar|fire|earth|earthing|flex\w*|armour\w*|armor\w*|screen\w*|"
    r"lszh|lzsh|ls[0o]h|copper|aluminium|aluminum|welding|marine|mining|trailing|reeling|feeder)\b",
    re.I,
)


def looks_like_section_heading(line: str) -> bool:
    """True when a free-text line reads as a cable section heading.

    Deliberately narrow -- see the note above _HEADING_KEYWORD_RE. Two to five
    words, no digits, no sentence-ending punctuation, not a greeting, and it must
    name something cable-ish. "Cables only. Exclude all pre-made leads." is a
    directive, not a heading, and must not become context.
    """
    value = str(line or "").strip().rstrip(":").strip()
    if not value or any(char.isdigit() for char in value):
        return False
    if value.endswith((".", "?", "!")):
        return False
    words = value.split()
    if not 2 <= len(words) <= 5:
        return False
    if _HEADING_GREETING_RE.match(value):
        return False
    return bool(_HEADING_KEYWORD_RE.search(value))


def looks_like_header(cells: list[str]) -> bool:
    """Per-cell, role-based header detection.

    A row is a header when its cells' *label roles* say so, not when header
    words appear anywhere in the joined text. The old substring scoring made
    data rows into headers mid-sheet ("to" is a substring of "conductor", so
    "1C 240 Earth Conductor, Cu, PVC/PVC | 30" scored 2 terms and hijacked the
    header, destructuring every row after it), while genuinely terse headers
    ("Details | Length", "Cable | Qty") scored 1 and were missed entirely.
    """
    if len(cells) < 2:
        return False
    roles = [header_cell_role(cell) for cell in cells]
    recognized = [role for role in roles if role]
    has_description = any(role in {"description", "extra_description"} for role in roles)
    has_quantityish = any(role in {"quantity", "route_length", "cuts", "unit"} for role in roles)
    has_id = any(role in {"reference", "product_code"} for role in roles)
    # The common case: a description-like label next to a qty/unit or id label.
    # This is what makes the terse aliases in header_role safe -- "Cable" or
    # "Data" alone never marks a header; it must co-occur with a qty-role cell.
    if has_description and (has_quantityish or has_id):
        return True
    # Decomposed schedules without a description column: a length column plus
    # several recognized labels (Cores | Size | Insulation | ... | Length [m]).
    if has_quantityish and len(recognized) >= 3:
        return True
    # Wide schedules with no length column at all (e.g. reference + attribute
    # columns + comments). Four label-role cells never happen in a data row.
    if len(recognized) >= 4:
        return True
    # Narrow schedules where every cell is a recognizable label and one carries
    # the quantity ("ItemID | Length", "Cable Size & # of cores | Length per
    # conductor (m)"). Data rows always carry at least one value cell (a number
    # or a spec) that gets no label role, so they cannot satisfy this.
    if has_quantityish and len(recognized) == len(cells):
        return True
    return False
