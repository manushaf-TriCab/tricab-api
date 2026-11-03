"""The parser's model transport (plan M7 O1-O3): Claude on Foundry, and the reader's managed identity.

Nothing here reaches the network or imports the Anthropic SDK: `finance_ai.provider._foundry_messages_create` is
faked, as the finance tests fake it."""

import json

import pytest

from finance_ai import provider
from rfq_parser import attachment_reader, foundry, llm_fallback, vision_ocr


@pytest.fixture(autouse=True)
def configured(monkeypatch):
    monkeypatch.setenv("FOUNDRY_ENDPOINT", "https://foundry.invalid")
    for name in ("RFQ_LLM_ENABLED", "RFQ_LLM_MODEL", "RFQ_VISION_MODEL", "RFQ_VISION_ESCALATION_MODEL", "RFQ_LLM_EFFORT", "APP_ENV",
                 "RFQ_READER_API_KEY", "IDENTITY_ENDPOINT", "IDENTITY_HEADER", "VISION_OCR_ENABLED"):
        monkeypatch.delenv(name, raising=False)


@pytest.fixture
def sent(monkeypatch):
    calls: list[dict] = []
    answers: list[dict] = []

    def create(payload, timeout):
        calls.append({"payload": payload, "timeout": timeout})
        return answers.pop(0)

    monkeypatch.setattr(provider, "_foundry_messages_create", create)
    calls_answers = type("Sent", (), {"calls": calls, "answers": answers})
    return calls_answers


def _message(text, stop_reason="end_turn", model="claude-haiku-5-5"):
    return {"model": model, "stop_reason": stop_reason, "content": [{"type": "text", "text": text}], "usage": {"input_tokens": 1}}


def test_the_calls_are_on_only_with_a_foundry_endpoint(monkeypatch):
    assert foundry.enabled() and llm_fallback.openrouter_enabled()
    monkeypatch.setenv("RFQ_LLM_ENABLED", "false")
    assert not foundry.enabled()
    monkeypatch.delenv("RFQ_LLM_ENABLED")
    monkeypatch.setenv("FOUNDRY_ENDPOINT", "")
    assert not foundry.enabled() and not llm_fallback.openrouter_enabled()


def test_a_chat_request_becomes_a_messages_request_without_the_parameters_claude_5_refuses():
    request = foundry.to_messages_request({
        "model": "claude-haiku-5-5",
        "messages": [
            {"role": "system", "content": "You transcribe."},
            {"role": "user", "content": [
                {"type": "text", "text": "Read this."},
                {"type": "image_url", "image_url": {"url": "data:image/png;base64,QUJD"}},
            ]},
        ],
        "temperature": 0,
        "max_tokens": 500,
        "provider": {"require_parameters": True},
        "response_format": {"type": "json_schema", "json_schema": {"name": "x", "strict": True, "schema": {"type": "object"}}},
    })
    assert request["system"] == "You transcribe."
    blocks = request["messages"][0]["content"]
    assert [b["type"] for b in blocks] == ["image", "text"]
    assert blocks[0]["source"] == {"type": "base64", "media_type": "image/png", "data": "QUJD"}
    assert request["output_config"] == {"effort": "low", "format": {"type": "json_schema", "schema": {"type": "object"}}}
    assert request["max_tokens"] == 500
    for refused in ("temperature", "top_p", "top_k", "tool_choice", "tools", "provider", "response_format"):
        assert refused not in request


def test_chat_answers_in_the_shape_the_generated_code_reads(sent):
    sent.answers.append(_message('{"ok": true}'))
    data, error = foundry.chat({"model": "m", "messages": [{"role": "user", "content": "hi"}]}, timeout=7)
    assert error is None
    assert data["choices"][0] == {"message": {"content": '{"ok": true}'}, "finish_reason": "stop"}
    assert sent.calls[0]["timeout"] == 7


def test_a_cut_off_answer_reads_as_length(sent):
    sent.answers.append(_message("{", stop_reason="max_tokens"))
    data, _ = foundry.chat({"model": "m", "messages": []}, timeout=1)
    assert data["choices"][0]["finish_reason"] == "length"


def test_a_refusal_and_a_provider_error_are_errors_the_reader_classifies(sent, monkeypatch):
    sent.answers.append({"stop_reason": "refusal", "stop_details": {"category": "x"}, "content": []})
    assert foundry.chat({"model": "m", "messages": []}, timeout=1)[0] is None

    def refused(payload, timeout):
        raise provider.ProviderError("provider_rejected", "The model provider refused the request (HTTP 400).")

    monkeypatch.setattr(provider, "_foundry_messages_create", refused)
    data, error = foundry.chat({"model": "m", "messages": []}, timeout=1)
    assert data is None and "HTTP 400" in error and "request failed" in error


def test_the_line_fallback_runs_on_the_haiku_deployment_and_reads_its_answer(sent):
    entry = {"description": "4C 16mm2 XLPE", "core_count": 4, "cross_section_mm2": 16}
    sent.answers.append(_message(json.dumps({"entries": [entry]})))
    result = llm_fallback.parse_with_openrouter("4C 16mm2 XLPE")
    assert sent.calls[0]["payload"]["model"] == "claude-haiku-5-5"
    assert "temperature" not in sent.calls[0]["payload"]
    assert result.ok and result.model == "claude-haiku-5-5" and len(result.entries) == 1, result


