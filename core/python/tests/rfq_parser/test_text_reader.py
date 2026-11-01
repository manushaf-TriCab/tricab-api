# Generated from the RFQ parser's tests/test_text_reader.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""The reader on prose: the email body and PDF text.

Transport is mocked throughout -- these assert what the module does with a response, not
that Azure OpenAI works.

The theme is that this path has a weaker guarantee than the spreadsheet one and must
behave like it knows that. A workbook claim can be re-opened and read back; a quoted span
can be real and still be the wrong span. So the tests here care most about what happens
to a number whose evidence does not hold up.
"""

from __future__ import annotations

import json

import pytest

from rfq_parser import attachment_reader as reader
from rfq_parser.attachment_reader import read_text
from rfq_parser.attachment_validate import SOURCE_CHECK_KEY

BODY = """Please quote:
1  4x1C 185mm2 Cu XLPE/PVC orange   270 m   4 sets
2  1C 95mm2 earth                   1,080 m
Total 2,150 m.
"""


@pytest.fixture(autouse=True)
def configured(monkeypatch):
    monkeypatch.setenv("RFQ_READER_ENABLED", "true")
    monkeypatch.setenv("RFQ_READER_TEXT_ENABLED", "true")
    monkeypatch.setenv("RFQ_READER_ENDPOINT", "https://example.openai.azure.com")
    monkeypatch.setenv("RFQ_READER_API_KEY", "test-key")
    monkeypatch.setenv("RFQ_READER_MODEL", "gpt-test")


def _item(**kwargs):
    base = {
        "description": "1C 95mm2 earth",
        "product_code": None, "product_code_quote": None,
        "quantity": 1080, "unit": "m", "cuts": None,
        "quantity_source": {"quote": "1C 95mm2 earth                   1,080 m", "derivation": None},
        "note": "",
    }
    base.update(kwargs)
    return base


def _response(items, skipped=None):
    return {
        "status": "completed",
        "model": "gpt-test",
        "usage": {"input_tokens": 10, "output_tokens": 20, "total_tokens": 30},
        "output": [
            {
                "type": "message",
                "content": [
                    {"type": "output_text",
                     "text": json.dumps({"items": items, "skipped": skipped or []})}
                ],
            }
        ],
    }


@pytest.fixture
def stub(monkeypatch):
    state = {"payload": None, "response": _response([_item()])}

    def fake_post(path, payload, timeout):
        state["payload"] = payload
        response = state["response"]
        if isinstance(response, Exception):
            raise response
        return response

    monkeypatch.setattr(reader, "_post", fake_post)
    return state


# --- the flags ----------------------------------------------------------------------

def test_text_reading_is_off_unless_its_own_flag_is_set(monkeypatch, stub):
    """RFQ_READER_ENABLED alone must not switch on the weaker path."""
    monkeypatch.delenv("RFQ_READER_TEXT_ENABLED", raising=False)
    result = read_text(BODY)
    assert not result.ok
    assert "not configured" in result.error


def test_text_reading_is_off_when_the_reader_itself_is_off(monkeypatch, stub):
    monkeypatch.setenv("RFQ_READER_ENABLED", "false")
    assert read_text(BODY).ok is False


def test_empty_text_is_not_sent_anywhere(stub):
    assert read_text("   ").ok is False
    assert stub["payload"] is None, "an empty body must not cost a call"


# --- the request ---------------------------------------------------------------------

def test_no_code_interpreter_is_offered(stub):
    """There is nothing to open, and a writable sandbox is how the empty-list fault happened."""
    read_text(BODY)
    assert "tools" not in stub["payload"]


def test_the_body_is_framed_as_data_not_instructions(stub):
    read_text(BODY)
    sent = stub["payload"]["input"][0]["content"][0]["text"]
    assert "not instructions" in sent
    assert BODY.strip() in sent


def test_the_strict_quote_schema_is_requested(stub):
    read_text(BODY)
    fmt = stub["payload"]["text"]["format"]
    assert fmt["strict"] is True
    props = fmt["schema"]["properties"]["items"]["items"]["properties"]
    assert "quote" in props["quantity_source"]["properties"]
    assert "cells" not in props["quantity_source"]["properties"]


# --- grounding: the whole point of this path ------------------------------------------

def test_a_grounded_quantity_is_returned(stub):
    result = read_text(BODY)
    assert result.ok, result.error
    assert [i["quantity"] for i in result.items] == [1080]
    assert result.validated is True


def test_a_quantity_whose_span_is_not_in_the_body_is_kept_with_a_warning(stub):
    """The model quoting text that does not exist is the failure this path must catch."""
    stub["response"] = _response([_item(
        quantity=5000,
        quantity_source={"quote": "3  1C 400mm2 earth  5,000 m", "derivation": None},
    )])
    result = read_text(BODY)
    assert result.ok
    assert [i["quantity"] for i in result.items] == [5000]
    assert result.items[0][SOURCE_CHECK_KEY]["code"] == "1_quote"


def test_a_quantity_not_present_in_its_own_span_is_kept_with_a_warning(stub):
    stub["response"] = _response([_item(
        quantity=2150,
        quantity_source={"quote": "1C 95mm2 earth                   1,080 m", "derivation": None},
    )])
    result = read_text(BODY)
    assert [i["quantity"] for i in result.items] == [2150]
    assert result.items[0][SOURCE_CHECK_KEY]["code"] == "3_absent"


def test_a_route_times_sets_derivation_is_accepted(stub):
    stub["response"] = _response([_item(
        description="4x1C 185mm2",
        quantity=1080,
        quantity_source={"quote": "4x1C 185mm2 Cu XLPE/PVC orange   270 m   4 sets",
                         "derivation": "270 * 4"},
    )])
    result = read_text(BODY)
    assert [i["quantity"] for i in result.items] == [1080]


# --- product codes --------------------------------------------------------------------

def test_a_product_code_without_a_quote_is_dropped_but_the_row_survives(stub):
    """CLAUDE.md: no model output may be a product code. The line is still a real line."""
    stub["response"] = _response([_item(product_code="XL-PAXN/1C95OR", product_code_quote=None)])
    result = read_text(BODY)
    assert len(result.items) == 1
    assert result.items[0]["product_code"] is None
    assert "without a source quote" in result.items[0]["note"]


def test_a_product_code_the_customer_wrote_is_kept(stub):
    stub["response"] = _response([_item(
        product_code="XL-PAXN/1C95OR", product_code_quote="1C 95mm2 earth")])
    result = read_text(BODY)
    assert result.items[0]["product_code"] == "XL-PAXN/1C95OR"


# --- failing loudly -------------------------------------------------------------------

def test_silence_is_a_failure_not_an_empty_document(stub):
    """The §7 fault: the model builds the answer and returns nothing. Never silent."""
    stub["response"] = _response([], [])
    result = read_text(BODY)
    assert not result.ok
    assert "not returned in the response" in result.error


def test_a_skipped_only_read_is_a_real_answer(stub):
    """A body of greetings and disclaimers legitimately has no line items."""
    stub["response"] = _response([], [{"quote": "Kind regards", "reason": "signature"}])
    result = read_text(BODY)
    assert result.ok
    assert result.items == []
    assert result.degraded[0]["rule"] == "skipped"


def test_an_incomplete_response_is_an_error(stub):
    stub["response"] = {"status": "incomplete", "incomplete_details": {"reason": "max_output_tokens"}}
    result = read_text(BODY)
    assert not result.ok
    assert "incomplete" in result.error


def test_a_token_ceiling_breach_is_an_error(stub, monkeypatch):
    monkeypatch.setenv("RFQ_READER_MAX_TOKENS", "10")
    result = read_text(BODY)
    assert not result.ok
    assert "token ceiling" in result.error


def test_non_json_structured_output_is_an_error(stub):
    stub["response"] = {
        "status": "completed",
        "output": [{"type": "message", "content": [{"type": "output_text", "text": "not json"}]}],
    }
    result = read_text(BODY)
    assert not result.ok
    assert "not JSON" in result.error


# --- PDFs ----------------------------------------------------------------------------

# Long enough to clear _PDF_TEXT_FLOOR, which is what separates "has a text layer" from
# "is a scan". A real cable schedule clears it many times over.
PDF_SCHEDULE = """Cable Schedule - Level 5 Switchroom - Rev C
Please quote the following, delivered to site:
1  4x1C 185mm2 Cu XLPE orange   270 m   4 sets
2  1C 95mm2 earth   1080 m
3  4C 2.5mm2 control   subject to survey
Delivery to site by 30 September 2026. Payment terms 30 days end of month.
Contact Jane Doe on 03 9000 0000 with any queries about this enquiry.
All cable to be supplied on non-returnable drums unless noted otherwise.
"""


def _pdf_bytes(text: str) -> bytes:
    """A real one-page PDF with a text layer, built with reportlab if available."""
    pytest.importorskip("reportlab")
    import io as _io
    from reportlab.pdfgen import canvas
    buf = _io.BytesIO()
    c = canvas.Canvas(buf)
    y = 800
    for line in text.splitlines():
        c.drawString(40, y, line)
        y -= 14
    c.save()
    return buf.getvalue()


def test_a_pdf_with_a_text_layer_is_grounded_against_that_text(stub, monkeypatch):
    """The common case: terra reads the text, and every quantity is checked against it."""
    pdf = _pdf_bytes(PDF_SCHEDULE)
    stub["response"] = _response([_item(
        quantity=1080,
        quantity_source={"quote": "1C 95mm2 earth   1080 m", "derivation": None},
    )])
    result = reader.read_pdf(pdf, "schedule.pdf")
    assert result.ok, result.error
    assert result.validated is True, "a text-layer PDF must be grounded"
    assert [i["quantity"] for i in result.items] == [1080]


def test_a_pdf_quantity_with_no_supporting_text_is_warned(stub):
    pdf = _pdf_bytes(PDF_SCHEDULE)
    stub["response"] = _response([_item(
        quantity=9999,
        quantity_source={"quote": "3  1C 400mm2 earth   9999 m", "derivation": None},
    )])
    result = reader.read_pdf(pdf, "schedule.pdf")
    assert result.items[0][SOURCE_CHECK_KEY]["code"] == "1_quote"


def test_a_scanned_pdf_comes_back_ungrounded(stub, monkeypatch):
    """No text layer means nothing to check against, and the result must say so."""
    monkeypatch.setattr(reader, "extract_pdf_text", lambda data: "")
    monkeypatch.setattr(reader, "_upload", lambda *a, **k: "file-1")
    monkeypatch.setattr(reader, "_delete_file", lambda *a, **k: None)
    result = reader.read_pdf(b"%PDF-1.4 fake", "scan.pdf")
    assert result.ok, result.error
    assert result.validated is False, "a scanned read must never claim to be validated"


@pytest.mark.skip(reason="The mail path's auto-push gate is not here; the platform's is LineApprovalPolicy (ungrounded rows need approval).")
def test_ungrounded_rows_are_marked_so_they_cannot_auto_push(stub, monkeypatch):
    monkeypatch.setattr(reader, "extract_pdf_text", lambda data: "")
    monkeypatch.setattr(reader, "_upload", lambda *a, **k: "file-1")
    monkeypatch.setattr(reader, "_delete_file", lambda *a, **k: None)
    result = reader.read_pdf(b"%PDF-1.4 fake", "scan.pdf")
    work = reader.to_text_work_items(result, "scan.pdf")
    assert work[0]["source"]["input_type"] == "reader_ungrounded"

    from rfq_parser.mail_ingest import _flag_reason
    row = {"status": "matched", "final_product_code": "XL-PAXN/1C95OR",
           "length": {"total_length": 1080}, "source": work[0]["source"]}
    assert _flag_reason(row) == "read from a picture or scan - confirm every quantity"


def test_the_uploaded_scan_is_deleted_afterwards(stub, monkeypatch):
    deleted = []
    monkeypatch.setattr(reader, "extract_pdf_text", lambda data: "")
    monkeypatch.setattr(reader, "_upload", lambda *a, **k: "file-1")
    monkeypatch.setattr(reader, "_delete_file", lambda fid, timeout: deleted.append(fid))
    reader.read_pdf(b"%PDF-1.4 fake", "scan.pdf")
    assert deleted == ["file-1"]


def test_pdfs_need_the_text_flag_too(monkeypatch, stub):
    monkeypatch.delenv("RFQ_READER_TEXT_ENABLED", raising=False)
    assert reader.read_pdf(b"%PDF-1.4", "x.pdf").ok is False


# --- .docx / .csv / .txt: the inputs that had no reader at all -------------------------

def _docx_bytes(paragraphs=(), table_rows=()):
    """A real .docx, built with the same library `file_parser.parse_docx` reads."""
    import io as _io
    from docx import Document

    document = Document()
    for text in paragraphs:
        document.add_paragraph(text)
    if table_rows:
        table = document.add_table(rows=0, cols=len(table_rows[0]))
        for row in table_rows:
            cells = table.add_row().cells
            for cell, value in zip(cells, row):
                cell.text = value
    buffer = _io.BytesIO()
    document.save(buffer)
    return buffer.getvalue()


DOCX_PARAGRAPHS = ("Ten A", "4 drums x 240mm Alu flex ( 66m )", "1 x 95mm Alu flex Earth ( 66m )")


def test_a_docx_is_read_as_the_document_the_parser_would_see(stub):
    """Paragraphs, then table rows joined with ` | ` -- the shape `parse_docx` builds its
    rows from. A reading checked against a different rendering is not checked at all."""
    data = _docx_bytes(DOCX_PARAGRAPHS, [("Submains", "Qnty"), ("35mm2 4C + E Cu", "110 m")])
    text = reader.extract_docx_text(data)
    assert "4 drums x 240mm Alu flex ( 66m )" in text
    assert "35mm2 4C + E Cu | 110 m" in text


def test_a_docx_attachment_reaches_terra_and_is_grounded(stub):
    data = _docx_bytes(DOCX_PARAGRAPHS)
    stub["response"] = _response([_item(
        description="1 x 95mm Alu flex Earth",
        quantity=66,
        quantity_source={"quote": "1 x 95mm Alu flex Earth ( 66m )", "derivation": None},
    )])
    result = reader.read_document(data, "Addendum 005 - Cabling details.docx")
    assert result.ok, result.error
    assert [i["quantity"] for i in result.items] == [66]
    assert result.validated is True


def test_a_docx_quote_that_is_not_in_the_document_is_warned(stub):
    """The grounding contract has to survive the new route, or the route is worthless."""
    data = _docx_bytes(DOCX_PARAGRAPHS)
    stub["response"] = _response([_item(
        description="invented", quantity=9999,
        quantity_source={"quote": "8 drums x 400mm Alu flex ( 900m )", "derivation": None},
    )])
    result = reader.read_document(data, "x.docx")
    assert result.items[0][SOURCE_CHECK_KEY]["status"] == "failed"


def test_a_csv_is_sent_verbatim_not_re_rendered(stub):
    """Re-emitting the rows would change the quoting and separators, and terra's quotes are
    checked against the text we hold -- re-rendering is how a true quote looks invented."""
    raw = b'Date:,28/04/2026,,,,\r\nItem,"4C+E, 35mm2",110\r\n'
    reader.read_document(raw, "quote.csv")
    sent = stub["payload"]["input"][0]["content"][0]["text"]
    assert 'Item,"4C+E, 35mm2",110' in sent


def test_a_cp1252_csv_still_decodes(stub):
    """Excel writes the platform codepage far more often than UTF-8, and a decode that
    throws would lose the whole attachment."""
    raw = "Cable,90°C rated,250\r\n".encode("cp1252")
    assert "90°C rated" in reader.extract_plain_text(raw)


def test_a_txt_attachment_is_read(stub):
    result = reader.read_document(b"1C 95mm2 earth                   1,080 m\n", "rfq.txt")
    assert result.ok, result.error


def test_an_unreadable_document_fails_loudly_rather_than_returning_nothing(stub):
    """An empty item list reads as "no cables in this file". It must not be how a broken
    attachment looks."""
    result = reader.read_document(b"%PDF-1.4 not really a docx", "broken.docx")
    assert result.ok is False
    assert "no text could be extracted" in result.error
    assert stub["payload"] is None, "a document with no text must not cost a call"


def test_documents_ride_the_text_flag(monkeypatch, stub):
    monkeypatch.delenv("RFQ_READER_TEXT_ENABLED", raising=False)
    assert reader.document_reader_enabled() is False
    result = reader.read_document(_docx_bytes(DOCX_PARAGRAPHS), "x.docx")
    assert result.ok is False
    assert "not configured" in result.error


def test_health_can_tell_you_whether_the_body_is_being_read(stub):
    """`suffixes` lists spreadsheets only, so before this the health endpoint answered
    "is terra reading our email?" wrongly by omission."""
    config = reader.reader_config()
    assert config["text_enabled"] is True
    assert config["reads_email_body"] is True
    assert ".docx" in config["text_suffixes"] and ".pdf" in config["text_suffixes"]


def test_health_shows_the_text_path_off_when_only_the_base_flag_is_set(monkeypatch, stub):
    monkeypatch.delenv("RFQ_READER_TEXT_ENABLED", raising=False)
    config = reader.reader_config()
    assert config["enabled"] is True, "spreadsheets still on"
    assert config["text_enabled"] is False
    assert config["text_suffixes"] == []


# --- the customer terra proposes ------------------------------------------------------

BODY_WITH_CUSTOMER = """Hi Daniel,

