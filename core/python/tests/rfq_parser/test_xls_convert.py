# Generated from the RFQ parser's tests/test_xls_convert.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""`.xls` -> `.xlsx`: PORTAL_TERRA_PARITY_PLAN Phase A.

The point of the conversion is that the reader's two guarantees -- terra opens the workbook
with `openpyxl` and reports cells, and `attachment_validate` re-opens the same bytes with
`openpyxl` and checks them -- become available on a file type where neither could run. So
these tests are about fidelity, and the one that matters most is
`test_a_claim_about_a_converted_cell_validates`: a coordinate terra cites has to mean the
same cell after conversion, or the check is checking the wrong thing.

`tests/fixtures/xls/legacy-schedule.xls` is a committed BIFF8 workbook covering every cell
type xlrd distinguishes, a merged header band, a hidden sheet, and a used range wider than
its values. It is committed rather than written at test time because `xlwt` is not a
dependency of this project, and a Phase A test that skips when the writer is absent would
be no evidence that Billy's files work.
"""

from __future__ import annotations

import datetime as dt
import io
import tempfile
from pathlib import Path

import openpyxl
import pytest

from rfq_parser.attachment_validate import validate_items
from rfq_parser.xls_convert import (
    XlsConversionError,
    safe_sheet_titles,
    xls_to_xlsx,
)

FIXTURE = Path(__file__).parent / "fixtures" / "xls" / "legacy-schedule.xls"


@pytest.fixture
def converted() -> openpyxl.Workbook:
    """The fixture, converted and re-opened the way `_validate` re-opens it."""
    data, renamed = xls_to_xlsx(FIXTURE.read_bytes())
    assert renamed == []
    return openpyxl.load_workbook(io.BytesIO(data), data_only=True, read_only=False)


# --- fidelity ----------------------------------------------------------------------

def test_cell_coordinates_are_preserved(converted):
    """The whole guarantee: `Submains!C3` means the same cell in the customer's file."""
    sheet = converted["Submains"]
    assert sheet["A2"].value == "Description"
    assert sheet["A3"].value == "LSZH FLEXIBLE BUILDING WIRE 110d"
    assert sheet["C3"].value == 2100
    assert sheet["D3"].value == 3


def test_xlrd_typing_is_undone_the_way_openpyxl_would_have_it(converted):
    sheet = converted["Submains"]
    # A whole float is an int, or every quantity in the sheet stringifies as "2100.0".
    assert sheet["C3"].value == 2100 and isinstance(sheet["C3"].value, int)
    # A real fraction stays one.
    assert sheet["C4"].value == 70.5
    # A date serial is a date, not 46138.
    assert sheet["B5"].value == dt.datetime(2026, 4, 26)
    # A TRUE cell is a bool, which `_as_number` refuses -- it is not a quantity.
    assert sheet["C5"].value is True


def test_a_blank_cell_re_opens_as_none_not_as_an_empty_string(converted):
    """§5.5 turns on blank not being a value. An empty string written into an empty cell
    would make `quantity_source.cells` citing it look like evidence of nothing."""
    assert converted["Submains"]["D4"].value is None


def test_the_used_range_survives_the_save(converted):
    """The fixture's values stop at row 5 / column 5 and its used range is 7x7.

    openpyxl does not write out a cell holding neither value nor style, so without
    `_anchor_used_range` the converted sheet comes back narrower than the customer's file
    -- and `max_row`/`max_column` are exactly what rule 1 bounds a claimed row against and
    what rule 2 uses to decide a cited cell exists.
    """
    sheet = converted["Submains"]
    assert (sheet.max_row, sheet.max_column) == (7, 7)
    assert sheet["G7"].value is None


def test_merged_header_bands_survive(converted):
    """The instructions tell terra to read grouped and merged header bands, so a
    conversion that flattened them would change what it is being asked to judge."""
    assert {str(r) for r in converted["Submains"].merged_cells.ranges} == {"C1:D1"}
    assert converted["Submains"]["C1"].value == "Cable"


def test_hidden_sheets_are_carried_across_hidden_rather_than_dropped(converted):
    """`parse_xls` skips hidden sheets because it is deciding what an RFQ contains. A
    converter is not, and the `.xlsx` reader path sees hidden sheets today -- dropping them
    here would make one file mean two different things to the two readers."""
    assert converted.sheetnames == ["Submains", "Old master"]
    assert converted["Submains"].sheet_state == "visible"
    assert converted["Old master"].sheet_state == "hidden"


