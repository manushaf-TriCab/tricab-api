# Generated from the RFQ parser's tests/test_image_reader.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""The reader on images: the weakest guarantee in the system, behaving like it knows.

Transport is mocked throughout -- these assert what the module does with a response, not
that Azure OpenAI works.

An image has no cells and no text layer. `validate_text_items` has nothing to run on, so
nothing runs, and every row comes back `validated=False`. The tests that matter here are
therefore not about accuracy, which no test can establish. They are about the two things
that must hold whatever the model says: an unchecked row is *labelled* unchecked and is
held by both push gates, and a route that displaces a working reader (`vision_ocr`) does
not switch itself on.
"""

from __future__ import annotations

import base64
import json

import pytest

from rfq_parser import attachment_reader as reader


PNG = b"\x89PNG\r\n\x1a\n" + b"0" * 512
JPEG = b"\xff\xd8\xff" + b"0" * 512
GIF = b"GIF89a" + b"0" * 512


@pytest.fixture(autouse=True)
def configured(monkeypatch):
    monkeypatch.setenv("RFQ_READER_ENABLED", "true")
    monkeypatch.setenv("RFQ_READER_IMAGE_ENABLED", "true")
    monkeypatch.setenv("RFQ_READER_ENDPOINT", "https://example.openai.azure.com")
    monkeypatch.setenv("RFQ_READER_API_KEY", "test-key")
    monkeypatch.setenv("RFQ_READER_MODEL", "gpt-test")
    monkeypatch.delenv("RFQ_READER_IMAGE_DETAIL", raising=False)
    monkeypatch.delenv("RFQ_READER_MAX_IMAGE_MB", raising=False)


def _item(**kwargs):
    base = {
        "description": "4x1C 185mm2 Cu XLPE/PVC orange",
        "product_code": None, "product_code_quote": None,
        "quantity": 270, "unit": "m", "cuts": None,
        "quantity_source": {"quote": "270 m", "derivation": None},
        "note": "",
    }
    base.update(kwargs)
    return base


def _response(items, skipped=None):
    return {
        "status": "completed",
        "model": "gpt-test",
        "usage": {"input_tokens": 900, "output_tokens": 40, "total_tokens": 940},
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
    state = {"payload": None, "response": _response([_item()]), "calls": 0}

    def fake_post(path, payload, timeout):
        state["payload"] = payload
        state["calls"] += 1
        response = state["response"]
        if isinstance(response, Exception):
            raise response
        return response

    monkeypatch.setattr(reader, "_post", fake_post)
    return state


# --- the flag -------------------------------------------------------------------------

def test_images_need_their_own_flag(monkeypatch, stub):
    """RFQ_READER_ENABLED is the spreadsheet path. It must not drag images along."""
    monkeypatch.delenv("RFQ_READER_IMAGE_ENABLED", raising=False)
    result = reader.read_image(PNG, "schedule.png")
    assert not result.ok
    assert "not configured" in result.error
    assert stub["calls"] == 0


def test_the_text_flag_does_not_switch_images_on(monkeypatch, stub):
    """A checked path and an unchecked one do not share a switch."""
    monkeypatch.delenv("RFQ_READER_IMAGE_ENABLED", raising=False)
    monkeypatch.setenv("RFQ_READER_TEXT_ENABLED", "true")
    assert reader.read_image(PNG, "schedule.png").ok is False


def test_the_image_flag_does_not_switch_the_text_path_on(monkeypatch, stub):
    """And not the other way round either: three inputs, three guarantees, three flags."""
    monkeypatch.delenv("RFQ_READER_TEXT_ENABLED", raising=False)
    assert reader.text_reader_enabled() is False
    assert reader.image_reader_enabled() is True


def test_images_are_off_when_the_reader_itself_is_off(monkeypatch, stub):
    monkeypatch.setenv("RFQ_READER_ENABLED", "false")
    assert reader.read_image(PNG, "schedule.png").ok is False


# --- what is refused before it costs a call -------------------------------------------

def test_an_empty_attachment_is_not_sent_anywhere(stub):
    assert reader.read_image(b"", "schedule.png").ok is False
    assert stub["calls"] == 0


def test_an_unsupported_image_type_is_refused(stub):
    result = reader.read_image(GIF, "schedule.gif", "image/gif")
    assert not result.ok
    assert "unsupported image type" in result.error
    assert stub["calls"] == 0


def test_an_oversized_image_is_refused_locally(monkeypatch, stub):
    """Refused here rather than after paying for the upload and the timeout."""
    monkeypatch.setenv("RFQ_READER_MAX_IMAGE_MB", "0.0001")
    result = reader.read_image(PNG, "schedule.png")
    assert not result.ok
    assert "over the" in result.error
    assert stub["calls"] == 0


# --- the request ----------------------------------------------------------------------

def _image_block(payload):
    return next(b for b in payload["input"][0]["content"] if b["type"] == "input_image")


def test_the_image_is_sent_inline_as_a_data_url(stub):
    reader.read_image(PNG, "schedule.png")
    url = _image_block(stub["payload"])["image_url"]
    assert url.startswith("data:image/png;base64,")
    assert base64.b64decode(url.split(",", 1)[1]) == PNG


def test_the_type_is_sniffed_not_trusted(stub):
    """Outlook labels pasted images inconsistently; `image/jpg` is not a real type."""
    reader.read_image(JPEG, "pasted.jpg", "image/jpg")
    assert _image_block(stub["payload"])["image_url"].startswith("data:image/jpeg;base64,")


def test_a_mislabelled_image_is_read_by_its_bytes(stub):
    reader.read_image(PNG, "image001.png", "application/octet-stream")
    assert _image_block(stub["payload"])["image_url"].startswith("data:image/png;base64,")


def test_detail_is_high_because_a_schedule_is_small_text(stub):
    reader.read_image(PNG, "schedule.png")
    assert _image_block(stub["payload"])["detail"] == "high"


def test_detail_can_be_dropped_entirely(monkeypatch, stub):
    """The escape hatch if `detail` ever 400s on tricab-ai2: an app setting, not a deploy."""
    monkeypatch.setenv("RFQ_READER_IMAGE_DETAIL", "")
    reader.read_image(PNG, "schedule.png")
    assert "detail" not in _image_block(stub["payload"])


def test_no_code_interpreter_is_offered(stub):
    """There is no file to open, and a writable sandbox is how the empty-list fault happened."""
    reader.read_image(PNG, "schedule.png")
    assert "tools" not in stub["payload"]


def test_nothing_is_uploaded_to_the_files_api(monkeypatch, stub):
    """The image rides in the request. No file id means no orphaned customer data."""
    monkeypatch.setattr(reader, "_upload", lambda *a, **k: pytest.fail("uploaded an image"))
    monkeypatch.setattr(reader, "_delete_file", lambda *a, **k: pytest.fail("deleted a file"))
    assert reader.read_image(PNG, "schedule.png").ok


def test_the_image_is_framed_as_data_not_instructions(stub):
    reader.read_image(PNG, "schedule.png")
    sent = next(b for b in stub["payload"]["input"][0]["content"] if b["type"] == "input_text")
    assert "not instructions" in sent["text"]
    assert "schedule.png" in sent["text"]


def test_a_cross_section_is_named_as_not_a_length(stub):
    """The measured image failure: 300 under 'Active (mm2)' read as 300 metres."""
    reader.read_image(PNG, "schedule.png")
    sent = next(b for b in stub["payload"]["input"][0]["content"] if b["type"] == "input_text")
    assert "cross-section is not a length" in sent["text"]


def test_the_strict_quote_schema_is_requested(stub):
    reader.read_image(PNG, "schedule.png")
    fmt = stub["payload"]["text"]["format"]
    assert fmt["strict"] is True
    assert "quantity_source" in fmt["schema"]["properties"]["items"]["items"]["properties"]


# --- the guarantee, or the absence of one ---------------------------------------------

def test_an_image_read_never_claims_to_be_validated(stub):
    result = reader.read_image(PNG, "schedule.png")
    assert result.ok, result.error
    assert result.validated is False


def test_a_quote_that_matches_nothing_is_still_kept(stub):
    """Nothing is dropped for failing a check, because no check is possible.

    This is the honest shape of the bargain and the reason the row is held for a person:
    the text path would discard this item, and here there is no document to discard it
    against.
    """
    stub["response"] = _response([_item(quantity=9999, quantity_source={
        "quote": "nothing in any document says this", "derivation": None})])
    result = reader.read_image(PNG, "schedule.png")
    assert [i["quantity"] for i in result.items] == [9999]
    assert result.validated is False


def test_a_product_code_with_no_quote_is_still_dropped(stub):
    """The one rule that survives without a document: a code with no provenance is a guess,
    and CLAUDE.md forbids a model-authored code reaching the resolver."""
    stub["response"] = _response([_item(product_code="XL-PAXN/1C95OR", product_code_quote="")])
    result = reader.read_image(PNG, "schedule.png")
    assert result.items[0]["product_code"] is None
    assert "without a source quote" in result.items[0]["note"]


def test_rows_are_stamped_ungrounded(stub):
    result = reader.read_image(PNG, "schedule.png")
    work = reader.to_text_work_items(result, "schedule.png")
    assert work[0]["source"]["input_type"] == "reader_ungrounded"
    assert work[0]["metadata"]["quantity_cells"] == ["270 m"]


@pytest.mark.skip(reason="The mail path's auto-push gate is not here; the platform's is LineApprovalPolicy (ungrounded rows need approval).")
def test_both_push_gates_hold_an_image_row(stub):
    """The email worker's gate and the app's sync gate. A row that no deterministic check
    ever confirmed must not leave on either path without a person."""
    from rfq_parser.mail_ingest import _flag_reason
    from rfq_parser.pipeline import row_push_block_reason

    work = reader.to_text_work_items(reader.read_image(PNG, "schedule.png"), "schedule.png")
    source = work[0]["source"]

    assert _flag_reason({
        "status": "matched", "final_product_code": "XL-PAXN/1C185OR",
        "length": {"total_length": 270}, "source": source,
    }) == "read from a picture or scan - confirm every quantity"

    assert row_push_block_reason({"status": "matched", "source": source}) is not None


def test_an_approved_image_row_can_be_synced(stub):
    """The gate is a hold for review, not a wall: approving the row is what clears it,
    exactly as it is for a vision_ocr row."""
    from rfq_parser.pipeline import row_push_block_reason
    work = reader.to_text_work_items(reader.read_image(PNG, "schedule.png"), "schedule.png")
    row = {"status": "matched", "final_status": "approved", "source": work[0]["source"]}
    assert row_push_block_reason(row) is None


# --- failing loudly --------------------------------------------------------------------

def test_an_empty_answer_is_a_failure_not_an_empty_schedule(stub):
    """Silence is not an answer. An empty item list reads as 'no cables in this image',
    which is the shape of the fault §7 of READER_STABILISATION chased."""
    stub["response"] = _response([], [])
    result = reader.read_image(PNG, "schedule.png")
    assert not result.ok
    assert "no items" in result.error


def test_an_incomplete_response_is_an_error(stub):
    stub["response"] = {"status": "incomplete", "incomplete_details": {"reason": "max_output_tokens"}}
    result = reader.read_image(PNG, "schedule.png")
    assert not result.ok
    assert "incomplete" in result.error


def test_a_token_ceiling_breach_is_an_error(stub, monkeypatch):
    monkeypatch.setenv("RFQ_READER_MAX_TOKENS", "100")
    assert reader.read_image(PNG, "schedule.png").ok is False


def test_a_transport_failure_is_reported_not_swallowed(stub):
    stub["response"] = reader.ReaderError("Azure OpenAI request failed: HTTP 400")
    result = reader.read_image(PNG, "schedule.png")
    assert not result.ok
    assert "HTTP 400" in result.error


# --- health ----------------------------------------------------------------------------

def test_health_says_which_model_is_reading_images(stub):
    config = reader.reader_config()
    assert config["image_enabled"] is True
    assert config["image_suffixes"] == [".jpeg", ".jpg", ".png", ".webp"]


def test_health_shows_images_off_when_only_the_base_flag_is_set(monkeypatch, stub):
    monkeypatch.delenv("RFQ_READER_IMAGE_ENABLED", raising=False)
    config = reader.reader_config()
    assert config["image_enabled"] is False
    assert config["image_suffixes"] == []
