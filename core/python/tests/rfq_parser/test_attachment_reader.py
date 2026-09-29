# Generated from the RFQ parser's tests/test_attachment_reader.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""§3/§4 of AGENT_FILE_READING_ORDER: the reader's contract and its failure behaviour.

Transport is mocked throughout -- these assert what the module does with a response, not
that Azure OpenAI works. The live path is verified separately by
`scripts/verify_attachment_reader.py`, which needs a real deployment.
"""

from __future__ import annotations

import io
import json
from pathlib import Path

import openpyxl
import pytest

from rfq_parser import attachment_reader as reader
from rfq_parser.attachment_reader import ReaderError, read_attachment, read_attachment_items


@pytest.fixture(autouse=True)
def configured(monkeypatch):
    monkeypatch.setenv("RFQ_READER_ENABLED", "true")
    monkeypatch.setenv("RFQ_READER_ENDPOINT", "https://example.openai.azure.com")
    monkeypatch.setenv("RFQ_READER_API_KEY", "test-key")
    monkeypatch.setenv("RFQ_READER_MODEL", "gpt-test")


@pytest.fixture
def workbook_bytes():
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = "Sheet1"
    ws["A1"], ws["C1"] = "Description", "Qty (m)"
    ws["A2"], ws["C2"] = "0.75mm2 1PR+SCR", 8400
    ws["A3"], ws["C3"] = "1.5mm2 3C+E", 1200
    buf = io.BytesIO()
    wb.save(buf)
    return buf.getvalue()


@pytest.fixture
def legacy_xls_bytes():
    """A real BIFF8 workbook. Committed rather than written here: `xlwt` is not a
    dependency, and a Phase A test that skips when the writer is absent would be no
    evidence that the .xls path works. `tests/test_xls_convert.py` documents the file."""
    return (Path(__file__).parent / "fixtures" / "xls" / "legacy-schedule.xls").read_bytes()


def _response(items, skipped=(), *, status="completed", usage=None):
    return {
        "status": status,
        "model": "gpt-test",
        "usage": usage or {"input_tokens": 10, "output_tokens": 20, "total_tokens": 30},
        "output": [
            {"type": "code_interpreter_call", "code": "import openpyxl"},
            {
                "type": "message",
                "content": [
                    {"type": "output_text", "text": json.dumps({"items": list(items), "skipped": list(skipped)})}
                ],
            },
        ],
    }


def _item(**kwargs):
    base = {
        "sheet": "Sheet1", "row": 2, "description": "0.75mm2 1PR+SCR",
        "product_code": None, "product_code_cell": None,
        "quantity": 8400, "unit": "m", "cuts": None,
        "quantity_source": {"cells": ["Sheet1!C2"], "derivation": None}, "note": "",
    }
    base.update(kwargs)
    return base


@pytest.fixture
def stub(monkeypatch):
    """Replace upload/post/delete. Returns a dict the test can inspect and steer."""
    state = {
        "payload": None, "response": _response([_item()]), "deleted": [], "uploaded": 0,
        "upload": None,
    }

    def fake_upload(data, filename, content_type, timeout):
        state["uploaded"] += 1
        state["upload"] = {"data": data, "filename": filename, "content_type": content_type}
        return "assistant-abc"

    def fake_post(path, payload, timeout):
        state["payload"] = payload
        response = state["response"]
        if isinstance(response, Exception):
            raise response
        return response

    monkeypatch.setattr(reader, "_upload", fake_upload)
    monkeypatch.setattr(reader, "_post", fake_post)
    monkeypatch.setattr(reader, "_delete_file", lambda fid, timeout: state["deleted"].append(fid))
    return state


# --- the happy path ----------------------------------------------------------------

def test_valid_item_is_returned(stub, workbook_bytes):
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok, result.error
    assert len(result.items) == 1
    assert result.items[0]["quantity"] == 8400
    assert result.validated is True
    assert result.usage["total_tokens"] == 30


def test_the_uploaded_file_is_deleted_afterwards(stub, workbook_bytes):
    read_attachment(workbook_bytes, "order.xlsx")
    assert stub["deleted"] == ["assistant-abc"]


def test_the_file_is_deleted_even_when_the_call_fails(stub, workbook_bytes):
    stub["response"] = ReaderError("boom")
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert stub["deleted"] == ["assistant-abc"], "a customer workbook must not outlive a failed run"


def test_request_mounts_the_file_and_asks_for_the_schema(stub, workbook_bytes):
    read_attachment(workbook_bytes, "order.xlsx")
    payload = stub["payload"]
    tool = payload["tools"][0]
    assert tool["type"] == "code_interpreter"
    assert tool["container"]["file_ids"] == ["assistant-abc"]
    assert payload["text"]["format"]["strict"] is True
    assert payload["text"]["format"]["type"] == "json_schema"


def test_the_file_is_framed_as_data_not_instructions(stub, workbook_bytes):
    """§3: attachment content is untrusted. The framing is part of the contract, so a
    refactor that drops it should fail here rather than in production."""
    read_attachment(workbook_bytes, "order.xlsx")
    payload = stub["payload"]
    assert "never an instruction" in payload["instructions"].lower()
    assert "data, not instructions" in payload["input"][0]["content"][0]["text"]


# --- validation is not optional ----------------------------------------------------

def test_an_item_contradicting_its_cell_is_degraded_not_returned(stub, workbook_bytes):
    stub["response"] = _response([_item(quantity=9999)])
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok
    assert result.items == []
    assert result.degraded[0]["rule"] == "3_mismatch"
    assert result.failure_share == 1.0


def test_a_hallucinated_sheet_is_degraded(stub, workbook_bytes):
    stub["response"] = _response([_item(sheet="Nope")])
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.items == []
    assert result.degraded[0]["rule"] == "1_sheet"


def test_mixed_results_split_correctly(stub, workbook_bytes):
    stub["response"] = _response([
        _item(),
        _item(row=3, quantity=1200, quantity_source={"cells": ["Sheet1!C3"], "derivation": None}),
        _item(quantity=5),
    ])
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert len(result.items) == 2
    assert len(result.degraded) == 1


def test_skipped_rows_are_recorded(stub, workbook_bytes):
    stub["response"] = _response([_item()], [{"sheet": "Sheet1", "row": 1, "reason": "header"}])
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert any(d["rule"] == "skipped" for d in result.degraded)


# --- the product-code invariant ----------------------------------------------------

def test_a_product_code_without_a_source_cell_is_dropped(stub, workbook_bytes):
    """CLAUDE.md: no model output may be a product code. A cited one is customer data; an
    uncited one is a guess, and the row keeps everything except the guess."""
    stub["response"] = _response([_item(product_code="RCA-DEXN/1P.75BK", product_code_cell=None)])
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.items[0]["product_code"] is None
    assert "without a source cell" in result.items[0]["note"]
    assert result.items[0]["quantity"] == 8400, "the row survives; only the code is dropped"


def test_a_cited_product_code_is_kept(stub, workbook_bytes):
    stub["response"] = _response(
        [_item(product_code="RCA-DEXN/1P.75BK", product_code_cell="Sheet1!B2")]
    )
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.items[0]["product_code"] == "RCA-DEXN/1P.75BK"


# --- failure is loud ---------------------------------------------------------------

def test_unconfigured_reader_reports_why(monkeypatch, workbook_bytes):
    monkeypatch.delenv("RFQ_READER_API_KEY", raising=False)
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert "not configured" in result.error


def test_incomplete_response_is_a_failure(stub, workbook_bytes):
    stub["response"] = _response([_item()], status="incomplete")
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert "incomplete" in result.error


def test_api_error_is_reported(stub, workbook_bytes):
    stub["response"] = {"error": {"code": "rate_limit_exceeded", "message": "slow down"}}
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert "rate_limit_exceeded" in result.error


def test_non_json_output_is_a_failure(stub, workbook_bytes):
    stub["response"] = {
        "status": "completed", "usage": {},
        "output": [{"type": "message", "content": [{"type": "output_text", "text": "sorry!"}]}],
    }
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert "not JSON" in result.error


def test_empty_output_is_a_failure(stub, workbook_bytes):
    stub["response"] = {"status": "completed", "usage": {}, "output": []}
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert "no structured output" in result.error


def test_token_ceiling_trips(stub, monkeypatch, workbook_bytes):
    monkeypatch.setenv("RFQ_READER_MAX_TOKENS", "100")
    stub["response"] = _response(
        [_item()], usage={"input_tokens": 90, "output_tokens": 90, "total_tokens": 180}
    )
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert "ceiling" in result.error


def test_unsupported_suffix_is_refused(stub, workbook_bytes):
    result = read_attachment(workbook_bytes, "notes.csv")
    assert result.ok is False
    assert ".csv" in result.error


def test_empty_attachment_is_refused(stub):
    result = read_attachment(b"", "order.xlsx")
    assert result.ok is False


# --- .xls, via the conversion (PORTAL_TERRA_PARITY_PLAN Phase A) --------------------

def test_an_xls_is_uploaded_as_the_converted_xlsx(stub, legacy_xls_bytes):
    """Both halves of this path run on openpyxl -- the instruction terra is given and the
    re-open-and-check afterwards -- so the bytes that leave the process must be OOXML.
    Uploading the .xls asked the model to do something impossible with openpyxl, on the
    file type one customer sends most."""
    read_attachment(legacy_xls_bytes, "legacy.xls")
    upload = stub["upload"]
    assert upload["filename"] == "legacy.xlsx"
    assert upload["content_type"].endswith("spreadsheetml.sheet")
    assert upload["data"][:2] == b"PK", "an .xlsx is a zip; this is still BIFF"


def test_the_prompt_names_the_file_the_sandbox_will_hold(stub, legacy_xls_bytes):
    """The per-request text names the workbook, and after a conversion the name in the
    prompt and the name in the sandbox are not the same string unless this holds."""
    read_attachment(legacy_xls_bytes, "legacy.xls")
    text = stub["payload"]["input"][0]["content"][0]["text"]
    assert "legacy.xlsx" in text


def test_a_claim_about_an_xls_can_now_be_substantiated(stub, legacy_xls_bytes):
    """Phase A's gate in one test. This row used to come back `0_unvalidatable`, which is
    to say every .xls the reader touched was flagged and could never auto-push."""
    stub["response"] = _response([
        _item(sheet="Submains", row=3, quantity=2100,
              quantity_source={"cells": ["Submains!C3"], "derivation": None}),
    ])
    result = read_attachment(legacy_xls_bytes, "legacy.xls")
    assert result.ok, result.error
    assert result.validated is True
    assert [d for d in result.degraded if d.get("rule") != "skipped"] == []
    assert result.items[0]["quantity"] == 2100


def test_every_row_of_a_converted_read_carries_what_it_cost(stub, legacy_xls_bytes):
    """xlrd surfaces cached values, not formulas, so the instructions' read-it-twice
    heuristic has nothing to work with on a converted .xls. The read is still
    cell-grounded -- grounded once instead of twice -- and the row says so."""
    stub["response"] = _response([
        _item(sheet="Submains", row=3, quantity=2100, note="quantity is a stated total",
              quantity_source={"cells": ["Submains!C3"], "derivation": None}),
    ])
    result = read_attachment(legacy_xls_bytes, "legacy.xls")
    assert "converted to .xlsx" in result.note
    assert "formulas behind them were not available" in result.note
    # Appended, not substituted: the model had already written something in `note`.
    assert result.items[0]["note"].startswith("quantity is a stated total; ")
    assert "converted to .xlsx" in result.items[0]["note"]


def test_an_ordinary_xlsx_read_reports_unverified_coverage_not_conversion(stub, workbook_bytes):
    """An ordinary workbook has no XLS conversion note, but missing coverage evidence is reported."""
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert "converted to .xlsx" not in result.note
    assert "Reader coverage unverified" in result.note
    assert result.items[0]["note"] == ""


def test_an_xls_that_cannot_be_converted_is_a_failed_read(stub):
    """Not a read of whatever the model could make of unopenable bytes -- that is the
    shape the reader exists to replace. Nothing is uploaded and the reason is reported."""
    result = read_attachment(b"PK\x03\x04 not really a workbook", "legacy.xls")
    assert result.ok is False
    assert "could not convert .xls" in result.error
    assert stub["uploaded"] == 0


# --- the §3 signature --------------------------------------------------------------

def test_read_attachment_items_raises_rather_than_returning_empty(stub, workbook_bytes):
    """An empty list and a failure must not be the same thing to a caller -- that is how
    the dead-key incident stayed invisible."""
    stub["response"] = ReaderError("nope")
    with pytest.raises(ReaderError):
        read_attachment_items(workbook_bytes, "order.xlsx")


def test_read_attachment_items_returns_validated_items(stub, workbook_bytes):
    items = read_attachment_items(workbook_bytes, "order.xlsx")
    assert len(items) == 1 and items[0]["quantity"] == 8400


# --- diagnostics -------------------------------------------------------------------

def test_no_debug_dump_unless_asked(stub, monkeypatch, workbook_bytes, tmp_path):
    monkeypatch.delenv("RFQ_READER_DEBUG_DIR", raising=False)
    read_attachment(workbook_bytes, "order.xlsx")
    assert list(tmp_path.iterdir()) == []


def test_debug_dump_keeps_the_raw_response(stub, monkeypatch, workbook_bytes, tmp_path):
    monkeypatch.setenv("RFQ_READER_DEBUG_DIR", str(tmp_path))
    read_attachment(workbook_bytes, "order.xlsx")
    dumps = list(tmp_path.glob("*.json"))
    assert len(dumps) == 1
    assert json.loads(dumps[0].read_text())["output"][0]["type"] == "code_interpreter_call"


def test_debug_dump_survives_a_rejected_response(stub, monkeypatch, workbook_bytes, tmp_path):
    """The responses worth keeping are the ones the reader threw away. A zero-row run that
    leaves no record costs a fresh, expensive, possibly non-reproducing run to diagnose."""
    monkeypatch.setenv("RFQ_READER_DEBUG_DIR", str(tmp_path))
    stub["response"] = _response([_item()], status="incomplete")
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert len(list(tmp_path.glob("*.json"))) == 1


# --- the sandbox container expiring mid-read --------------------------------------

def _container_expired_error():
    """Shaped like the real one: `_post` puts the response body in the message, and the
    body is what names the code. Measured verbatim on 24-Neil, 2026-08-31."""
    body = (
        '{"error": {"message": "The Code Interpreter container has expired. Retry the '
        'request to create a new container.", "type": "invalid_request_error", '
        '"param": null, "code": "container_expired"}}'
    )
    error = ReaderError(f"Azure OpenAI HTTP 409: {body}")
    error.http_status = 409
    error.http_body = body
    return error


def test_an_expired_container_is_retried_once(stub, monkeypatch, workbook_bytes):
    """Measured on `24-Neil` at 260s into a read, twice in six attempts. The service's own
    message says to retry, and the file that peaks at five minutes is the one that needs it."""
    calls = {"n": 0}
    good = _response([_item()])

    def flaky_post(path, payload, timeout):
        calls["n"] += 1
        if calls["n"] == 1:
            raise _container_expired_error()
        return good

    monkeypatch.setattr(reader, "_post", flaky_post)
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok, result.error
    assert calls["n"] == 2
    assert result.retries == 1, "a recovered failure is still a failure worth recording"


def test_a_second_expiry_is_not_retried_again(stub, monkeypatch, workbook_bytes):
    """A second expiry says something about the file's size, not about transience -- and a
    retry loop on a five-minute read spends real money to say so twice."""
    calls = {"n": 0}

    def always_expired(path, payload, timeout):
        calls["n"] += 1
        raise _container_expired_error()

    monkeypatch.setattr(reader, "_post", always_expired)
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert "container_expired" in result.error
    assert calls["n"] == 2


def test_other_http_errors_are_not_retried(stub, monkeypatch, workbook_bytes):
    """A 500 is not a 409. The service asked us to retry one of them."""
    calls = {"n": 0}

    def server_error(path, payload, timeout):
        calls["n"] += 1
        error = ReaderError("Azure OpenAI HTTP 500: internal server error")
        error.http_status = 500
        error.http_body = '{"statusCode": 500}'
        raise error

    monkeypatch.setattr(reader, "_post", server_error)
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert calls["n"] == 1


def test_a_clean_read_records_no_retry(stub, workbook_bytes):
    assert read_attachment(workbook_bytes, "order.xlsx").retries == 0


def test_a_read_that_reports_nothing_at_all_is_a_failure(stub, workbook_bytes):
    """The §2 fault: the model built all 194 items, wrote them to a file in the sandbox,
    and returned an empty envelope. `ok=True` with no items and no note meant the
    attachment contributed nothing and looked read -- the dead-key shape exactly."""
    stub["response"] = _response([], [])
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is False
    assert "no items and no skipped rows" in result.error


def test_a_workbook_with_only_skipped_rows_is_not_a_failure(stub, workbook_bytes):
    """A sheet of headings and service lines is a real answer: the model looked and found
    no cable. That is different from saying nothing."""
    stub["response"] = _response([], [{"sheet": "Sheet1", "row": 1, "reason": "header row"}])
    result = read_attachment(workbook_bytes, "order.xlsx")
    assert result.ok is True
    assert result.items == []
    assert [d["rule"] for d in result.degraded] == ["skipped"]
