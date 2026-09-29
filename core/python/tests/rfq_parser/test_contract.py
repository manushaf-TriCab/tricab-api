"""`rfq-parser.v1`, the surface the PHP API calls (`rfq_parser.contract`).

Platform-owned. The shapes asserted here are the ones `ParseResponse::fromPayload`
reads strictly on the PHP side (`core/tests/Rfq/Parser/ParserContractTest.php`);
a field renamed here fails there.
"""

from __future__ import annotations

import json

import pytest

from rfq_parser import app as service
from rfq_parser import contract

TOKEN = "test-internal-token-0123456789"
CID = "corr-0123456789abcdef"


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    service.app.config["TESTING"] = True
    return service.app.test_client()


def _auth(token: str = TOKEN) -> dict[str, str]:
    return {"Authorization": f"Bearer {token}"}


def _parse_body(text: str, **extra) -> dict:
    return {"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000, "text": text,
            "documents": [], "options": {"allowModel": False, "maxModelCalls": 50}, **extra}


def _counts(coverage: dict) -> dict:
    return {k: coverage[k] for k in ("inputRows", "keptRows", "excludedRows", "skippedRows")}


def _parse(client, text: str, **extra):
    return client.post("/v1/parse", json=_parse_body(text, **extra), headers=_auth())


# ------------------------------------------------------------------ authentication


@pytest.mark.parametrize("path,method", [("/v1/parse", "post"), ("/v1/readiness", "post"), ("/v1/products/search", "post"), ("/v1/health", "get"), ("/v1/ready", "get")])
def test_every_route_refuses_a_caller_without_the_token(client, path, method):
    for headers in ({}, _auth("wrong"), {"Authorization": TOKEN}):
        response = getattr(client, method)(path, json={}, headers=headers)
        assert response.status_code == 401, (path, headers)
        assert response.get_json()["error"]["code"] == "invalid_request"


def test_no_configured_token_refuses_everyone(client, monkeypatch):
    monkeypatch.delenv(contract.TOKEN_SETTING)
    response = client.get("/v1/health", headers=_auth(""))
    assert response.status_code == 503
    assert response.get_json()["error"]["code"] == "unavailable"


def test_the_parity_routes_are_unchanged(client):
    """The source-shaped routes keep their own envelope; the compare tool depends on it."""
    body = client.post("/api/parse/text", json={"text": "4C 16mm2 XLPE/PVC 120m"}).get_json()
    assert body["success"] is True and "results" in body["data"]


# ------------------------------------------------------------------------ parse


def test_parse_answers_in_the_contract_shape(client):
    response = _parse(client, "4C 16mm2 XLPE/PVC 120m\n3C 2.5mm TPS 4 x 55m per cut")
    body = response.get_json()

    assert response.status_code == 200, body
    assert body["contract"] == "rfq-parser.v1"
    assert body["correlationId"] == CID
    assert len(body["lines"]) == 2
    assert body["lines"][0]["raw_text"] == "4C 16mm2 XLPE/PVC 120m"
    assert _counts(body["coverage"]) == {"inputRows": 2, "keptRows": 2, "excludedRows": 0, "skippedRows": 0}
    assert body["coverage"]["measured"] is True
    assert set(body["engine"]) == {"version", "rulesVersion", "catalogueVersion"}
    assert body["engine"]["rulesVersion"].startswith("cable_code-")
    assert body["provider"] == {"mode": "deterministic", "modelCalls": 0, "model": None}
    assert isinstance(body["degraded"], bool) and isinstance(body["notes"], list)


def test_the_lines_are_the_paste_routes_rows_exactly(client):
    """A draft created through v1 holds what the source's paste would have matched."""
    text = "DB-1\n3C 2.5mm TPS 4 x 55m per cut\nsupply and install lighting"
    v1_lines = _parse(client, text).get_json()["lines"]
    parity = client.post("/api/parse/text", json={"text": text}).get_json()["data"]["results"]
    assert v1_lines == parity


