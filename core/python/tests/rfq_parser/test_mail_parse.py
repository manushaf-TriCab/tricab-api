"""`rfq_parser.mail_parse`: an email's body into work items, and merging an email's lines."""

from __future__ import annotations

import pytest

import mail_fixtures
from rfq_parser import accounting, mail_envelope, mail_parse


def read(name_or_envelope):
    env = mail_fixtures.build()[name_or_envelope] if isinstance(name_or_envelope, str) else name_or_envelope
    return mail_parse.read_body(mail_envelope.normalise(env))


def envelope(html=None, text=None):
    return mail_fixtures.envelope("t", "RFQ", html=html, text=text)


def test_table_rows_become_items_with_their_own_row_numbers():
    body = read("table_in_body")
    assert [i["text"] for i in body.items] == ["4C 16mm2 XLPE/PVC", "3C 2.5mm2 XLPE/PVC", "1C 120mm2 XLPE/PVC"]
    assert body.table_count == 1
    assert [i["source"]["row_number"] for i in body.items] == [1, 2, 3]
    assert {i["source"]["file_name"] for i in body.items} == {mail_parse.BODY_FILE}
    assert all(i["source"]["input_type"] == "email_html_table" for i in body.items)


def test_a_table_cell_is_not_read_twice_as_text():
    # The same three lines as table cells and as text would be six items.
    assert len(read("table_in_body").items) == 3


def test_a_layout_table_around_a_letter_does_not_hide_the_lines():
    html = "<table><tr><td>Example Electrical</td></tr></table><p>Hello,</p><p>4C 16mm2 XLPE/PVC 120m</p>"
    body = read(envelope(html=html))
    assert body.table_count == 1
    assert [i["text"] for i in body.items] == ["4C 16mm2 XLPE/PVC 120m"]


def test_text_lines_keep_where_the_reader_found_them():
    body = read("plain_text_body")
    assert [i["text"] for i in body.items] == ["4C 16mm2 XLPE/PVC 120m", "3C 2.5mm2 XLPE/PVC 4 x 55m"]
    assert body.items[0]["source"]["input_type"] == "email_body"
    assert body.items[0]["source"]["segment_line_number"] > 0 and body.items[0]["source"]["row_number"] == 1


def test_residue_is_counted_as_skipped_rows_with_a_reason():
    body = read("not_an_rfq")
    assert body.items == [] and len(body.residue) == 2
    cov = accounting.balance(body.rows(), [], items=body.items, default_file=mail_parse.BODY_FILE)
    assert (cov.input_rows, cov.skipped, cov.kept) == (2, 2, 0)
    assert cov.skipped_rows[0]["reason"] == mail_parse.SKIP_RESIDUE
    assert body.notes == ["2 line(s) of the email text were not read as quote lines."]


def test_an_unmatched_item_is_unaccounted_so_the_coverage_does_not_balance():
    body = read("table_in_body")
    cov = accounting.balance(body.rows(), [], items=body.items, default_file=mail_parse.BODY_FILE)
    assert cov.input_rows == 3 and len(cov.unaccounted_rows) == 3


def test_lines_say_which_part_of_the_chain_they_came_from():
    body = read("reply_to_earlier_rfq")
    assert [(i["text"], i["source"]["chain_part"]) for i in body.items] == [
        ("Please add 200m of 1C 70mm2 XLPE/PVC to the quote.", 0),
        ("Quote attached for 4C 16mm2 XLPE/PVC 120m.", 1),
    ]


def test_a_forward_attributes_the_forwarded_lines_to_the_forwarded_part():
    parts = {i["source"]["chain_part"] for i in read("forwarded_chain").items}
    assert parts == {1}
    assert {i["source"]["chain_part"] for i in read("forwarded_chain_two_deep").items} == {2}


def test_table_items_carry_no_chain_part():
    assert all("chain_part" not in i["source"] for i in read("table_in_body").items)


def test_attribution_is_withheld_rather_than_guessed_when_the_parts_disagree_with_the_whole():
    # A spec line whose length sits in the first line of the forwarded part: the whole-body read folds
    # them into one line, the part-by-part read cannot, so the parts do not add up to the whole.
    text = "4C 16mm2 XLPE/PVC\n\nFrom: Jane <jane@customer-example.com>\nSent: Mon\nTo: sam@tricab.com\nSubject: x\n\n200m\n"
    body = read(envelope(text=text))
    assert [i["text"] for i in body.items] == ["4C 16mm2 XLPE/PVC 200m"]
    assert all("chain_part" not in i["source"] for i in body.items)


# ---------------------------------------------------------------------------------- merging


def line(file_name, text, code="KL-X/4C16", total=120.0, excluded=False):
    row = {
        "source": {"file_name": file_name, "original_text": text, "row_number": 1},
        "selected_product_code": code,
        "length": {"total_length": total},
    }
    if excluded:
        row["disposition"] = "excluded"
    return row


