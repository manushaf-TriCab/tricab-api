"""The Claude-on-Foundry path (plan O4): the per-purpose switch, the request each purpose sends, ar_classify's
rewrite, and the failures.

No test reaches the network or needs the Anthropic SDK: `provider._foundry_messages_create` is stubbed with a fake
Messages response, and `urllib.request.urlopen` fails any test that gets further. The SDK error mapping is checked
only where the SDK is installed. Keys and emails are invented.
"""

from __future__ import annotations

import base64
import json

import pytest

from finance_ai import contract, provider, purposes, registry
from finance_ai.app import app
from finance_ai.purposes import ap as ap_purposes
from finance_ai.purposes import ar as ar_purposes
from finance_ai.purposes import task as task_purposes

TOKEN = "internal-token-for-tests"
CORRELATION = "corr-0123456789abcdef"
PDF = {"name": "invoice.pdf", "content_type": "application/pdf", "data": base64.b64encode(b"%PDF-1.4 invented").decode()}
PNG = {"name": "advice.png", "content_type": "image/png", "data": base64.b64encode(b"\x89PNG invented").decode()}
TIFF = {"name": "scan.tiff", "content_type": "image/tiff", "data": base64.b64encode(b"II*\x00 invented").decode()}

CATEGORIES = [
    {"value": "remittance_advice", "label": "Remittance", "description": "The customer says they have paid."},
    {"value": "dispute", "label": "Dispute", "description": ""},
    {"value": "other", "label": "Other", "description": "Anything else."},
]
METHODS = [{"value": "email", "label": "Email", "description": ""}, {"value": "phone", "label": "Phone", "description": "Call them."}]
EMAIL = {"sender_name": "Pat", "sender_email": "pat@customer.example.test", "subject": "Payment", "body": "We paid INV-1."}

INVOICE_ANSWER = {
    "is_invoice": True, "classification_reason": "Tax invoice.", "classification_confidence": 0.93,
    "invoice_no": "INV-42", "vendor": "Acme Pty Ltd", "company": "TriCab (Australia) Pty Ltd", "vendor_email": None,
    "tax_id": None, "invoice_date": "01/09/2026", "due_date": None, "po_number": None, "currency": "$",
    "net_amount": 100, "tax_amount": 10, "total_amount": 110, "notes": None,
}

ALL_NAMES = [*ap_purposes.NAMES, *ar_purposes.NAMES]


@pytest.fixture(autouse=True)
def no_network(monkeypatch):
    def refuse(*args, **kwargs):  # pragma: no cover - only reached by a bug
        raise AssertionError("a finance_ai test reached the network")

    monkeypatch.setattr("urllib.request.urlopen", refuse)