def test_the_model_selectors_are_settings(sent, monkeypatch):
    monkeypatch.setenv("RFQ_LLM_MODEL", "my-deployment")
    sent.answers.append(_message(json.dumps({"entries": []})))
    llm_fallback.parse_with_openrouter("x")
    assert sent.calls[0]["payload"]["model"] == "my-deployment"


def test_vision_ocr_reads_with_claude_and_escalates_to_sonnet(sent):
    weak = {"overall_confidence": 0.5, "rows": [{"section": "", "description": "4C 16mm2", "length": 10, "unit": "m",
                                                   "confidence": 0.5, "uncertain": True, "notes": ""}]}
    strong = {"overall_confidence": 0.97, "rows": [{"section": "", "description": "4C 16mm2", "length": 10, "unit": "m",
                                                      "confidence": 0.97, "uncertain": False, "notes": ""}]}
    sent.answers += [_message(json.dumps(weak)), _message(json.dumps(strong), model="claude-sonnet-5-5")]
    result = vision_ocr.extract_table_with_qwen(b"\x89PNG\r\n\x1a\nrest", "image/png")
    assert result.ok and result.escalated
    assert [c["payload"]["model"] for c in sent.calls] == ["claude-haiku-5-5", "claude-sonnet-5-5"]
    image = sent.calls[0]["payload"]["messages"][0]["content"][0]
    assert image["type"] == "image" and image["source"]["media_type"] == "image/png"
    assert "temperature" not in sent.calls[0]["payload"]


def test_vision_is_off_without_a_foundry_endpoint(monkeypatch):
    monkeypatch.setenv("FOUNDRY_ENDPOINT", "")
    assert not vision_ocr.vision_ocr_enabled()


# --- the reader's credential (O3) --------------------------------------------------------

def _reader_env(monkeypatch):
    monkeypatch.setenv("RFQ_READER_ENABLED", "true")
    monkeypatch.setenv("RFQ_READER_ENDPOINT", "https://reader.invalid")
    monkeypatch.setenv("RFQ_READER_MODEL", "terra")


def test_the_reader_runs_on_the_managed_identity_without_a_key(monkeypatch):
    _reader_env(monkeypatch)
    assert not attachment_reader.reader_enabled()
    assert attachment_reader.reader_config()["auth"] is None
    monkeypatch.setenv("IDENTITY_ENDPOINT", "http://localhost:8081/msi/token")
    monkeypatch.setenv("IDENTITY_HEADER", "h")
    assert attachment_reader.reader_enabled()
    assert attachment_reader.reader_config()["auth"] == "managed-identity"
    assert "has_key" not in attachment_reader.reader_config()


def test_the_reader_sends_a_bearer_token_for_the_azure_openai_resource(monkeypatch):
    monkeypatch.setenv("IDENTITY_ENDPOINT", "http://localhost:8081/msi/token")
    monkeypatch.setenv("IDENTITY_HEADER", "h")
    asked = []
    monkeypatch.setattr(provider, "_managed_identity_token", lambda resource=provider.FOUNDRY_TOKEN_RESOURCE: asked.append(resource) or "tok")
    assert attachment_reader._auth_headers() == {"Authorization": "Bearer tok"}
    assert asked == ["https://cognitiveservices.azure.com"]


def test_a_missing_token_is_a_reader_error_not_a_crash(monkeypatch):
    def none(resource=provider.FOUNDRY_TOKEN_RESOURCE):
        raise provider._TokenError("no identity")

    monkeypatch.setattr(provider, "_managed_identity_token", none)
    with pytest.raises(attachment_reader.ReaderError, match="no identity"):
        attachment_reader._auth_headers()


def test_the_key_is_a_local_fallback_and_refused_in_production(monkeypatch):
    monkeypatch.setenv("RFQ_READER_API_KEY", "dev-key")
    assert attachment_reader._auth_headers() == {"api-key": "dev-key"}
    monkeypatch.setenv("APP_ENV", "production")
    assert foundry.reader_auth_mode() is None


def test_the_token_cache_is_per_resource(monkeypatch):
    class Reply:
        def __init__(self, token):
            self.token = token

        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def read(self):
            return json.dumps({"access_token": self.token, "expires_on": "9999999999"}).encode()

    seen = []

    def urlopen(request, timeout):
        seen.append(request.full_url)
        return Reply("t-" + str(len(seen)))

    monkeypatch.setattr("urllib.request.urlopen", urlopen)
    monkeypatch.setattr(provider, "_token", {"value": None, "expires_at": 0.0})
    monkeypatch.setattr(provider, "_other_tokens", {})
    monkeypatch.setenv("IDENTITY_ENDPOINT", "http://localhost:8081/msi/token")
    monkeypatch.setenv("IDENTITY_HEADER", "h")
    first = provider._managed_identity_token()
    other = provider._managed_identity_token("https://cognitiveservices.azure.com")
    assert first != other and len(seen) == 2
    assert provider._managed_identity_token("https://cognitiveservices.azure.com") == other and len(seen) == 2