Can you kindly assist with quote for the below for Brolec Pty Ltd by tomorrow.

1  4x1C 185mm2 Cu XLPE/PVC orange   270 m
"""


def _response_with_customer(customer, items=None):
    return {
        "status": "completed", "model": "gpt-test",
        "usage": {"input_tokens": 10, "output_tokens": 20, "total_tokens": 30},
        "output": [{"type": "message", "content": [{"type": "output_text", "text": json.dumps({
            "items": items if items is not None else [_item()],
            "skipped": [],
            "customer": customer,
        })}]}],
    }


def test_a_grounded_customer_name_comes_back(stub):
    stub["response"] = _response_with_customer(
        {"name": "Brolec Pty Ltd", "quote": "quote for the below for Brolec Pty Ltd by tomorrow"}
    )
    result = read_text(BODY_WITH_CUSTOMER)
    assert result.ok, result.error
    assert result.customer == "Brolec Pty Ltd"
    assert result.customer_rejected == ""


def test_a_customer_whose_span_is_not_in_the_body_is_discarded(stub):
    """The same evidence rule as a quantity. A name with no span behind it is a name the
    model may have produced from nowhere, and a quote to the wrong company is not
    recoverable."""
    stub["response"] = _response_with_customer(
        {"name": "Acme Cables", "quote": "please quote for Acme Cables"}
    )
    result = read_text(BODY_WITH_CUSTOMER)
    assert result.customer == ""
    assert "not in the source" in result.customer_rejected


def test_a_customer_name_outside_its_own_span_is_discarded(stub):
    """A real span quoted as evidence for a different name is evidence for nothing."""
    stub["response"] = _response_with_customer(
        {"name": "Acme Cables", "quote": "Can you kindly assist with quote"}
    )
    result = read_text(BODY_WITH_CUSTOMER)
    assert result.customer == ""
    assert "not inside its own quoted span" in result.customer_rejected


def test_no_customer_is_the_ordinary_answer(stub):
    stub["response"] = _response_with_customer(None)
    result = read_text(BODY_WITH_CUSTOMER)
    assert result.ok
    assert result.customer == ""
    assert result.customer_rejected == ""


def test_a_body_naming_only_the_customer_is_not_a_failed_read(stub):
    """"Please quote the attached for X" is a real message with no line items of its own,
    and must not be reported as the reader returning nothing."""
    stub["response"] = _response_with_customer(
        {"name": "Brolec Pty Ltd", "quote": "for Brolec Pty Ltd by tomorrow"}, items=[]
    )
    result = read_text(BODY_WITH_CUSTOMER)
    assert result.ok, result.error
    assert result.customer == "Brolec Pty Ltd"


EARTH_EMAIL = '''<html><head><style>.x {width:999px}</style></head><body>
<p>Please quote the following.</p>
<table><tr><td>6mm V-90</td><td>100</td></tr></table>
<table><tr><th colspan="2">Earth Cable</th></tr>
<tr><th>CABLE TYPE</th><th>TOTAL</th></tr>
<tr><td><span>6mm</span> V-90</td><td>50</td></tr>
<tr><td>16mm X-HF-110</td><td>40</td></tr></table></body></html>'''


def test_original_html_and_subject_reach_reader_with_grounded_earth_description(stub):
    from tricab_cable_rules.extractor import extract_entities

    stub["response"] = _response([
        _item(description="6mm V-90 earth", quantity=50,
              quantity_source={"quote": "6mm V-90 50", "derivation": None}),
        _item(description="16mm X-HF-110 earth", quantity=40,
              quantity_source={"quote": "16mm X-HF-110 40", "derivation": None}),
    ])
    result = read_text(EARTH_EMAIL, is_html=True, subject="RFQ - 2027 Project")
    sent = stub["payload"]["input"][0]["content"][0]["text"]
    assert EARTH_EMAIL in sent
    assert "Subject: RFQ - 2027 Project" in sent
    assert result.ok and result.validated
    assert len(result.items) == 2
    assert not result.degraded
    work = reader.to_text_work_items(result, "email body")
    assert work[0]["text"] == "6mm V-90 earth"
    assert work[0]["metadata"]["table_quantity"] == 50
    assert extract_entities(work[0]["text"], known_codes=[])["fields"]["earth_only"] is True


@pytest.mark.parametrize("quote,quantity", [("6mm V-90 51", 51), ("999", 999)])
def test_html_evidence_warns_on_invented_quantity_and_style_numbers(stub, quote, quantity):
    stub["response"] = _response([_item(quantity=quantity,
        quantity_source={"quote": quote, "derivation": None})])
    result = read_text(EARTH_EMAIL, is_html=True)
    assert result.ok
    assert result.items[0][SOURCE_CHECK_KEY]["code"] == "1_quote"


def test_html_evidence_preserves_inline_text_and_decodes_entities():
    evidence = reader.email_evidence_text(
        '<p>Cu &amp; Al</p><table><tr><td>V-<b>90</b></td><td>50&nbsp;m</td></tr></table>'
    )
    assert " ".join(evidence.split()) == "Cu & Al V-90 50 m"


# --- pictures pasted into the body (Ref 42387) -----------------------------------------
#
# The customer typed the cable type as a heading and pasted the rows under it as a
# screenshot. Read apart, the rows lose the heading; these pin that the body read carries
# each picture in its place, and that a row read off a picture is never passed off as one
# checked against the text.

PNG = b"\x89PNG\r\n\x1a\n" + b"0" * 64

PASTED_BODY = (
    "<p>Power Cable - Orange Sheath 110deg</p>"
    '<p><img width="311" height="100" src="cid:image001.png@01DD57E5.927C2640"></p>'
    "<p>Control Cable - Black Sheath</p>"
    '<p><img src="cid:image002.png@01DD57E5.927C2640"></p>'
    "<p>Also 4C 10mm2 Cu XLPE 2000m</p>"
    '<img src="cid:tc_logo.png" alt="TriCab">'
)


def _pictures():
    return [
        reader.BodyImage("image001.png", PNG, "image/png", "image001.png@01DD57E5.927C2640"),
        reader.BodyImage("image002.png", PNG, "image/png", "<image002.png@01DD57E5.927C2640>"),
    ]


def _picture_row(number, **kwargs):
    base = _item(
        description="2.5mm2 2C+E orange sheath 110deg power cable",
        quantity=217,
        quantity_source={"quote": "217 2.5 2C+E", "derivation": None},
    )
    base["source_image"] = number
    base.update(kwargs)
    return base


def _typed_row(**kwargs):
    base = _item(
        description="4C 10mm2 Cu XLPE",
        quantity=2000,
        quantity_source={"quote": "4C 10mm2 Cu XLPE 2000m", "derivation": None},
    )
    base["source_image"] = None
    base.update(kwargs)
    return base


def test_pasted_pictures_are_sent_in_place_under_their_headings(stub):
    read_text(PASTED_BODY, is_html=True, images=_pictures())

    content = stub["payload"]["input"][0]["content"]
    document = content[0]["text"]
    assert "Orange Sheath 110deg</p><p>[IMAGE 1]</p>" in document
    assert "Black Sheath</p><p>[IMAGE 2]</p>" in document
    assert "cid:image001" not in document.split("<<<EVIDENCE_TEXT")[0]
    # A tag for a picture that was not sent is left alone, not numbered.
    assert 'src="cid:tc_logo.png"' in document
    # The markers are for reading only; quotes are never checked against them.
    evidence = document.split("<<<EVIDENCE_TEXT")[1]
    assert "[IMAGE" not in evidence

    images = [block for block in content if block["type"] == "input_image"]
    assert len(images) == 2
    assert images[0]["image_url"].startswith("data:image/png;base64,")
    labels = [block["text"] for block in content if block["type"] == "input_text"]
    assert "[IMAGE 1] 'image001.png':" in labels
    assert "[IMAGE 2] 'image002.png':" in labels

    item_schema = stub["payload"]["text"]["format"]["schema"]["properties"]["items"]["items"]
    assert "source_image" in item_schema["required"]


def test_without_pictures_the_request_is_exactly_as_before(stub):
    read_text(PASTED_BODY, is_html=True)

    content = stub["payload"]["input"][0]["content"]
    assert len(content) == 1
    assert "[IMAGE" not in content[0]["text"]
    item_schema = stub["payload"]["text"]["format"]["schema"]["properties"]["items"]["items"]
    assert "source_image" not in item_schema["properties"]


def test_pictures_are_only_placed_in_html(stub):
    read_text("Power Cable - Orange Sheath", images=_pictures())

    assert len(stub["payload"]["input"][0]["content"]) == 1


def test_a_row_read_off_a_picture_is_kept_unchecked_and_ungrounded(stub):
    stub["response"] = _response([_picture_row(1), _typed_row()])

    result = read_text(PASTED_BODY, is_html=True, images=_pictures())

    assert result.ok and result.validated
    picture, typed = result.items
    assert picture[reader.BODY_IMAGE_KEY] == "image001.png"
    assert "source_image" not in picture
    # Not "failed": nothing was there to check it against, and the row says so by its
    # provenance rather than by a check that never ran.
    assert SOURCE_CHECK_KEY not in picture
    assert reader.BODY_IMAGE_KEY not in typed and SOURCE_CHECK_KEY not in typed
    assert result.check_status == "unavailable"

    picture_row, typed_row = reader.to_text_work_items(result, "email body")
    assert picture_row["source"]["input_type"] == "reader_ungrounded"
    assert picture_row["source"]["image_name"] == "image001.png"
    assert picture_row["metadata"]["quantity_cells"] == ["217 2.5 2C+E"]
    assert typed_row["source"]["input_type"] == "text_reader"
    assert "image_name" not in typed_row["source"]


def test_picture_rows_keep_their_place_among_the_typed_rows(stub):
    stub["response"] = _response([_typed_row(), _picture_row(2), _typed_row(quantity=None)])

    result = read_text(PASTED_BODY, is_html=True, images=_pictures())

    assert [bool(i.get(reader.BODY_IMAGE_KEY)) for i in result.items] == [False, True, False]


def test_a_typed_row_cannot_escape_its_check_by_claiming_no_picture(stub):
    stub["response"] = _response([_typed_row(quantity_source={"quote": "217 2.5 2C+E", "derivation": None},
                                             quantity=217)])

    result = read_text(PASTED_BODY, is_html=True, images=_pictures())

    assert result.items[0][SOURCE_CHECK_KEY]["code"] == "1_quote"


def test_a_picture_number_that_was_never_sent_is_still_held(stub):
    stub["response"] = _response([_picture_row(7)])

    result = read_text(PASTED_BODY, is_html=True, images=_pictures())

    assert result.items[0][reader.BODY_IMAGE_KEY] == "image 7"
    row = reader.to_text_work_items(result, "email body")[0]
    assert row["source"]["input_type"] == "reader_ungrounded"


def test_a_picture_that_cannot_be_sent_is_named_not_dropped_silently(stub):
    gif = reader.BodyImage("banner.gif", b"GIF89a" + b"0" * 32, "image/gif", "image001.png@01DD57E5.927C2640")

    result = read_text(PASTED_BODY, is_html=True, images=[gif, _pictures()[1]])

    content = stub["payload"]["input"][0]["content"]
    assert len([b for b in content if b["type"] == "input_image"]) == 1
    # The one picture that was sent is numbered 1, wherever it sits.
    assert "Black Sheath</p><p>[IMAGE 1]</p>" in content[0]["text"]
    assert "pasted image 'banner.gif' was not read: unsupported image type" in result.note


def test_a_picture_with_no_tag_in_the_body_is_read_and_says_it_lost_its_heading(stub):
    loose = reader.BodyImage("image009.png", PNG, "image/png", "image009.png@01DD57E5.927C2640")

    result = read_text(PASTED_BODY, is_html=True, images=[loose])

    assert len([b for b in stub["payload"]["input"][0]["content"] if b["type"] == "input_image"]) == 1
    assert "'image009.png' could not be placed in the text" in result.note


def test_body_image_content_ids_reads_every_cid_reference():
    assert reader.body_image_content_ids(PASTED_BODY) == {
        "image001.png@01dd57e5.927c2640", "image002.png@01dd57e5.927c2640", "tc_logo.png",
    }
