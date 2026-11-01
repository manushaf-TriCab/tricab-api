# Generated from the RFQ parser's tests/test_attachment_validate.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""§5 of AGENT_FILE_READING_ORDER: the deterministic check on the reader's claims.

The cases that matter here are the ones where an item looks fine and is wrong -- a
quantity that does not match the cell it names, a blank read as zero, a derivation that
reaches for a cell the item never declared. Those are the shapes that put a wrong number
on a real quote.
"""

from __future__ import annotations

import openpyxl
import pytest

from rfq_parser.attachment_validate import validate_items


@pytest.fixture
def workbook():
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Sheet1"
    ws["A1"], ws["B1"], ws["C1"] = "Description", "Code", "Qty (m)"
    ws["A2"], ws["C2"] = "0.75mm2 1PR+SCR", 8400
    ws["A3"], ws["C3"] = "1.5mm2 3C+E", 1200
    ws["A4"] = "blank quantity row"          # C4 deliberately empty
    ws["A5"], ws["C5"] = "zero row", 0
    second = wb.create_sheet("90% Design")
    second["F13"] = 25
    second["G13"] = "n/a"
    return wb


def item(**kwargs):
    base = {
        "sheet": "Sheet1",
        "row": 2,
        "description": "0.75mm2 1PR+SCR",
        "quantity": 8400,
        "unit": "m",
        "quantity_source": {"cells": ["Sheet1!C2"], "derivation": None},
        "note": "",
    }
    base.update(kwargs)
    return base


# --- the happy paths -------------------------------------------------------------

def test_single_cell_matching_is_accepted(workbook):
    outcome = validate_items([item()], workbook)
    assert len(outcome.accepted) == 1
    assert outcome.degraded == []


def test_derivation_over_named_cells_is_accepted(workbook):
    outcome = validate_items(
        [item(sheet="90% Design", row=13, quantity=100,
              quantity_source={"cells": ["90% Design!F13"], "derivation": "F13 * 4"})],
        workbook,
    )
    assert len(outcome.accepted) == 1, outcome.degraded


def test_multi_cell_sum_is_accepted(workbook):
    outcome = validate_items(
        [item(quantity=9600,
              quantity_source={"cells": ["Sheet1!C2", "Sheet1!C3"], "derivation": "C2 + C3"})],
        workbook,
    )
    assert len(outcome.accepted) == 1, outcome.degraded


def test_null_quantity_is_kept_not_dropped(workbook):
    """§4: a row that states no quantity keeps null rather than inventing one. It carries
    no cell claim, so there is nothing to check -- but it is a real row for a human."""
    outcome = validate_items([item(quantity=None, quantity_source=None)], workbook)
    assert len(outcome.accepted) == 1
    assert outcome.accepted[0]["quantity"] is None


def test_zero_is_a_real_quantity_not_a_failure(workbook):
    outcome = validate_items(
        [item(row=5, quantity=0, quantity_source={"cells": ["Sheet1!C5"], "derivation": None})],
        workbook,
    )
    assert len(outcome.accepted) == 1, outcome.degraded


# --- rule 1: sheet and row ---------------------------------------------------------

def test_unknown_sheet_is_dropped(workbook):
    outcome = validate_items([item(sheet="Nope")], workbook)
    assert outcome.accepted == []
    assert outcome.degraded[0]["rule"] == "1_sheet"


def test_row_past_the_end_is_dropped(workbook):
    outcome = validate_items([item(row=9999)], workbook)
    assert outcome.degraded[0]["rule"] == "1_row"


def test_row_zero_is_dropped(workbook):
    assert validate_items([item(row=0)], workbook).degraded[0]["rule"] == "1_row"


# --- rule 2: the cells exist -------------------------------------------------------

def test_nonexistent_cell_is_dropped(workbook):
    outcome = validate_items(
        [item(quantity_source={"cells": ["Sheet1!C9999"], "derivation": None})], workbook
    )
    assert outcome.degraded[0]["rule"] == "2_cell"


def test_cell_on_unknown_sheet_is_dropped(workbook):
    outcome = validate_items(
        [item(quantity_source={"cells": ["Ghost!C2"], "derivation": None})], workbook
    )
    assert outcome.degraded[0]["rule"] == "2_cell"


def test_malformed_reference_is_dropped(workbook):
    outcome = validate_items(
        [item(quantity_source={"cells": ["C2"], "derivation": None})], workbook
    )
    assert outcome.degraded[0]["rule"] == "2_cell"


def test_missing_quantity_source_is_dropped(workbook):
    """§4 calls quantity_source mandatory -- an item without one has no audit trail."""
    outcome = validate_items([item(quantity_source=None)], workbook)
    assert outcome.degraded[0]["rule"] == "2_source"


# --- rule 3: the cell says what the item says --------------------------------------

def test_quantity_not_matching_its_cell_is_dropped(workbook):
    """The core case. The item is well-formed, names a real cell, and is wrong."""
    outcome = validate_items([item(quantity=8000)], workbook)
    assert outcome.accepted == []
    assert outcome.degraded[0]["rule"] == "3_mismatch"
    assert "8400" in outcome.degraded[0]["detail"]


def test_blank_cell_claimed_as_a_quantity_is_dropped(workbook):
    """§5.5, blank != 0. A blank cell cannot substantiate any quantity at all."""
    outcome = validate_items(
        [item(row=4, quantity=500, quantity_source={"cells": ["Sheet1!C4"], "derivation": None})],
        workbook,
    )
    assert outcome.degraded[0]["rule"] == "3_blank"


def test_text_cell_claimed_as_a_quantity_is_dropped(workbook):
    outcome = validate_items(
        [item(sheet="90% Design", row=13, quantity=1,
              quantity_source={"cells": ["90% Design!G13"], "derivation": None})],
        workbook,
    )
    assert outcome.degraded[0]["rule"] == "3_blank"


def test_several_cells_without_a_derivation_is_dropped(workbook):
    outcome = validate_items(
        [item(quantity=9600,
              quantity_source={"cells": ["Sheet1!C2", "Sheet1!C3"], "derivation": None})],
        workbook,
    )
    assert outcome.degraded[0]["rule"] == "3_multi"


# --- rule 4: the derivation reproduces the quantity --------------------------------

def test_derivation_that_does_not_reproduce_the_quantity_is_dropped(workbook):
    outcome = validate_items(
        [item(sheet="90% Design", row=13, quantity=999,
              quantity_source={"cells": ["90% Design!F13"], "derivation": "F13 * 4"})],
        workbook,
    )
    assert outcome.degraded[0]["rule"] == "4_mismatch"


def test_derivation_naming_an_undeclared_cell_is_dropped(workbook):
    """§5.4: 'over the named cells only'. C3 exists and would even make the sum work --
    that is exactly why an undeclared reference has to fail: the audit trail would be
    incomplete and still look correct."""
    outcome = validate_items(
        [item(quantity=9600, quantity_source={"cells": ["Sheet1!C2"], "derivation": "C2 + C3"})],
        workbook,
    )
    assert outcome.degraded[0]["rule"] == "4_expression"


def test_derivation_with_a_blank_cell_is_dropped(workbook):
    outcome = validate_items(
        [item(row=4, quantity=0, quantity_source={"cells": ["Sheet1!C4"], "derivation": "C4 * 2"})],
        workbook,
    )
    assert outcome.degraded[0]["rule"] == "4_blank"


@pytest.mark.parametrize(
    "expression",
    [
        "__import__('os').system('echo pwned')",
        "open('/etc/passwd').read()",
        "C2.__class__",
        "[c for c in range(10)]",
        "C2 if C2 else 0",
        "lambda: 1",
    ],
)
def test_non_arithmetic_derivations_are_rejected(workbook, expression):
    """Attachment content is untrusted (§3) and a derivation is a string that came back
    from a model reading it. It is evaluated, so it is parsed and whitelisted, never
    eval()'d."""
    outcome = validate_items(
        [item(quantity_source={"cells": ["Sheet1!C2"], "derivation": expression})], workbook
    )
    assert outcome.accepted == []
    assert outcome.degraded[0]["rule"] == "4_expression"