@pytest.fixture(autouse=True)
def environment(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    monkeypatch.setenv(provider.KEY_SETTING, "sk-dummy-openrouter-key")
    for name in [provider.PROVIDER_SETTING, *(f"{provider.PROVIDER_SETTING}_{n.upper()}" for n in ALL_NAMES)]:
        monkeypatch.delenv(name, raising=False)
    for name in ("AR_CONFIDENCE_THRESHOLD", "AR_REVIEW_THRESHOLD", "APP_ENV", "IDENTITY_ENDPOINT", "IDENTITY_HEADER"):
        monkeypatch.delenv(name, raising=False)


@pytest.fixture(autouse=True)
def registered():
    purposes.register_ap()
    purposes.register_ar()
    yield
    for name in ALL_NAMES:
        registry.unregister(name)


@pytest.fixture()
def client():
    app.config["TESTING"] = True
    return app.test_client()


def ask(client, purpose, data):
    return client.post(
        f"/v1/complete/{purpose}",
        json={"contract": contract.CONTRACT, "correlationId": CORRELATION, "data": data},
        headers={"Authorization": f"Bearer {TOKEN}", "X-Correlation-Id": CORRELATION},
    )


def message(answer, stop_reason="end_turn", **extra):
    text = answer if isinstance(answer, str) else json.dumps(answer)
    return {
        "content": [{"type": "thinking", "thinking": "", "signature": "x"}, {"type": "text", "text": text}],
        "stop_reason": stop_reason,
        "usage": {"input_tokens": 1200, "output_tokens": 80},
        **extra,
    }


def stub_foundry(monkeypatch, response):
    calls = []

    def fake_create(payload, timeout):
        calls.append({"payload": payload, "timeout": timeout})
        if isinstance(response, Exception):
            raise response
        return response

    monkeypatch.setattr(provider, "_foundry_messages_create", fake_create)
    return calls


def refuse_openrouter(monkeypatch):
    def fake_post(*args, **kwargs):  # pragma: no cover - only reached by a bug
        raise AssertionError("a Foundry call went to OpenRouter")

    monkeypatch.setattr(provider, "_post_json", fake_post)


# --- the switch -----------------------------------------------------------------------------------------------------


def test_every_ap_and_ar_purpose_has_a_foundry_setup_but_runs_on_openrouter_by_default():
    for name in ALL_NAMES:
        purpose = registry.get(name)
        assert purpose.foundry is not None, name
        assert purpose.foundry.model == "claude-haiku-5-5"
        assert provider.provider_for(purpose) == "openrouter", name


def test_one_purpose_is_switched_by_its_own_setting(monkeypatch):
    monkeypatch.setenv("FINANCE_AI_PROVIDER_AR_CLASSIFY", "Foundry ")
    assert provider.provider_for(registry.get("ar_classify")) == "foundry"
    assert provider.provider_for(registry.get("ar_prose")) == "openrouter"


def test_the_shared_setting_moves_every_purpose_with_a_setup_and_a_purpose_setting_wins(monkeypatch):
    monkeypatch.setenv("FINANCE_AI_PROVIDER", "foundry")
    monkeypatch.setenv("FINANCE_AI_PROVIDER_AP_EXTRACT", "openrouter")
    assert provider.provider_for(registry.get("ar_remittance")) == "foundry"
    assert provider.provider_for(registry.get("ap_extract")) == "openrouter"


def test_the_shared_setting_leaves_tri_task_on_openrouter(monkeypatch):
    monkeypatch.setenv("FINANCE_AI_PROVIDER", "foundry")
    task = next(p for p in task_purposes.PURPOSES)
    assert task.foundry is None
    assert provider.provider_for(task) == "openrouter"


def test_a_mistyped_setting_is_ignored_with_a_warning(monkeypatch, caplog):
    monkeypatch.setenv("FINANCE_AI_PROVIDER_AR_PROSE", "foundary")
    assert provider.provider_for(registry.get("ar_prose")) == "openrouter"
    assert "foundary" in caplog.text


def test_a_purpose_without_a_setup_named_for_foundry_is_unavailable(monkeypatch):
    plain = registry.Purpose("plain", lambda data: "prompt", {"type": "object"})
    with pytest.raises(provider.ProviderError) as caught:
        provider.complete_json(plain, "prompt", correlation_id=CORRELATION, provider="foundry")
    assert caught.value.code == "unavailable"


# --- the request ----------------------------------------------------------------------------------------------------


def _schemas(schema):
    """Every object schema inside a structured-output schema."""
    if isinstance(schema, dict):
        if schema.get("type") == "object":
            yield schema
        for value in schema.values():
            yield from _schemas(value)
    elif isinstance(schema, list):
        for item in schema:
            yield from _schemas(item)


def test_every_foundry_schema_meets_the_structured_output_rules():
    data = {"categories": CATEGORIES, "methods": METHODS}
    for name in ALL_NAMES:
        for obj in _schemas(registry.get(name).foundry.schema_for(data)):
            assert obj.get("additionalProperties") is False, name
            assert sorted(obj["required"]) == sorted(obj["properties"]), name


def test_a_document_goes_first_as_a_document_block_with_no_sampling_settings(client, monkeypatch):
    refuse_openrouter(monkeypatch)
    monkeypatch.setenv("FINANCE_AI_PROVIDER_AP_CLASSIFY_EXTRACT", "foundry")
    calls = stub_foundry(monkeypatch, message(INVOICE_ANSWER))
    response = ask(client, "ap_classify_extract", {"subject": "Invoice", "sender": "Acme", "file": PDF})

    assert response.status_code == 200, response.get_json()
    result = response.get_json()["result"]
    # The source's own post-processing still runs on Claude's answer.
    assert result["currency"] == "AUD" and result["invoice_date"] == "2026-09-01" and result["is_invoice"] is True
    payload = calls[0]["payload"]
    assert payload["model"] == "claude-haiku-5-5"
    assert not {"temperature", "top_p", "top_k", "thinking", "response_format"} & set(payload)
    assert payload["output_config"]["effort"] == "low"
    assert payload["output_config"]["format"] == {"type": "json_schema", "schema": ap_purposes.FOUNDRY_CLASSIFY_EXTRACT_SCHEMA}
    document, text = payload["messages"][0]["content"]
    assert document == {"type": "document", "source": {"type": "base64", "media_type": "application/pdf", "data": PDF["data"]}}
    assert text["type"] == "text" and "accounts-payable mailbox" in text["text"]
    assert len(payload["messages"]) == 1 and payload["messages"][0]["role"] == "user"


def test_an_image_is_an_image_block_and_a_tiff_is_refused():
    assert provider.foundry_block(registry.file_part(PNG)) == {
        "type": "image", "source": {"type": "base64", "media_type": "image/png", "data": PNG["data"]},
    }
    with pytest.raises(provider.ProviderError) as caught:
        provider.foundry_block(registry.file_part(TIFF))
    assert caught.value.code == "provider_rejected"


@pytest.mark.parametrize("name, effort", [
    ("ap_classify_extract", "low"), ("ap_extract", "low"), ("ar_classify", "low"), ("ar_remittance", "low"),
    ("ap_vendor_email_draft", "medium"), ("ap_reply_draft", "medium"), ("ap_reply_suggest", "medium"),
    ("ar_prose", "medium"), ("ar_reply_draft", "medium"),
])
def test_reading_runs_at_low_effort_and_drafting_at_medium(name, effort):
    assert registry.get(name).foundry.effort == effort


def test_a_draft_keeps_its_parser_on_foundry(client, monkeypatch):
    monkeypatch.setenv("FINANCE_AI_PROVIDER_AR_REPLY_DRAFT", "foundry")
    calls = stub_foundry(monkeypatch, message({"subject": "", "body": "Subject: Re: x\n\nHi Pat,\nThanks.\nRegards,\n[Your Name]"}))
    response = ask(client, "ar_reply_draft", {**EMAIL, "instruction": "Thank them."})
    assert response.status_code == 200, response.get_json()
    assert response.get_json()["result"] == {"subject": "Re: Payment", "body": "Hi Pat,\nThanks.\nRegards,"}
    assert calls[0]["payload"]["output_config"]["effort"] == "medium"


# --- ar_classify ----------------------------------------------------------------------------------------------------


def classify_answer(category="remittance_advice", confidence=0.95, review=0.1, method="email"):
    return {"category": category, "category_confidence": confidence, "response_type": method, "needs_review_probability": review}


def test_classify_on_foundry_is_a_chat_call_with_the_options_as_enums(client, monkeypatch):
    refuse_openrouter(monkeypatch)
    monkeypatch.setenv("FINANCE_AI_PROVIDER_AR_CLASSIFY", "foundry")
    calls = stub_foundry(monkeypatch, message(classify_answer()))
    response = ask(client, "ar_classify", {**EMAIL, "categories": CATEGORIES, "methods": METHODS})

    assert response.status_code == 200, response.get_json()
    assert response.get_json()["result"] == {
        "follow_up_category": "remittance_advice", "proposed_response_type": "email", "needs_review": False,
        "classification_confidence": 0.95, "follow_up_status": "in_progress",
    }
    payload = calls[0]["payload"]
    schema = payload["output_config"]["format"]["schema"]
    assert schema["properties"]["category"]["enum"] == ["remittance_advice", "dispute", "other"]
    assert schema["properties"]["response_type"]["enum"] == ["email", "phone"]
    text = payload["messages"][0]["content"][-1]["text"]
    # The criteria are the Decisions API's: the description, or the label when it is empty.
    assert "- remittance_advice: The customer says they have paid." in text and "- dispute: Dispute" in text
    assert "<email>\nFrom: Pat <pat@customer.example.test>" in text


@pytest.mark.parametrize("confidence, review, status, needs_review", [
    (0.95, 0.1, "in_progress", False),
    (0.79, 0.1, "needs_review", False),
    (0.95, 0.5, "needs_review", True),
])
def test_classify_on_foundry_meets_jevs_thresholds(client, monkeypatch, confidence, review, status, needs_review):
    monkeypatch.setenv("FINANCE_AI_PROVIDER_AR_CLASSIFY", "foundry")
    stub_foundry(monkeypatch, message(classify_answer(confidence=confidence, review=review)))
    result = ask(client, "ar_classify", {**EMAIL, "categories": CATEGORIES, "methods": METHODS}).get_json()["result"]
    assert (result["follow_up_status"], result["needs_review"]) == (status, needs_review)


def test_classify_on_foundry_falls_back_like_jev_and_other_is_reviewed(client, monkeypatch):
    monkeypatch.setenv("FINANCE_AI_PROVIDER_AR_CLASSIFY", "foundry")
    stub_foundry(monkeypatch, message(classify_answer(category="invented", method="pigeon")))
    result = ask(client, "ar_classify", {**EMAIL, "categories": CATEGORIES, "methods": METHODS}).get_json()["result"]
    assert result["follow_up_category"] == "other" and result["proposed_response_type"] == "email"
    assert result["follow_up_status"] == "needs_review"


def test_classify_on_openrouter_still_calls_the_decisions_api(client, monkeypatch):
    calls = []

    def fake_post(url, payload, api_key, timeout):
        calls.append(url)
        return 200, json.dumps({"answers": {
            "category": {"choice": "dispute", "confidence": 0.9}, "response_type": {"choice": "phone"},
            "needs_review": {"noul": 0.2},
        }})

    monkeypatch.setattr(provider, "_post_json", fake_post)
    stub_foundry(monkeypatch, AssertionError("an OpenRouter call went to Foundry"))
    response = ask(client, "ar_classify", {**EMAIL, "categories": CATEGORIES, "methods": METHODS})
    assert response.status_code == 200
    assert calls == [provider.OPENROUTER_DECISIONS_URL]


# --- failures and the trace -----------------------------------------------------------------------------------------


def test_a_refusal_is_a_provider_rejection_naming_the_category(monkeypatch, caplog):
    stub_foundry(monkeypatch, message("", stop_reason="refusal", stop_details={"type": "refusal", "category": "general_harms"}))
    trace = {}
    with pytest.raises(provider.ProviderError) as caught:
        provider.complete_json(registry.get("ar_prose"), "p", correlation_id=CORRELATION, data=EMAIL, provider="foundry", trace=trace)
    assert caught.value.code == "provider_rejected" and "general_harms" in caught.value.message
    assert trace["refusal_category"] == "general_harms" and trace["error"] == "provider_rejected"
    assert "provider=foundry" in caplog.text


def test_a_cut_off_answer_is_a_bad_model_answer(monkeypatch):
    stub_foundry(monkeypatch, message('{"customer_name": "Ac', stop_reason="max_tokens"))
    with pytest.raises(provider.ProviderError) as caught:
        provider.complete_json(registry.get("ar_prose"), "p", correlation_id=CORRELATION, data=EMAIL, provider="foundry")
    assert caught.value.code == "bad_model_answer"


def test_the_trace_carries_the_model_usage_and_latency(monkeypatch):
    stub_foundry(monkeypatch, message({"customer_name": "Acme", "ai_summary": "Paid.", "proposed_response": "Thanks.\nRegards,"}))
    trace = {}
    provider.complete_json(registry.get("ar_prose"), "p", correlation_id=CORRELATION, data=EMAIL, provider="foundry", trace=trace)
    assert trace["provider"] == "foundry" and trace["model"] == "claude-haiku-5-5" and trace["stop_reason"] == "end_turn"
    assert trace["usage"] == {"input_tokens": 1200, "output_tokens": 80}
    assert isinstance(trace["latency_ms"], int)


def test_the_openrouter_trace_carries_its_usage(monkeypatch):
    monkeypatch.setattr(provider, "_post_json", lambda *a: (200, json.dumps({
        "choices": [{"message": {"content": json.dumps({"customer_name": "A", "ai_summary": "S", "proposed_response": "R"})}}],
        "usage": {"prompt_tokens": 900, "completion_tokens": 60, "cost": 0.0004},
    })))
    trace = {}
    provider.complete_json(registry.get("ar_prose"), "p", correlation_id=CORRELATION, data=EMAIL, trace=trace)
    assert trace["provider"] == "openrouter" and trace["model"] == "google/gemini-2.5-flash"
    assert trace["usage"]["cost"] == 0.0004


# --- configuration --------------------------------------------------------------------------------------------------


@pytest.fixture()
def fresh_clients(monkeypatch):
    monkeypatch.setattr(provider, "_clients", {})


def test_no_endpoint_is_unavailable(monkeypatch, fresh_clients):
    monkeypatch.delenv("FOUNDRY_ENDPOINT", raising=False)
    with pytest.raises(provider.ProviderError) as caught:
        provider._foundry_client()
    assert caught.value.code == "unavailable" and "FOUNDRY_ENDPOINT" in caught.value.message


def test_an_api_key_is_refused_in_production(monkeypatch, fresh_clients):
    monkeypatch.setenv("FOUNDRY_ENDPOINT", "https://tricab-ai2.services.ai.azure.com")
    monkeypatch.setenv("FOUNDRY_AUTH_MODE", "api-key")
    monkeypatch.setenv("FOUNDRY_API_KEY", "dummy-foundry-key")
    monkeypatch.setenv("APP_ENV", "production")
    with pytest.raises(provider.ProviderError) as caught:
        provider._foundry_client()
    assert caught.value.code == "unavailable" and "production" in caught.value.message


def test_the_managed_identity_token_is_fetched_from_app_service_and_cached(monkeypatch):
    seen = []

    class FakeResponse:
        def __enter__(self):
            return self

        def __exit__(self, *exc):
            return False

        def read(self):
            return json.dumps({"access_token": "token-1", "expires_on": "9999999999"}).encode()

    def fake_urlopen(request, timeout):
        seen.append((request.full_url, request.get_header("X-identity-header")))
        return FakeResponse()

    monkeypatch.setattr("urllib.request.urlopen", fake_urlopen)
    monkeypatch.setattr(provider, "_token", {"value": None, "expires_at": 0.0})
    monkeypatch.setenv("IDENTITY_ENDPOINT", "http://localhost:8081/msi/token")
    monkeypatch.setenv("IDENTITY_HEADER", "header-secret")

    assert provider._managed_identity_token() == "token-1"
    assert provider._managed_identity_token() == "token-1"
    assert len(seen) == 1
    url, header = seen[0]
    assert "api-version=2019-08-01" in url and "resource=https%3A%2F%2Fai.azure.com" in url and header == "header-secret"


def test_a_remote_identity_endpoint_is_refused(monkeypatch):
    monkeypatch.setattr(provider, "_token", {"value": None, "expires_at": 0.0})
    monkeypatch.setenv("IDENTITY_ENDPOINT", "https://attacker.example.test/token")
    monkeypatch.setenv("IDENTITY_HEADER", "header-secret")
    with pytest.raises(provider._TokenError):
        provider._managed_identity_token()


def test_sdk_errors_map_to_the_contracts_codes(monkeypatch):
    anthropic = pytest.importorskip("anthropic")
    import httpx2 as httpx  # the transport anthropic 1.x uses

    request = httpx.Request("POST", "https://tricab-ai2.services.ai.azure.com/anthropic/v1/messages")

    def status(code):
        return httpx.Response(code, request=request, json={"type": "error", "error": {"type": "x", "message": "m"}})

    cases = [
        (anthropic.APITimeoutError(request=request), "timeout"),
        (anthropic.APIConnectionError(request=request), "provider_unavailable"),
        (anthropic.RateLimitError("m", response=status(429), body=None), "provider_unavailable"),
        (anthropic.InternalServerError("m", response=status(503), body=None), "provider_unavailable"),
        (anthropic.BadRequestError("m", response=status(400), body=None), "provider_rejected"),
        (anthropic.NotFoundError("m", response=status(404), body=None), "provider_rejected"),
    ]
    for error, code in cases:
        class FakeMessages:
            def create(self, **payload):
                raise error

        class FakeClient:
            messages = FakeMessages()

            def with_options(self, **options):
                return self

        monkeypatch.setattr(provider, "_foundry_client", lambda: FakeClient())
        with pytest.raises(provider.ProviderError) as caught:
            provider._foundry_messages_create({}, 5)
        assert caught.value.code == code, type(error).__name__
