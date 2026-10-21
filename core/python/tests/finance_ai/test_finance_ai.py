"""`finance-ai.v1`: the auth, the purpose registry, the provider call and its failures.

No test reaches the network: `urllib.request.urlopen` fails any test that gets that far, and
the provider's `_post_json` is stubbed where a model answer is needed. The key below is a dummy.
"""

from __future__ import annotations

import json
import logging
import socket
import urllib.error

import pytest

from finance_ai import contract, provider, registry
from finance_ai.app import app

TOKEN = "internal-token-for-tests"
KEY = "sk-dummy-openrouter-key"
CORRELATION = "corr-0123456789abcdef"
SECRET_TEXT = "INVOICE 4471 FOR ACME PTY LTD"

SCHEMA = {
    "type": "object",
    "required": ["vendor", "total"],
    "properties": {"vendor": {"type": "string"}, "total": {"type": "number"}},
}


def build_prompt(data):
    if "text" not in data:
        raise registry.InvalidData("text is required.")
    return f"Extract the vendor and total. <data>{data['text']}</data>"


@pytest.fixture(autouse=True)
def no_network(monkeypatch):
    def refuse(*args, **kwargs):  # pragma: no cover - only reached by a bug
        raise AssertionError("a finance_ai test reached the network")

    monkeypatch.setattr("urllib.request.urlopen", refuse)