def test_division_by_zero_is_rejected_not_raised(workbook):
    outcome = validate_items(
        [item(row=5, quantity=1,
              quantity_source={"cells": ["Sheet1!C2", "Sheet1!C5"], "derivation": "C2 / C5"})],
        workbook,
    )
    assert outcome.degraded[0]["rule"] == "4_expression"


# --- rule 5: the quantity is a number ----------------------------------------------

def test_negative_quantity_is_dropped(workbook):
    outcome = validate_items([item(quantity=-5)], workbook)
    assert outcome.degraded[0]["rule"] == "5_negative"


def test_string_quantity_is_dropped(workbook):
    outcome = validate_items([item(quantity="8400")], workbook)
    assert outcome.degraded[0]["rule"] == "5_type"


def test_boolean_quantity_is_dropped(workbook):
    """True == 1 in Python. A TRUE cell is not a quantity of one."""
    outcome = validate_items([item(quantity=True)], workbook)
    assert outcome.degraded[0]["rule"] == "5_type"


# --- the outcome the caller gates on -----------------------------------------------

def test_failure_share_is_reported(workbook):
    outcome = validate_items([item(), item(quantity=8000), item(sheet="Nope")], workbook)
    assert len(outcome.accepted) == 1
    assert outcome.total == 3
    assert outcome.failure_share == pytest.approx(2 / 3)


