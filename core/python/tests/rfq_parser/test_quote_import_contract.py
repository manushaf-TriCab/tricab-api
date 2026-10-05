"""TriCab quotations uploaded through `rfq-parser.v1` (`contract._parse_documents`).

Platform-owned. The source's upload reads a recognised 4js/Dynamics quote PDF with
`quote_import` and never with the RFQ parser; `/v1/parse` must do the same, and must
not queue a model re-read of it. The real quotes are customer documents and are not
committed: the acceptance cases run when `QUOTE_TEMPLATE_DIR` (or ~/Desktop/quote-templates)
holds the source's three samples, and `RFQ_QUOTE_PDF` names any further quote to check.
"""

from __future__ import annotations

import base64
import os
from pathlib import Path

import pytest

from rfq_parser import app as service
from rfq_parser import contract, quote_import

TOKEN = "test-internal-token-0123456789"
CID = "corr-0123456789abcdef"

# The source's reviewed samples (tests/test_quote_import.py): file -> printed line count.
SAMPLES = {"4js-quote.pdf": 13, "4js-quote-with-comments.pdf": 29, "dynamics-quote.pdf": 28}


def _sample_dir() -> Path | None:
    for candidate in (os.environ.get("QUOTE_TEMPLATE_DIR", ""), str(Path.home() / "Desktop" / "quote-templates")):
        if candidate and all((Path(candidate) / name).exists() for name in SAMPLES):
            return Path(candidate)
    return None


SAMPLE_DIR = _sample_dir()


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    service.app.config["TESTING"] = True
    return service.app.test_client()


def _upload(client, name: str, data: bytes):
    document = {"documentPublicId": "doc-1", "fileName": name, "contentType": "application/pdf",
                "contentBase64": base64.b64encode(data).decode()}
    body = {"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000, "text": None,
            "documents": [document], "options": {"allowModel": False, "maxModelCalls": 0}}
    return client.post("/v1/parse", json=body, headers={"Authorization": f"Bearer {TOKEN}"})


def _blank_pdf() -> bytes:
    import io

    import pypdfium2

    pdf = pypdfium2.PdfDocument.new()
    pdf.new_page(200, 200)
    buffer = io.BytesIO()
    pdf.save(buffer)
    pdf.close()
    return buffer.getvalue()


def _quote_row(item: int, code: str) -> dict:
    return {"raw_text": code, "selected_product_code": code, "quantity": 100, "status": "matched", "row_number": item}


def test_a_recognised_quote_is_read_by_the_quote_reader_only(client, monkeypatch):
    imported = {"file_name": "q.pdf", "template": "4js", "status": "ready", "header": {"quote_number": "740388"}}
    rows = [_quote_row(1, "KL-PAXA/1C120BK"), _quote_row(2, "KL-PAXA/1C35BK")]
    monkeypatch.setattr(quote_import, "read_upload_quote", lambda data, name="": imported)
    monkeypatch.setattr(quote_import, "quote_upload_result", lambda q: {
        "results": rows, "errors": [{"file_name": "q.pdf", "error": "The printed total does not reconcile."}],
        "quote_import": {"template": "4js", "quote_number": "740388", "status": "ready", "line_count": 2}})
    monkeypatch.setattr(contract.intake, "parse_bounded", lambda *a, **k: pytest.fail("a quote reached the RFQ parser"))

    body = _upload(client, "q.pdf", _blank_pdf()).get_json()

    assert [line["selected_product_code"] for line in body["lines"]] == ["KL-PAXA/1C120BK", "KL-PAXA/1C35BK"]
    (summary,) = body["documents"]
    assert summary["readerRoute"] is False and summary["lineCount"] == 2
    assert summary["quoteImport"]["quote_number"] == "740388"
    assert {k: body["coverage"][k] for k in ("inputRows", "keptRows", "excludedRows", "skippedRows")} == {
        "inputRows": 2, "keptRows": 2, "excludedRows": 0, "skippedRows": 0}
    assert "q.pdf was read as TriCab quote 740388: 2 lines." in body["notes"]
    assert "The printed total does not reconcile." in body["notes"]


def test_a_pdf_that_is_not_a_quote_goes_to_the_rfq_parser(client, monkeypatch):
    monkeypatch.setattr(quote_import, "read_upload_quote", lambda data, name="": None)
    called = []

    def parse_bounded(document, seconds):
        called.append(document.file_name)
        return [], []

    monkeypatch.setattr(contract.intake, "parse_bounded", parse_bounded)
    body = _upload(client, "schedule.pdf", _blank_pdf()).get_json()

    assert called == ["schedule.pdf"]
    assert "quoteImport" not in body["documents"][0]


@pytest.mark.skipif(SAMPLE_DIR is None, reason="sample quotation PDFs are not available")
@pytest.mark.parametrize("name,count", sorted(SAMPLES.items()))
def test_the_source_samples_read_one_line_per_printed_item(client, name, count):
    body = _upload(client, name, (SAMPLE_DIR / name).read_bytes()).get_json()

    assert body["documents"][0]["readerRoute"] is False
    assert len(body["lines"]) == count
    assert not any("Page " in str(line.get("raw_text") or "") for line in body["lines"])


@pytest.mark.skipif(not os.environ.get("RFQ_QUOTE_PDF"), reason="RFQ_QUOTE_PDF is not set")
def test_a_named_quote_matches_the_quote_reader(client):
    path = Path(os.environ["RFQ_QUOTE_PDF"])
    data = path.read_bytes()
    expected = quote_import.read_upload_quote(data, path.name)
    assert expected is not None, f"{path.name} is not recognised as a TriCab quote"

    body = _upload(client, path.name, data).get_json()

    assert body["documents"][0]["readerRoute"] is False
    assert [line.get("selected_product_code") or line.get("product_code") for line in body["lines"]] == [
        line["product_code"] for line in expected["lines"]
    ]
