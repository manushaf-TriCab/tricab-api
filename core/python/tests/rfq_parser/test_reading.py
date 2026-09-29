"""`POST /v1/read` (plan 3.5): one document through the model reader, for a reader job.

Platform-owned. The reader's transport is stubbed at `read_one_file`, the one
function the source's worker calls, so these tests are about the boundary: when a
read may happen, how its failures are typed, and that no provider body leaves.
"""

from __future__ import annotations

import base64

import pytest

from rfq_parser import app as service
from rfq_parser import contract, reading

TOKEN = "test-internal-token-0123456789"
CID = "corr-0123456789abcdef"
XLSX = b"PK\x03\x04"  # enough for the signature check; the read itself is stubbed


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    # The reader is configured, so a spreadsheet has a route.
    monkeypatch.setenv("RFQ_READER_ENABLED", "true")
    monkeypatch.setenv("RFQ_READER_ENDPOINT", "https://reader.invalid")
    monkeypatch.setenv("RFQ_READER_API_KEY", "k")
    monkeypatch.setenv("RFQ_READER_MODEL", "gpt-test")
    monkeypatch.setattr(contract.intake, "_check_zip", lambda *a: None)
    service.app.config["TESTING"] = True
    return service.app.test_client()


def _read(client, name="schedule.xlsx", data=XLSX, **extra):
    body = {
        "contract": contract.CONTRACT,
        "correlationId": CID,
        "deadlineMs": 600_000,
        "document": {"documentPublicId": "doc-1", "fileName": name, "contentType": "", "contentBase64": base64.b64encode(data).decode()},
        "options": {"allowModel": True, "maxModelCalls": 20},
        "context": {"subject": "RFQ 123", "attachments": [name]},
        **extra,
    }
    return client.post("/v1/read", json=body, headers={"Authorization": f"Bearer {TOKEN}"})


def _reader_item(text: str) -> dict:
    return {"text": text, "context": [], "metadata": {}, "source": {"input_type": "attachment_reader", "file_name": "schedule.xlsx", "sheet": "S", "row_number": 2}}


def test_a_read_returns_matched_lines_with_what_produced_them(client, monkeypatch):
    seen = {}

    def fake_read(name, data, content_type="", *, vision_ocr=None, is_inline=False):
        seen["name"] = name
        return [_reader_item("4C 16mm2 Cu XLPE/PVC 120m")], ["Attachment 'schedule.xlsx': converted note."]

    monkeypatch.setattr(reading, "read_one_file", fake_read)
    response = _read(client)
    body = response.get_json()

    assert response.status_code == 200, body
    assert seen["name"] == "schedule.xlsx"
    assert len(body["lines"]) == 1 and body["lines"][0]["source"]["input_type"] == "attachment_reader"
    assert body["provider"]["mode"] == "model" and body["provider"]["answeredBy"] == "reader"
    assert body["provider"]["model"] == "gpt-test"
    assert body["coverage"]["measured"] is False
    assert "Attachment 'schedule.xlsx': converted note." in body["notes"]


def test_the_translator_runs_with_the_message_context(client, monkeypatch):
    captured = {}
    real = reading.pipeline.match_items

    def spy(items, *, budget=None, message_context=None, degraded=None):
        captured["context"] = message_context
        captured["limit"] = budget["limit"]
        return real(items, budget=budget, message_context=message_context, degraded=degraded)

    monkeypatch.setattr(reading, "read_one_file", lambda *a, **k: ([_reader_item("4C 16mm2 Cu XLPE/PVC 120m")], []))
    monkeypatch.setattr(reading.pipeline, "match_items", spy)
    _read(client)
    assert captured["context"] == {"subject": "RFQ 123", "attachments": ["schedule.xlsx"], "residue": []}
    assert captured["limit"] == 20


def test_no_read_without_a_reader_on(client, monkeypatch):
    monkeypatch.setenv("RFQ_READER_ENABLED", "false")
    called = []
    monkeypatch.setattr(reading, "read_one_file", lambda *a, **k: called.append(1) or ([], []))
    response = _read(client)
    assert response.status_code == 415 and response.get_json()["error"]["code"] == "unsupported_format"
    assert called == [], "a read must never fall back to a deterministic parse and call it a read"


def test_a_read_is_a_model_call_so_php_must_allow_one(client):
    response = _read(client, options={"allowModel": False, "maxModelCalls": 0})
    assert response.status_code == 400 and response.get_json()["error"]["code"] == "invalid_request"


@pytest.mark.parametrize(
    "error,code,words",
    [
        ('Azure OpenAI HTTP 401: {"error": {"message": "key sk-live-secret is invalid"}}', "provider_rejected", "refused the reader's credentials"),
        ('Azure OpenAI HTTP 429: {"error": "quota for tricab-ai2 exhausted"}', "provider_unavailable", "throttling"),
        ('Azure OpenAI HTTP 503: <html>upstream body</html>', "provider_unavailable", "had an error"),
        ("Azure OpenAI request failed: URLError: <urlopen error timed out>", "provider_unavailable", "could not be reached"),
        ('model returned no structured output', "provider_unavailable", "could not be used"),
    ],
)
def test_a_failed_read_is_typed_and_carries_no_provider_body(client, monkeypatch, error, code, words):
    monkeypatch.setattr(reading, "read_one_file", lambda *a, **k: ([], [f"Attachment 'schedule.xlsx' could not be read: {error}"]))
    response = _read(client)
    body = response.get_json()
    assert body["error"]["code"] == code
    assert words in body["error"]["message"]
    for leaked in ("sk-live", "tricab-ai2", "upstream body", "urlopen", "{"):
        assert leaked not in body["error"]["message"]


def test_a_credential_failure_outranks_a_transient_one():
    assert reading._worst(["provider_unavailable", "provider_rejected"]) == "provider_rejected"


def test_the_vision_fallback_note_is_cleaned_too():
    note = "Image 'scan.png' could not be read by the reader (Azure OpenAI HTTP 500: secret body); vision OCR was used instead."
    safe, code = reading.safe_note(note)
    assert safe == "Image 'scan.png' could not be read by the reader (the model service had an error); vision OCR was used instead."
    assert code == "provider_unavailable"


def test_an_image_route_includes_vision_ocr(monkeypatch):
    monkeypatch.setenv("RFQ_READER_ENABLED", "false")
    monkeypatch.setenv("OPENROUTER_API_KEY", "k")
    monkeypatch.setenv("VISION_OCR_ENABLED", "true")
    png = contract.intake.check("d", "scan.png", "", b"\x89PNG\r\n\x1a\n" + b"\0" * 16)
    assert reading.reader_route(png) is True
    monkeypatch.setenv("VISION_OCR_ENABLED", "false")
    assert reading.reader_route(png) is False


def test_a_translator_failure_is_reported_without_its_body():
    note = reading.degraded_reason({"degraded": True, "reason": "Azure OpenAI HTTP 500: upstream secret"}, CID)
    assert note and "secret" not in note and "had an error" in note
    assert reading.degraded_reason({}, CID) is None