def test_nothing_is_repaired(workbook):
    """§5: 'Do not repair a failing item.' The dropped item must come back untouched,
    so what is recorded in `degraded` is what the model actually said."""
    bad = item(quantity=8000)
    outcome = validate_items([bad], workbook)
    assert outcome.degraded[0]["item"]["quantity"] == 8000
    assert bad["quantity"] == 8000


# --- §2's fault: a cited cell the derivation does not use ------------------------
#
# One run in three of `23-Allison` lost every one of its 66 items to `4_blank`. The model
# had cited `Sheet1!G5` -- the `Type` cell reading `4x1c+e XLPE X-90`, which is where the
# x16 comes from -- alongside the route cell it multiplied. Rule 4 read the text cell as a
# blank inside the derivation and rejected the lot, so the reader was punished for showing
# its working and someone got an empty draft.

def test_a_cited_text_cell_the_derivation_never_uses_is_provenance_not_a_blank(workbook):
    outcome = validate_items(
        [item(quantity=16800, quantity_source={
            "cells": ["Sheet1!C2", "Sheet1!A2"], "derivation": "C2 * 2"})],
        workbook,
    )
    assert outcome.degraded == [], outcome.degraded
    assert len(outcome.accepted) == 1


def test_a_blank_the_derivation_does_use_is_still_dropped(workbook):
    """The rule keeps its point: `C4 * 2` with C4 empty cannot produce a number, and
    treating the blank as zero is the silent-quantity failure the module exists for."""
    outcome = validate_items(
        [item(quantity=100, quantity_source={
            "cells": ["Sheet1!C2", "Sheet1!C4"], "derivation": "C4 * 2"})],
        workbook,
    )
    assert outcome.accepted == []
    assert outcome.degraded[0]["rule"] == "4_blank"
    assert "C4" in outcome.degraded[0]["detail"]


# --- §4: the inferred multiplier -------------------------------------------------

def test_a_constant_in_a_derivation_is_flagged_as_an_inferred_multiplier(workbook):
    """`F13 * 4` validates cleanly and proves nothing about the 4. The cell reference is
    real, the arithmetic is right, and the count came from prose."""
    outcome = validate_items(
        [item(sheet="90% Design", row=13, quantity=100,
              quantity_source={"cells": ["90% Design!F13"], "derivation": "F13 * 4"})],
        workbook,
    )
    flag = outcome.accepted[0]["inferred_multiplier"]
    assert flag["factors"] == [4.0]
    assert "no cited cell states" in flag["detail"]


def test_the_flag_keeps_the_cell_the_count_was_read_from(workbook):
    outcome = validate_items(
        [item(quantity=16800, quantity_source={
            "cells": ["Sheet1!C2", "Sheet1!A2"], "derivation": "C2 * 2"})],
        workbook,
    )
    assert outcome.accepted[0]["inferred_multiplier"]["provenance_cells"] == ["Sheet1!A2"]


def test_a_derivation_over_cells_alone_is_not_flagged(workbook):
    """`C2 + C3` invents no number, so there is nothing for a human to confirm."""
    outcome = validate_items(
        [item(quantity=9600, quantity_source={
            "cells": ["Sheet1!C2", "Sheet1!C3"], "derivation": "C2 + C3"})],
        workbook,
    )
    assert "inferred_multiplier" not in outcome.accepted[0]


def test_a_plain_single_cell_quantity_is_not_flagged(workbook):
    outcome = validate_items([item()], workbook)
    assert "inferred_multiplier" not in outcome.accepted[0]


def test_a_null_quantity_is_not_flagged(workbook):
    outcome = validate_items([item(row=4, quantity=None, quantity_source=None)], workbook)
    assert "inferred_multiplier" not in outcome.accepted[0]


# --- §4: the double-counting guard ----------------------------------------------

def _whole_cell(**kwargs):
    return item(quantity_source={"cells": ["Sheet1!C2"], "derivation": None}, **kwargs)


