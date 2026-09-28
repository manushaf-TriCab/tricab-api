"""The parser service: same answers as the source's two actions, and nothing else.

Platform-owned (not generated). The cost API is offline here (see conftest), so
rows come back unpriced; what is under test is the HTTP surface, not pricing.
"""

from __future__ import annotations

import io
import json

import pytest

from rfq_parser import app as service
from rfq_parser import corrections, pipeline
from tricab_cable_rules.extractor import pair_circuit_label_lines


@pytest.fixture()
def client():
    service.app.config["TESTING"] = True
    return service.app.test_client()


def _data(response):
    body = response.get_json()
    assert body["success"] is True, body
    return body["data"]


def _error(response):
    body = response.get_json()
    assert body["success"] is False, body
    return body["error"]["code"]


PASTE = "Cable schedule\r\n\r\n  4C 16mm2 XLPE/PVC 120m  \n2x 1C 240mm AL flex 80m\n\n"


def test_health_reports_flags_and_limits_but_no_setting_values(client, monkeypatch):
    monkeypatch.setenv("OPENROUTER_API_KEY", "sk-secret-value")
    data = _data(client.get("/api/health"))
    assert data["status"] == "ok"
    assert set(data["ai"]) == {"line_fallback", "normalizer", "table_shape"}
    assert "sk-secret-value" not in json.dumps(data)


def test_every_answer_names_what_produced_it(client, monkeypatch):
    from rfq_parser._source import SOURCE_COMMIT

    data = _data(client.post("/api/parse/text", json={"text": "4C 16mm2 XLPE/PVC 120m"}))
    versions = data["versions"]
    assert versions["parser_source"] == SOURCE_COMMIT and len(SOURCE_COMMIT) == 40
    assert versions["engine"].startswith("cable_code-")
    assert len(versions["catalogue"]) == 16
    assert versions["models"] == {}  # every AI path is off under test

    monkeypatch.setenv("OPENROUTER_API_KEY", "k")
    monkeypatch.setenv("OPENROUTER_MODEL", "some/model")
    assert service.versions()["models"] == {"line_fallback": "some/model"}


def test_text_is_split_as_the_source_browser_splits_it(client):
    data = _data(client.post("/api/parse/text", json={"text": PASTE}))
    assert data["line_count"] == 3
    assert [r["source"]["line_number"] for r in data["results"]][:1] == [1]


def test_text_answers_exactly_what_the_source_match_lines_action_answers(client):
    """rfq_cli.action_match_lines, reproduced: split, pair labels, match_items."""
    lines = [line.strip() for line in PASTE.splitlines() if line.strip()]
    items = [
        {"text": t, "source": {"line_number": i + 1}, "context": [], "metadata": {"reference": r} if r else {}}
        for i, (t, r) in enumerate(pair_circuit_label_lines(lines))
    ]
    expected = pipeline.match_items(items, budget=pipeline.new_llm_budget(line_count=len(items)))
    data = _data(client.post("/api/parse/text", json={"text": PASTE}))
    assert json.loads(json.dumps(data["results"])) == json.loads(json.dumps(expected))


def test_a_learned_correction_applies_and_does_not_outlive_the_request(client):
    line = "4C 16mm2 XLPE/PVC 120m"
    plain = _data(client.post("/api/parse/text", json={"text": line}))["results"][0]
    target = next(
        code for code in pipeline.catalog.products_by_code if code != plain["selected_product_code"] and "/4C16" in code
    )
    learned = {corrections.correction_key(line): {"product_code": target, "confirmations": 3}}

    corrected = _data(client.post("/api/parse/text", json={"text": line, "corrections": learned}))["results"][0]
    assert corrected["selected_product_code"] == target
    assert corrected["parse_tier"] == "learned"
    assert corrected["learned_correction"] == {"confirmations": 3, "product_code": target}

    after = _data(client.post("/api/parse/text", json={"text": line}))["results"][0]
    assert after["selected_product_code"] == plain["selected_product_code"]


