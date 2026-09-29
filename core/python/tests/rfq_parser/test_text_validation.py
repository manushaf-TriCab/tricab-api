# Generated from the RFQ parser's tests/test_text_validation.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""The text twin of §5's deterministic check, for body copy and PDFs.

The spreadsheet path can re-open the workbook and read the cell back, which is what lets
a model read a schedule without ever transcribing a number. Text has no cells, so the
evidence is a quoted span the model must return and this module must re-find.

The cases that matter are the ones where an item looks fine and is wrong: a quantity that
is not in the span it cites, a span that is not in the document at all, and a count
multiplied in from prose. Those are the shapes that put a wrong number on a real quote --
and unlike the workbook path, nothing downstream can catch them.
"""

from __future__ import annotations

import pytest

from rfq_parser.attachment_validate import (
    DOUBLE_COUNT_KEY,
    INFERRED_MULTIPLIER_KEY,
    validate_text_items,
)

# A realistic email body: a prose line, a rendered table row, a total, and a route that
# gets multiplied by a count stated only in words.
BODY = """
Hi team, please quote the following for Level 5:

Item  Description                          Route   Sets
1     4x1C 185mm2 Cu XLPE/PVC orange       270 m   4 sets
2     1C 95mm2 earth                       1,080 m
3     4C 2.5mm2 control                    subject to survey

Total run length is approximately 2,150 m.
"""


def item(quantity, quote, derivation=None, **extra):
    source = {"quote": quote}
    if derivation is not None:
        source["derivation"] = derivation
    return {"text": "a line", "quantity": quantity, "quantity_source": source, **extra}


# --- the number is really in the document ------------------------------------------

def test_a_quantity_stated_verbatim_is_accepted():
    out = validate_text_items([item(1080, "1C 95mm2 earth                       1,080 m")], BODY)
    assert len(out.accepted) == 1 and not out.degraded


def test_a_comma_formatted_number_matches_its_plain_form():
    """`1,080` in the document is the same quantity as `1080` on the item."""
    out = validate_text_items([item(1080, "1,080 m")], BODY)
    assert len(out.accepted) == 1


def test_whitespace_differences_do_not_reject_a_real_quote():
    """A model will not reproduce the original run of spaces; content still must match."""
    out = validate_text_items([item(270, "4x1C 185mm2 Cu XLPE/PVC orange 270 m 4 sets")], BODY)
    assert len(out.accepted) == 1


def test_case_differences_do_not_reject_a_real_quote():
    out = validate_text_items([item(270, "4X1C 185MM2 CU XLPE/PVC ORANGE 270 M 4 SETS")], BODY)
    assert len(out.accepted) == 1


# --- the failures that would otherwise reach a quote --------------------------------

def test_an_invented_span_is_dropped():
    """The whole guarantee: evidence that is not in the document is not evidence."""
    out = validate_text_items([item(600, "2 1C 600mm2 earth 600 m")], BODY)
    assert not out.accepted
    assert out.degraded[0]["rule"] == "1_quote"


def test_a_quantity_absent_from_its_own_span_is_dropped():
    """A real line, quoted correctly, with a number read off some other line."""
    out = validate_text_items([item(2150, "1C 95mm2 earth                       1,080 m")], BODY)
    assert not out.accepted
    assert out.degraded[0]["rule"] == "3_absent"


def test_a_derivation_that_does_not_compute_is_dropped():
    out = validate_text_items([item(999, "270 m   4 sets", derivation="270 * 4")], BODY)
    assert not out.accepted
    assert out.degraded[0]["rule"] == "3_mismatch"


def test_a_derivation_that_is_not_arithmetic_is_dropped():
    out = validate_text_items([item(1080, "270 m   4 sets", derivation="__import__('os').getcwd()")], BODY)
    assert not out.accepted
    assert out.degraded[0]["rule"] == "3_derivation"


def test_a_derivation_naming_anything_at_all_is_dropped():
    """Text derivations are literals only -- there are no named cells to reach for."""
    out = validate_text_items([item(1080, "270 m   4 sets", derivation="C2 * 4")], BODY)
    assert not out.accepted


def test_a_missing_quote_is_dropped():
    out = validate_text_items([{"quantity": 270, "quantity_source": {}}], BODY)
    assert not out.accepted
    assert out.degraded[0]["rule"] == "2_source"


def test_a_negative_quantity_is_dropped():
    out = validate_text_items([item(-270, "270 m")], BODY)
    assert not out.accepted
    assert out.degraded[0]["rule"] == "5_negative"


def test_a_non_numeric_quantity_is_dropped():
    out = validate_text_items([item("lots", "270 m")], BODY)
    assert not out.accepted
    assert out.degraded[0]["rule"] == "5_type"


# --- the two that flag rather than drop ---------------------------------------------

def test_a_count_multiplied_in_from_prose_is_flagged_not_dropped():
    """`270 * 4` where the span states both numbers is arithmetic this can check."""
    out = validate_text_items([item(1080, "270 m   4 sets", derivation="270 * 4")], BODY)
    assert len(out.accepted) == 1
    assert INFERRED_MULTIPLIER_KEY not in out.accepted[0], "both factors are stated in the span"


def test_a_multiplier_the_span_does_not_state_is_flagged():
    """The text form of `F5 * 16`: the route is quoted, the count came from elsewhere.

    Flagged, never dropped -- and an item carrying this key is not auto-push eligible.
    """
    out = validate_text_items([item(3240, "1,080 m", derivation="1080 * 3")], BODY)
    assert len(out.accepted) == 1
    flag = out.accepted[0][INFERRED_MULTIPLIER_KEY]
    assert flag["factors"] == [3.0]


def test_two_items_claiming_one_span_are_flagged_not_dropped():
    """A main and an earth both reported as the whole of one stated route."""
    out = validate_text_items([item(1080, "1,080 m"), item(1080, "1,080 m")], BODY)
    assert len(out.accepted) == 2
    assert all(DOUBLE_COUNT_KEY in row for row in out.accepted)


def test_two_items_off_one_span_with_different_derivations_are_not_flagged():
    """Two cable types over one route is a real requirement, not a double count."""
    out = validate_text_items(
        [item(1080, "270 m   4 sets", derivation="270 * 4"),
         item(810, "270 m   4 sets", derivation="270 * 3")],
        BODY,
    )
    assert len(out.accepted) == 2
    assert not any(DOUBLE_COUNT_KEY in row for row in out.accepted)


# --- the blank case, which must survive ---------------------------------------------

def test_a_row_stating_no_quantity_is_kept_for_a_human():
    """`subject to survey` is a real line with no number. Inventing one is the bug."""
    out = validate_text_items([{"text": "4C 2.5mm2 control", "quantity": None}], BODY)
    assert len(out.accepted) == 1 and not out.degraded


def test_zero_is_a_real_quantity():
    out = validate_text_items([item(0, "Total run length is approximately 2,150 m.",
                                    derivation="2150 - 2150")], BODY)
    assert len(out.accepted) == 1


def test_a_non_object_item_is_dropped():
    out = validate_text_items(["not an item"], BODY)
    assert not out.accepted
    assert out.degraded[0]["rule"] == "shape"


@pytest.mark.parametrize("source_text", ["", None])
def test_no_source_text_means_nothing_can_be_grounded(source_text):
    """An empty document cannot support any claim, and must not silently accept them."""
    out = validate_text_items([item(270, "270 m")], source_text)
    assert not out.accepted