def test_two_items_on_one_row_claiming_the_same_whole_cell_are_flagged(workbook):
    """The shape pass 2 produced on `23-Allison`: a main and an earth both reported as the
    plain contents of the row's one length cell. Each passes rules 1-5 alone."""
    outcome = validate_items([_whole_cell(), _whole_cell(description="its earth")], workbook)
    assert len(outcome.accepted) == 2, "flagged, never dropped -- see the module docstring"
    for accepted in outcome.accepted:
        assert accepted["double_counted"]["cell"] == "Sheet1!C2"
        assert accepted["double_counted"]["items_on_row"] == 2


def test_the_same_cell_with_different_derivations_is_not_double_counted(workbook):
    """`23-Allison` row 5 done right: 16 actives and 3 earths off one route. Two different
    quantities from one cell is correct and common, and flagging it would cry wolf on the
    file this order exists to get right."""
    outcome = validate_items(
        [
            item(quantity=134400, quantity_source={"cells": ["Sheet1!C2"], "derivation": "C2 * 16"}),
            item(quantity=25200, quantity_source={"cells": ["Sheet1!C2"], "derivation": "C2 * 3"}),
        ],
        workbook,
    )
    assert len(outcome.accepted) == 2
    assert not any("double_counted" in a for a in outcome.accepted)


def test_items_on_different_rows_are_not_double_counted(workbook):
    outcome = validate_items(
        [
            _whole_cell(),
            item(row=3, quantity=1200,
                 quantity_source={"cells": ["Sheet1!C3"], "derivation": None}),
        ],
        workbook,
    )
    assert not any("double_counted" in a for a in outcome.accepted)


def test_one_item_claiming_a_cell_is_not_double_counted(workbook):
    outcome = validate_items([_whole_cell()], workbook)
    assert "double_counted" not in outcome.accepted[0]


def test_a_flag_does_not_change_the_quantity(workbook):
    """Flags mark; they never repair. A validator that adjusts a number is a second parser."""
    outcome = validate_items([_whole_cell(), _whole_cell()], workbook)
    assert [a["quantity"] for a in outcome.accepted] == [8400, 8400]


def test_an_empty_cited_cell_is_not_offered_as_where_the_count_came_from(workbook):
    """The flag's one job is to tell a reviewer where to look. `90% Design!G13` holds the
    text 'n/a' and is worth naming; a cell holding nothing is not."""
    outcome = validate_items(
        [item(sheet="90% Design", row=13, quantity=100,
              quantity_source={"cells": ["90% Design!F13", "Sheet1!C4"], "derivation": "F13 * 4"})],
        workbook,
    )
    assert outcome.accepted[0]["inferred_multiplier"]["provenance_cells"] == []


def test_a_cited_text_cell_is_offered_as_where_the_count_came_from(workbook):
    outcome = validate_items(
        [item(sheet="90% Design", row=13, quantity=100,
              quantity_source={"cells": ["90% Design!F13", "90% Design!G13"], "derivation": "F13 * 4"})],
        workbook,
    )
    assert outcome.accepted[0]["inferred_multiplier"]["provenance_cells"] == ["90% Design!G13"]


@pytest.mark.parametrize("cell_value", ["8400", " 8400.0 ", "8.4e3", "+8400"])
def test_numeric_text_source_cells_are_validated(workbook, cell_value):
    workbook["Sheet1"]["C2"] = cell_value
    result = validate_items([item()], workbook)
    assert len(result.accepted) == 1
    assert not result.degraded


@pytest.mark.parametrize("cell_value", ["", " ", "8400m", "8,400", "=8400", "NaN", "inf", "1e999", True])
def test_ambiguous_or_nonfinite_text_is_not_a_source_quantity(workbook, cell_value):
    workbook["Sheet1"]["C2"] = cell_value
    result = validate_items([item()], workbook)
    assert not result.accepted
    assert result.degraded


def test_numeric_text_derivation_checks_arithmetic_without_repairing_claim(workbook):
    sheet = workbook["Sheet1"]
    sheet["C2"], sheet["D2"], sheet["E2"] = "70", "3", "3"
    claim = item(quantity=840, quantity_source={
        "cells": ["Sheet1!C2", "Sheet1!D2", "Sheet1!E2"],
        "derivation": "C2 * (D2 * 3 + E2)",
    })
    assert len(validate_items([claim], workbook).accepted) == 1
    claim["quantity"] = 841
    assert validate_items([claim], workbook).degraded[0]["rule"] == "4_mismatch"