# --- the reason it exists ----------------------------------------------------------

def test_a_claim_about_a_converted_cell_validates(converted):
    """§5 against the converted bytes. Before Phase A this row came back
    `0_unvalidatable`; the claim is the same claim, and now it can be substantiated."""
    item = {
        "sheet": "Submains", "row": 3, "description": "LSZH FLEXIBLE BUILDING WIRE 110d",
        "product_code": None, "product_code_cell": None,
        "quantity": 2100, "unit": "m", "cuts": None,
        "quantity_source": {"cells": ["Submains!C3"], "derivation": None}, "note": "",
    }
    outcome = validate_items([item], converted)
    assert outcome.degraded == []
    assert outcome.accepted[0]["quantity"] == 2100


def test_a_cell_the_workbook_does_not_have_still_fails(converted):
    """Fidelity has to cut both ways: the conversion must not invent addressable space."""
    item = {
        "sheet": "Submains", "row": 3, "description": "x",
        "product_code": None, "product_code_cell": None,
        "quantity": 2100, "unit": "m", "cuts": None,
        "quantity_source": {"cells": ["Submains!Z99"], "derivation": None}, "note": "",
    }
    outcome = validate_items([item], converted)
    assert outcome.accepted == []
    assert outcome.degraded[0]["rule"] == "2_cell"


def test_the_deterministic_parse_of_both_files_agrees():
    """Two readers of one file must not disagree for a reason that is about neither of
    them. `parse_xls` on the original and `parse_xlsx` on the conversion are the same
    machinery over the same grid, so they must produce the same items."""
    from rfq_parser.file_parser import parse_xls, parse_xlsx

    data, _ = xls_to_xlsx(FIXTURE.read_bytes())
    with tempfile.TemporaryDirectory() as tmp:
        target = Path(tmp) / "converted.xlsx"
        target.write_bytes(data)
        from_xlsx = parse_xlsx(target)
    from_xls = parse_xls(FIXTURE)
    assert from_xls == from_xlsx
    assert from_xls, "the fixture must actually parse to something"


# --- failure is loud ----------------------------------------------------------------

def test_an_xlsx_is_not_an_xls():
    """The mistake this would hide is real: `.xls` and `.xlsx` differ by one character in
    a filename, and OOXML bytes handed to xlrd are not a legacy workbook."""
    workbook = openpyxl.Workbook()
    workbook.active["A1"] = "hello"
    buffer = io.BytesIO()
    workbook.save(buffer)
    with pytest.raises(XlsConversionError) as excinfo:
        xls_to_xlsx(buffer.getvalue())
    assert "could not open" in str(excinfo.value)


def test_garbage_is_a_conversion_error_not_a_traceback():
    with pytest.raises(XlsConversionError):
        xls_to_xlsx(b"not a workbook at all")


def test_empty_bytes_are_refused():
    with pytest.raises(XlsConversionError) as excinfo:
        xls_to_xlsx(b"")
    assert "empty" in str(excinfo.value)


# --- sheet names --------------------------------------------------------------------

def test_titles_pass_through_unchanged_when_excel_would_accept_them():
    assert safe_sheet_titles(["Submains", "Old master"]) == ["Submains", "Old master"]


def test_titles_are_truncated_to_excels_limit():
    assert safe_sheet_titles(["x" * 40]) == ["x" * 31]


def test_characters_excel_forbids_in_a_title_are_replaced():
    assert safe_sheet_titles(["Rev:1/2 [final]?"]) == ["Rev_1_2 _final__"]


def test_a_collision_the_truncation_itself_created_is_resolved():
    """A 31-character truncation is what *creates* the collision it then has to resolve,
    and the de-duplicating suffix is spent out of the 31 rather than appended past it."""
    titles = safe_sheet_titles(["A" * 35 + "one", "A" * 35 + "two"])
    assert titles[0] == "A" * 31
    assert titles[1] == "A" * 29 + "~2"
    assert len(titles[1]) == 31
    assert len(set(titles)) == 2


def test_collisions_are_case_insensitive_the_way_excel_is():
    assert safe_sheet_titles(["Data", "DATA"]) == ["Data", "DATA~2"]


def test_a_nameless_sheet_still_gets_a_name():
    assert safe_sheet_titles(["", "  "]) == ["Sheet", "Sheet~2"]