def coverage(kept=0, excluded=0, skipped=0):
    return accounting.Coverage(input_rows=kept + excluded + skipped, kept=kept, excluded=excluded, skipped=skipped)


def test_a_body_line_an_attachment_already_has_is_dropped_and_counted_as_skipped():
    lines = [line("a.xlsx", "4C 16mm2 120"), line("email body", "4C 16mm2 XLPE/PVC 120m")]
    cov = coverage(kept=2)
    merged, dropped = mail_parse.merge_lines(lines, cov)
    assert [l["source"]["file_name"] for l in merged] == ["a.xlsx"] and dropped == 1
    assert (cov.kept, cov.skipped, cov.input_rows) == (1, 1, 2)
    assert cov.skipped_rows[0]["fileName"] == "email body" and "a.xlsx" in cov.skipped_rows[0]["reason"]


def test_the_same_text_is_a_duplicate_even_without_a_code():
    lines = [line("a.xlsx", "Special cable, 100m", code="", total=None), line("email body", "special CABLE 100m", code="", total=None)]
    merged, dropped = mail_parse.merge_lines(lines, coverage(kept=2))
    assert len(merged) == 1 and dropped == 1


def test_a_different_length_of_the_same_cable_is_a_different_line():
    lines = [line("a.xlsx", "4C 16mm2 120m", total=120.0), line("email body", "4C 16mm2 200m", total=200.0)]
    merged, dropped = mail_parse.merge_lines(lines, coverage(kept=2))
    assert len(merged) == 2 and dropped == 0


def test_identical_lines_inside_one_source_are_both_kept():
    lines = [line("a.xlsx", "4C 16mm2 120m"), line("a.xlsx", "4C 16mm2 120m")]
    merged, dropped = mail_parse.merge_lines(lines, coverage(kept=2))
    assert len(merged) == 2 and dropped == 0


def test_a_later_attachment_that_is_a_copy_of_an_earlier_one_is_dropped():
    first = [line("a.xlsx", f"cable {n}", code=f"KL-{n}", total=10.0 * n) for n in range(1, 11)]
    copy = [line("b.pdf", f"cable {n}", code=f"KL-{n}", total=10.0 * n) for n in range(1, 11)]
    cov = coverage(kept=20)
    merged, dropped = mail_parse.merge_lines(first + copy, cov)
    assert [l["source"]["file_name"] for l in merged] == ["a.xlsx"] * 10 and dropped == 10
    assert (cov.kept, cov.skipped) == (10, 10)


def test_a_partial_overlap_between_attachments_is_never_dropped():
    # Two drawings' schedules can hold the same cable at the same length: both are requirements.
    first = [line("a.xlsx", f"cable {n}", code=f"KL-{n}", total=10.0 * n) for n in range(1, 11)]
    second = [line("b.xlsx", f"cable {n}", code=f"KL-{n}", total=10.0 * n) for n in range(1, 6)] + [line("b.xlsx", f"other {n}", code=f"KL-X{n}", total=7.0 * n) for n in range(1, 6)]
    merged, dropped = mail_parse.merge_lines(first + second, coverage(kept=20))
    assert len(merged) == 20 and dropped == 0


def test_the_body_is_dropped_line_by_line_even_when_only_some_lines_repeat():
    first = [line("a.xlsx", f"cable {n}", code=f"KL-{n}", total=10.0 * n) for n in range(1, 4)]
    body = [line("email body", "cable 1 10m", code="KL-1", total=10.0), line("email body", "extra", code="KL-9", total=99.0)]
    merged, dropped = mail_parse.merge_lines(first + body, coverage(kept=5))
    assert [l["source"]["original_text"] for l in merged] == ["cable 1", "cable 2", "cable 3", "extra"] and dropped == 1


def test_a_dropped_excluded_line_comes_off_the_excluded_count(monkeypatch):
    monkeypatch.setattr(mail_parse, "exclusion_reason", lambda row: "not in this quote" if row.get("disposition") == "excluded" else None)
    lines = [line("a.xlsx", "4C 16mm2 120m"), line("email body", "4C 16mm2 120m", excluded=True)]
    cov = coverage(kept=1, excluded=1)
    mail_parse.merge_lines(lines, cov)
    assert (cov.kept, cov.excluded, cov.skipped) == (1, 0, 1)


def test_the_counts_always_add_up_after_a_merge():
    lines = [line("a.xlsx", "x1"), line("email body", "x1"), line("email body", "other", code="KL-Z", total=9.0)]
    cov = coverage(kept=3, skipped=2)
    before = cov.kept + cov.excluded + cov.skipped
    mail_parse.merge_lines(lines, cov)
    assert cov.kept + cov.excluded + cov.skipped == before == cov.input_rows


@pytest.mark.parametrize("text,key", [("4C 16mm2 XLPE/PVC 120m", "4c16mm2xlpepvc120m"), ("", "")])
def test_the_text_key_ignores_case_and_punctuation(text, key):
    assert mail_parse._text_key({"source": {"original_text": text}}) == key