def test_a_folded_circuit_label_is_kept_with_its_line(client):
    body = _parse(client, "DB-1\n3C 2.5mm TPS 4 x 55m per cut").get_json()
    assert len(body["lines"]) == 1
    assert _counts(body["coverage"]) == {"inputRows": 2, "keptRows": 2, "excludedRows": 0, "skippedRows": 0}


def test_coverage_does_not_balance_when_an_item_produced_nothing():
    items = [{"text": "4C 16mm2 120m", "source": {"line_number": 1}}, {"text": "3C 2.5mm 50m", "source": {"line_number": 2}}]
    results = [{"source": {"line_number": 1}, "status": "matched", "selected_product_code": "X"}]
    cov = contract.text_coverage("4C 16mm2 120m\n3C 2.5mm 50m", items, results)
    assert cov.kept + cov.excluded + cov.skipped < cov.input_rows
    assert cov.unaccounted_rows == [{"fileName": None, "sheet": None, "row": 2, "reason": "no quote line came from this row"}]


def test_coverage_counts_an_item_whose_every_result_is_excluded():
    items = [{"text": "Section heading", "source": {"line_number": 1}}]
    results = [{"source": {"line_number": 1}, "status": "admin", "selected_product_code": ""}]
    cov = contract.text_coverage("Section heading", items, results)
    assert (cov.input_rows, cov.kept, cov.excluded, cov.skipped) == (1, 0, 1, 0)


def test_a_failed_price_lookup_marks_the_parse_degraded(client):
    """Under test the cost API is offline, so a matched line's lookup fails."""
    body = _parse(client, "4C 16mm2 XLPE/PVC 120m").get_json()
    assert body["lines"][0]["pricing"]["status"] == "error"
    assert body["degraded"] is True
    assert "Pricing was unavailable for 1 line." in body["notes"][0]


def test_the_model_stays_off_unless_php_allows_it(client, monkeypatch):
    seen = {}
    real = service.pipeline.match_items

    def spy(items, budget=None):
        seen["limit"] = budget["limit"]
        return real(items, budget=budget)

    monkeypatch.setattr(service.pipeline, "match_items", spy)
    _parse(client, "4C 16mm2 XLPE/PVC 120m")
    assert seen["limit"] == 0
    _parse(client, "4C 16mm2 XLPE/PVC 120m", options={"allowModel": True, "maxModelCalls": 7})
    assert seen["limit"] == 7


@pytest.mark.parametrize(
    "change,code,status",
    [
        ({"contract": "rfq-parser.v2"}, "contract_mismatch", 400),
        ({"correlationId": "bad id\nwith newline"}, "invalid_request", 400),
        ({"deadlineMs": 5}, "invalid_request", 400),
        ({"text": "   "}, "invalid_request", 400),
        ({"text": None}, "invalid_request", 400),
        ({"text": None, "documents": [{"fileName": "a.xlsx"}]}, "invalid_request", 400),
        ({"text": None, "documents": [{"documentPublicId": "d", "fileName": "a.exe", "contentType": "", "contentBase64": "TVo="}]}, "unsupported_format", 415),
        ({"documents": [{"documentPublicId": "d", "fileName": "a.txt", "contentType": "", "contentBase64": "eA=="}]}, "invalid_request", 400),
    ],
)
def test_parse_refuses_what_the_contract_does_not_allow(client, change, code, status):
    body = {**_parse_body("4C 16mm2 120m"), **change}
    response = client.post("/v1/parse", json=body, headers=_auth())
    answer = response.get_json()
    assert response.status_code == status
    assert answer["contract"] == "rfq-parser.v1"
    assert answer["error"]["code"] == code


def test_a_malformed_correlation_id_is_never_echoed(client):
    body = client.post("/v1/parse", json=_parse_body("x", correlationId="evil\r\ninjected"), headers=_auth()).get_json()
    assert body["correlationId"] is None