@pytest.fixture(autouse=True)
def environment(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    monkeypatch.setenv(provider.KEY_SETTING, KEY)
    monkeypatch.delenv("OPENROUTER_API_KEY", raising=False)


@pytest.fixture()
def fake_purpose():
    purpose = registry.register(
        registry.Purpose("fake-extract", build_prompt, SCHEMA, model="test/model", temperature=0.1, timeout_seconds=7)
    )
    yield purpose
    registry.unregister("fake-extract")


@pytest.fixture()
def client():
    app.config["TESTING"] = True
    return app.test_client()


def headers(token=TOKEN):
    return {"Authorization": f"Bearer {token}", "X-Correlation-Id": CORRELATION}


def body(**over):
    payload = {"contract": contract.CONTRACT, "correlationId": CORRELATION, "data": {"text": SECRET_TEXT}}
    payload.update(over)
    return payload


def test_model_override_is_request_local_and_registry_default_survives(client, fake_purpose, monkeypatch):
    observed = []

    def complete(purpose, *_args, **_kwargs):
        observed.append(purpose.model)
        return {"vendor": "Acme", "total": 1}

    monkeypatch.setattr(provider, "complete_json", complete)
    for payload in (body(model="vendor/new"), body()):
        response = client.post("/v1/complete/fake-extract", json=payload, headers=headers())
        assert response.status_code == 200
    assert observed == ["vendor/new", "test/model"]
    assert registry.get("fake-extract").model == "test/model"


def test_bad_model_override_is_refused_before_provider_call(client, fake_purpose, monkeypatch):
    monkeypatch.setattr(provider, "complete_json", lambda *_args, **_kwargs: pytest.fail("provider called"))
    response = client.post("/v1/complete/fake-extract", json=body(model="bad model"), headers=headers())
    assert response.status_code == 400


def answer(content: str, status: int = 200):
    return status, json.dumps({"choices": [{"message": {"content": content}}]})


def stub_provider(monkeypatch, response):
    calls = []

    def fake_post(url, payload, api_key, timeout):
        calls.append({"url": url, "payload": payload, "api_key": api_key, "timeout": timeout})
        if isinstance(response, Exception):
            raise response
        return response

    monkeypatch.setattr(provider, "_post_json", fake_post)
    return calls


# --- authentication ----------------------------------------------------------------------------


def test_every_route_refuses_when_no_token_is_configured(client, monkeypatch):
    monkeypatch.delenv(contract.TOKEN_SETTING)
    for method, path in (("get", "/v1/health"), ("post", "/v1/complete/anything")):
        response = getattr(client, method)(path, headers=headers(""))
        assert response.status_code == 503
        assert response.get_json()["error"]["code"] == "unavailable"


def test_the_wrong_or_missing_token_is_refused(client):
    assert client.get("/v1/health", headers=headers("wrong")).status_code == 401
    assert client.get("/v1/health").status_code == 401
    assert client.post("/v1/complete/x", json=body()).status_code == 401


def test_health_names_the_contract_and_the_registered_purposes(client, fake_purpose):
    response = client.get("/v1/health", headers=headers())
    data = response.get_json()
    assert response.status_code == 200
    assert data["contract"] == "finance-ai.v1"
    assert data["correlationId"] == CORRELATION
    assert data["purposes"] == ["fake-extract"]


def test_phase_one_registers_no_purpose():
    # Each app's phase adds its own; the foundation ships with none.
    assert registry.names() == []


# --- the request -------------------------------------------------------------------------------


def test_an_unknown_purpose_is_a_404(client):
    response = client.post("/v1/complete/does-not-exist", json=body(), headers=headers())
    assert response.status_code == 404
    assert response.get_json()["error"]["code"] == "unknown_purpose"
    assert response.get_json()["correlationId"] == CORRELATION


@pytest.mark.parametrize(
    "payload, code",
    [
        ({"contract": "finance-ai.v0", "correlationId": CORRELATION, "data": {}}, "contract_mismatch"),
        ({"contract": contract.CONTRACT, "data": {"text": "x"}}, "invalid_request"),
        ({"contract": contract.CONTRACT, "correlationId": "short", "data": {"text": "x"}}, "invalid_request"),
        ({"contract": contract.CONTRACT, "correlationId": CORRELATION, "data": "not an object"}, "invalid_request"),
        ({"contract": contract.CONTRACT, "correlationId": CORRELATION, "data": {"text": "x"}, "deadlineMs": 5}, "invalid_request"),
        ({"contract": contract.CONTRACT, "correlationId": CORRELATION, "data": {"text": "x"}, "deadlineMs": True}, "invalid_request"),
        ({"contract": contract.CONTRACT, "correlationId": CORRELATION, "data": {"text": "x"}, "deadlineMs": 200_001}, "invalid_request"),
        ({"contract": contract.CONTRACT, "correlationId": CORRELATION, "data": {}}, "invalid_request"),  # builder refuses
    ],
)
def test_a_malformed_request_is_refused_with_a_coded_error(client, fake_purpose, payload, code):
    response = client.post("/v1/complete/fake-extract", json=payload, headers=headers())
    assert response.status_code == 400
    assert response.get_json()["error"]["code"] == code
    assert response.get_json()["contract"] == "finance-ai.v1"


def test_a_wrong_path_or_method_answers_in_the_contracts_shape(client):
    missing = client.get("/nope", headers=headers())
    assert missing.status_code == 404
    assert missing.get_json()["error"]["code"] == "invalid_request"
    wrong_method = client.get("/v1/complete/x", headers=headers())
    assert wrong_method.status_code == 405
    assert wrong_method.get_json()["contract"] == "finance-ai.v1"


# --- a purpose round-trips, with the provider stubbed ------------------------------------------


def test_a_fake_purpose_round_trips_and_the_purpose_owns_the_prompt_model_and_schema(client, fake_purpose, monkeypatch):
    calls = stub_provider(monkeypatch, answer('{"vendor": "Acme", "total": 1180.5}'))

    response = client.post("/v1/complete/fake-extract", json=body(deadlineMs=3000), headers=headers())

    assert response.status_code == 200
    data = response.get_json()
    assert data["result"] == {"vendor": "Acme", "total": 1180.5}
    assert data["purpose"] == "fake-extract"
    assert data["provider"] == {"name": "openrouter", "model": "test/model"}
    assert data["correlationId"] == CORRELATION
    # The answer does not echo the caller's data back.
    assert SECRET_TEXT not in json.dumps(data)

    (call,) = calls
    assert call["url"] == provider.OPENROUTER_URL
    assert call["api_key"] == KEY
    assert call["timeout"] == 3.0  # the caller's deadline, below the purpose's 7 s
    sent = call["payload"]
    assert sent["model"] == "test/model"
    assert sent["temperature"] == 0.1
    assert sent["response_format"]["type"] == "json_schema"
    assert sent["response_format"]["json_schema"]["schema"] == SCHEMA
    assert SECRET_TEXT in sent["messages"][0]["content"]  # the data reaches the model, wrapped as data


def test_the_purposes_timeout_applies_without_a_deadline_and_a_longer_deadline_cannot_exceed_it(client, fake_purpose, monkeypatch):
    calls = stub_provider(monkeypatch, answer('{"vendor": "A", "total": 1}'))
    client.post("/v1/complete/fake-extract", json=body(), headers=headers())
    client.post("/v1/complete/fake-extract", json=body(deadlineMs=200_000), headers=headers())
    assert [c["timeout"] for c in calls] == [7.0, 7.0]


def test_a_fenced_json_answer_is_read(client, fake_purpose, monkeypatch):
    stub_provider(monkeypatch, answer('```json\n{"vendor": "Acme", "total": 2}\n```'))
    response = client.post("/v1/complete/fake-extract", json=body(), headers=headers())
    assert response.get_json()["result"] == {"vendor": "Acme", "total": 2}


def test_the_finance_key_is_the_only_key_used(client, fake_purpose, monkeypatch):
    # The RFQ parser's key must never be borrowed (plan Q4).
    monkeypatch.delenv(provider.KEY_SETTING)
    monkeypatch.setenv("OPENROUTER_API_KEY", "the-rfq-parsers-key")
    calls = stub_provider(monkeypatch, answer('{"vendor": "A", "total": 1}'))

    response = client.post("/v1/complete/fake-extract", json=body(), headers=headers())

    assert response.status_code == 503
    assert response.get_json()["error"]["code"] == "unavailable"
    assert calls == []


# --- failures: coded, and every one logged -----------------------------------------------------


@pytest.mark.parametrize(
    "response, status, code",
    [
        (answer("", 401), 502, "provider_rejected"),
        (answer("", 402), 502, "provider_rejected"),
        (answer("", 400), 502, "provider_rejected"),
        (answer("", 429), 502, "provider_unavailable"),
        (answer("", 503), 502, "provider_unavailable"),
        (answer("", 504), 504, "timeout"),
        (socket.timeout("timed out"), 504, "timeout"),
        (urllib.error.URLError("refused"), 502, "provider_unavailable"),
        (answer("not json at all"), 502, "bad_model_answer"),
        (answer('{"vendor": "A"}'), 502, "bad_model_answer"),  # total is missing
        (answer('{"vendor": "A", "total": "a lot"}'), 502, "bad_model_answer"),
        (answer('{"vendor": "A", "total": true}'), 502, "bad_model_answer"),  # a boolean is not a number
        ((200, "{}"), 502, "bad_model_answer"),
    ],
)
def test_a_provider_failure_is_coded_and_logged_without_the_text(client, fake_purpose, monkeypatch, caplog, response, status, code):
    stub_provider(monkeypatch, response)

    with caplog.at_level(logging.ERROR, logger="finance-ai"):
        result = client.post("/v1/complete/fake-extract", json=body(), headers=headers())

    assert result.status_code == status
    assert result.get_json()["error"]["code"] == code
    assert result.get_json()["error"]["code"] in contract.CODES

    errors = [r for r in caplog.records if r.levelno == logging.ERROR]
    assert len(errors) == 1, "every failure is logged, once"
    logged = errors[0].getMessage()
    assert "fake-extract" in logged and CORRELATION in logged and code in logged
    # Not the data, not the prompt, not the model's answer, not a credential.
    for forbidden in (SECRET_TEXT, KEY, TOKEN, "Extract the vendor"):
        assert forbidden not in logged
        assert forbidden not in json.dumps(result.get_json())


def test_an_unexpected_exception_is_an_internal_error_with_no_traceback_in_the_answer(client, fake_purpose, monkeypatch, caplog):
    stub_provider(monkeypatch, RuntimeError(f"boom {SECRET_TEXT}"))

    with caplog.at_level(logging.ERROR, logger="finance-ai"):
        result = client.post("/v1/complete/fake-extract", json=body(), headers=headers())

    assert result.status_code == 500
    assert result.get_json()["error"]["code"] == "internal_error"
    assert "boom" not in json.dumps(result.get_json())
    assert any(r.levelno == logging.ERROR for r in caplog.records)


def test_the_token_and_the_key_are_never_logged_on_success(client, fake_purpose, monkeypatch, caplog):
    stub_provider(monkeypatch, answer('{"vendor": "A", "total": 1}'))
    with caplog.at_level(logging.DEBUG):
        client.post("/v1/complete/fake-extract", json=body(), headers=headers())
    text = "\n".join(r.getMessage() for r in caplog.records)
    for forbidden in (TOKEN, KEY, SECRET_TEXT):
        assert forbidden not in text


# --- the registry ------------------------------------------------------------------------------


def test_a_purpose_is_registered_once_and_named_plainly(fake_purpose):
    with pytest.raises(ValueError, match="already registered"):
        registry.register(registry.Purpose("fake-extract", build_prompt, SCHEMA))
    with pytest.raises(ValueError):
        registry.Purpose("Has Spaces", build_prompt, SCHEMA)
    with pytest.raises(ValueError):
        registry.Purpose("ok", build_prompt, SCHEMA, timeout_seconds=0)


def test_every_error_code_is_listed_once():
    assert len(set(contract.CODES)) == len(contract.CODES)
    assert "unknown_purpose" in contract.CODES and "bad_model_answer" in contract.CODES