def test_a_correction_to_an_unknown_code_is_ignored_as_in_the_source(client):
    line = "4C 16mm2 XLPE/PVC 120m"
    plain = _data(client.post("/api/parse/text", json={"text": line}))["results"][0]
    learned = {corrections.correction_key(line): {"product_code": "NOT-A-CODE", "confirmations": 9}}
    result = _data(client.post("/api/parse/text", json={"text": line, "corrections": learned}))["results"][0]
    assert result["selected_product_code"] == plain["selected_product_code"]


@pytest.mark.parametrize(
    "body",
    [
        {},
        {"text": 12},
        {"text": "   \n  "},
        {"text": "x", "corrections": {"key": {"confirmations": 1}}},
        {"text": "x", "corrections": ["not", "a", "map"]},
    ],
)
def test_bad_text_requests_are_refused(client, body):
    response = client.post("/api/parse/text", json=body)
    assert response.status_code == 422
    assert _error(response) == "VALIDATION_FAILED"


def test_line_and_size_limits_hold(client, monkeypatch):
    monkeypatch.setattr(service, "MAX_LINES", 2)
    response = client.post("/api/parse/text", json={"text": "a\nb\nc"})
    assert response.status_code == 413 and _error(response) == "TOO_MANY_LINES"

    monkeypatch.setattr(service, "MAX_TEXT_CHARS", 5)
    response = client.post("/api/parse/text", json={"text": "123456"})
    assert response.status_code == 413 and _error(response) == "TEXT_TOO_LONG"


CSV = b"Description,Qty\n4C 16mm2 XLPE/PVC,120\n1C 240mm AL flex,80\n"


def _upload(client, name, content, **form):
    return client.post(
        "/api/parse/file",
        data={"file": (io.BytesIO(content), name), **form},
        content_type="multipart/form-data",
    )


def test_a_file_answers_what_the_source_parse_file_action_answers(client, tmp_path):
    from rfq_parser.file_parser import parse_file

    path = tmp_path / "schedule.csv"
    path.write_bytes(CSV)
    items = [
        {
            "text": item["text"],
            "source": {"file_name": "schedule.csv", "sheet": item.get("sheet"), "row_number": item.get("row_number") or i + 1},
            "context": item.get("context") or [],
            "metadata": item.get("metadata") or {},
        }
        for i, item in enumerate(parse_file(path))
    ]
    expected = pipeline.match_items(items, budget=pipeline.new_llm_budget(line_count=len(items)))

    data = _data(_upload(client, "schedule.csv", CSV))
    assert data["ok"] is True and data["errors"] == []
    assert data["item_count"] == len(items)
    assert json.loads(json.dumps(data["results"])) == json.loads(json.dumps(expected))


def test_the_uploaded_filename_is_display_metadata_never_a_path(client):
    data = _data(_upload(client, "../../../etc/passwd.csv", CSV))
    assert {r["source"]["file_name"] for r in data["results"]} == {"passwd.csv"}


def test_unsupported_empty_and_oversized_files_are_refused(client, monkeypatch):
    response = _upload(client, "quote.exe", b"MZ")
    assert response.status_code == 415 and _error(response) == "UNSUPPORTED_FILE"

    response = _upload(client, "empty.csv", b"")
    assert response.status_code == 422 and _error(response) == "VALIDATION_FAILED"

    monkeypatch.setitem(service.app.config, "MAX_CONTENT_LENGTH", 100)
    response = _upload(client, "big.csv", b"x" * 1000)
    assert response.status_code == 413 and _error(response) == "FILE_TOO_LARGE"


def test_a_file_the_parser_cannot_read_is_reported_not_raised(client):
    data = _data(_upload(client, "broken.xlsx", b"this is not a workbook"))
    assert data["ok"] is False
    assert data["errors"] and data["errors"][0]["file_name"] == "broken.xlsx"