def test_input_limits_are_refused_as_too_large(client, monkeypatch):
    monkeypatch.setattr(service, "MAX_LINES", 2)
    response = _parse(client, "a\nb\nc")
    assert response.status_code == 413
    assert response.get_json()["error"]["code"] == "input_too_large"


def test_a_parser_failure_says_nothing_about_the_text(client, monkeypatch):
    def boom(*_a, **_k):
        raise RuntimeError("customer secret text 4C 16mm2")

    monkeypatch.setattr(service.pipeline, "match_items", boom)
    response = _parse(client, "4C 16mm2 XLPE/PVC 120m")
    error = response.get_json()["error"]
    assert response.status_code == 500
    assert error["code"] == "internal_error"
    assert "secret" not in error["message"] and "16mm2" not in error["message"]


# -------------------------------------------------------------------- readiness


def test_readiness_is_the_sources_rule(client):
    rows = _parse(client, "4C 16mm2 XLPE/PVC 120m").get_json()["lines"]
    body = client.post(
        "/v1/readiness",
        json={"contract": contract.CONTRACT, "correlationId": CID, "draftJson": json.dumps({"rows": rows, "quote_details": {}})},
        headers=_auth(),
    ).get_json()
    readiness = body["readiness"]
    assert readiness["ready"] is False
    missing = {i["field"] for i in readiness["blocking_issues"] if i.get("issue") == "missing_header"}
    assert missing == {"customer_id", "contact", "author", "salesoffice", "payterms", "region"}
    assert len(readiness["row_states"]) == 1


def test_product_search_is_the_sources_autocomplete(client):
    from rfq_parser.product_search import search_products
    from rfq_parser.pipeline import catalog

    body = client.post(
        "/v1/products/search",
        json={"contract": contract.CONTRACT, "correlationId": CID, "q": "xl-paxn/4c", "limit": 5},
        headers=_auth(),
    ).get_json()
    assert body["correlationId"] == CID
    assert body["items"] == search_products(catalog.products, "xl-paxn/4c", 5)
    assert body["items"] and all(item["code"].startswith("XL-PAXN/4C") for item in body["items"])
    assert set(body["items"][0]) == {"code", "style", "desc2"}


@pytest.mark.parametrize("change", [{"q": 4}, {"q": "x" * 101}, {"limit": "20"}, {"limit": True}])
def test_product_search_refuses_what_the_contract_does_not_allow(client, change):
    response = client.post(
        "/v1/products/search",
        json={"contract": contract.CONTRACT, "correlationId": CID, "q": "XL", "limit": 5, **change},
        headers=_auth(),
    )
    assert response.status_code == 400
    assert response.get_json()["error"]["code"] == "invalid_request"


def test_readiness_reads_nan_as_the_store_holds_it(client):
    """18 live rows carry a NaN score; PHP sends them as Python wrote them."""
    rows = _parse(client, "4C 16mm2 XLPE/PVC 120m").get_json()["lines"]
    rows[0]["confidence"] = float("nan")
    response = client.post(
        "/v1/readiness",
        json={"contract": contract.CONTRACT, "correlationId": CID, "draftJson": json.dumps({"rows": rows, "quote_details": {}})},
        headers=_auth(),
    )
    assert response.status_code == 200


def test_readiness_refuses_rows_that_are_not_objects(client):
    response = client.post(
        "/v1/readiness",
        json={"contract": contract.CONTRACT, "correlationId": CID, "draftJson": json.dumps({"rows": ["x"], "quote_details": {}})},
        headers=_auth(),
    )
    assert response.status_code == 400


# ------------------------------------------------------------------- health/ready


def test_health_and_ready(client):
    assert client.get("/v1/health", headers=_auth()).get_json()["status"] == "ok"
    ready = client.get("/v1/ready", headers=_auth()).get_json()
    assert ready["status"] == "ready" and ready["products"] > 0
