"""Source accounting (plan 3.4): every input row kept, excluded or skipped, with a reason.

Platform-owned. The plan names three cases to test explicitly: comment text that
mentions accessories, section defaults and split rows. Each is here, built as a real
workbook or document in a temporary directory, so no customer file is involved. The
corpus check behind the design (every row of the 120 measured files in `rfq/sample
rfq` balanced, 28 Sep) is in the plan's evidence log, not in this suite.
"""

from __future__ import annotations

import base64

import pytest
from openpyxl import Workbook

from rfq_parser import accounting, pipeline
from rfq_parser import app as service
from rfq_parser import contract
from rfq_parser.file_parser import parse_file

TOKEN = "test-internal-token-0123456789"
CID = "corr-0123456789abcdef"


def _workbook(path, sheets: dict[str, list[list]], hidden: tuple[str, ...] = ()):
    wb = Workbook()
    wb.remove(wb.active)
    for title, rows in sheets.items():
        ws = wb.create_sheet(title)
        for row in rows:
            ws.append(row)
        if title in hidden:
            ws.sheet_state = "hidden"
    wb.save(path)
    return path


def _account(path):
    parsed = parse_file(path)
    items = contract._work_items(parsed, path.name)
    results = pipeline.match_items(items, budget={"used": 0, "limit": 0})
    return accounting.balance(accounting.file_rows(path, path.name, parsed), results, items=items, default_file=path.name), results


def _balanced(cov: accounting.Coverage) -> bool:
    return cov.input_rows == cov.kept + cov.excluded + cov.skipped


HEADER = ["Item", "Description", "Qty (m)"]


def test_comment_text_mentioning_accessories_is_a_note_not_a_line(tmp_path):
    """A note row under the header that names glands and lugs is context, not an
    accessory order: it is skipped with its reason, and no line is quoted from it."""
    path = _workbook(tmp_path / "schedule.xlsx", {"Cables": [
        HEADER,
        [1, "4C 16mm2 Cu XLPE/PVC 0.6/1kV", 120],
        ["Price to include glands, lugs and cleats for all cables"],
        [2, "3C 2.5mm2 XLPE/PVC", 55],
    ]})
    cov, results = _account(path)

    assert _balanced(cov), cov
    assert (cov.input_rows, cov.kept, cov.skipped) == (4, 2, 2)
    reasons = {row["row"]: row["reason"] for row in cov.skipped_rows}
    assert reasons == {1: accounting.SKIP_HEADER, 3: accounting.SKIP_SECTION}
    assert not any("gland" in str(r.get("raw_text", "")).lower() for r in results)


def test_a_section_default_is_skipped_and_carried_to_the_rows_below(tmp_path):
    """"ALUMINIUM" spanning the table above a block is a section default: the banner row
    is accounted as skipped, and every row under it carries it as context. (Turning that
    context into an Al conductor is the AI normaliser's job in the source, not the
    deterministic resolver's; this test is about the row not going missing.)"""
    path = _workbook(tmp_path / "sections.xlsx", {"Schedule": [
        HEADER,
        ["ALUMINIUM", "ALUMINIUM", "ALUMINIUM"],
        [1, "1C 240mm2 XLPE", 300],
        [2, "1C 185mm2 XLPE", 150],
    ]})
    cov, _ = _account(path)

    assert _balanced(cov), cov
    assert (cov.input_rows, cov.kept, cov.skipped) == (4, 2, 2)
    assert [row["reason"] for row in cov.skipped_rows] == [accounting.SKIP_HEADER, accounting.SKIP_SECTION]
    assert all("ALUMINIUM" in item["context"] for item in parse_file(path))


def test_a_split_row_is_one_input_row_kept_once(tmp_path):
    """One row naming a cable and its earth becomes two lines; it is still one input
    row, counted once, and kept."""
    path = _workbook(tmp_path / "split.xlsx", {"Schedule": [
        HEADER,
        [1, "4C 16mm2 Cu XLPE/PVC + 1C 6mm2 earth", 80],
    ]})
    cov, results = _account(path)

    assert len(results) == 2, [r.get("raw_text") for r in results]
    assert {(r["source"]["sheet"], r["source"]["row_number"]) for r in results} == {("Schedule", 2)}
    assert (cov.input_rows, cov.kept, cov.excluded, cov.skipped) == (2, 1, 0, 1)


def test_a_product_code_under_its_cable_counts_with_that_cable(tmp_path):
    path = _workbook(tmp_path / "codes.xlsx", {"Schedule": [
        ["Description", "Product Code", "Qty (m)"],
        ["4C 16mm2 Cu XLPE/PVC", "", 120],
        ["", "XL-PAXN/4C16OR", ""],
    ]})
    cov, _ = _account(path)
    assert _balanced(cov), cov
    assert (cov.input_rows, cov.kept, cov.skipped) == (3, 2, 1)


def test_a_hidden_sheet_is_skipped_as_one_entry_for_all_its_rows(tmp_path):
    path = _workbook(tmp_path / "hidden.xlsx", {
        "Quote": [HEADER, [1, "4C 16mm2 Cu XLPE/PVC", 120]],
        "Old": [HEADER, [1, "2C 1.5mm2", 10], [2, "3C 2.5mm2", 20]],
    }, hidden=("Old",))
    cov, _ = _account(path)
    assert _balanced(cov), cov
    assert cov.skipped == 4 and {"sheet": "Old", "rows": 3, "reason": accounting.SKIP_HIDDEN}.items() <= [r for r in cov.skipped_rows if r["sheet"] == "Old"][0].items()


def test_an_explicit_zero_is_skipped_with_that_reason(tmp_path):
    """`has_explicit_zero_length` drops the line inside the pipeline; the row is still
    accounted for, and says why."""
    path = _workbook(tmp_path / "zero.xlsx", {"Schedule": [
        HEADER,
        [1, "4C 16mm2 Cu XLPE/PVC", 120],
        [2, "240mm2 1C Cu/PVC", 0],
    ]})
    cov, results = _account(path)
    assert len(results) == 1
    assert _balanced(cov), cov
    assert {"row": 3, "reason": accounting.SKIP_ZERO}.items() <= cov.skipped_rows[-1].items()


def test_a_product_tricab_does_not_sell_is_skipped_with_that_reason(tmp_path):
    """NOT_SOLD_PATTERNS lines (flat TPS) are removed from the quote inside the pipeline;
    the row is still accounted for, so the draft is not left `partial`."""
    path = _workbook(tmp_path / "tps.xlsx", {"Schedule": [
        HEADER,
        [1, "4C 16mm2 Cu XLPE/PVC", 120],
        [2, "2C+E 2.5mm2 TPS flat", 150],
    ]})
    cov, results = _account(path)
    assert len(results) == 1
    assert _balanced(cov), cov
    assert {"row": 3, "reason": accounting.SKIP_NOT_SOLD}.items() <= cov.skipped_rows[-1].items()


def test_circular_tps_stays_on_the_quote(tmp_path):
    """Only flat TPS is not sold (source e3d23a7); circular TPS goes through the style
    tree like any other cable and is kept, not skipped."""
    path = _workbook(tmp_path / "circ-tps.xlsx", {"Schedule": [
        HEADER,
        [1, "4C 16mm2 Cu XLPE/PVC", 120],
        [2, "4C+E 2.5mm2 Circ TPS", 150],
    ]})
    cov, results = _account(path)
    assert len(results) == 2
    assert _balanced(cov), cov
    assert not any(row.get("reason") == accounting.SKIP_NOT_SOLD for row in cov.skipped_rows)


def test_a_row_that_vanished_is_unaccounted_and_named():
    rows = [accounting.Row(("f.xlsx", "S", 2), "line"), accounting.Row(("f.xlsx", "S", 3), "line")]
    results = [{"source": {"file_name": "f.xlsx", "sheet": "S", "row_number": 2}, "status": "matched", "selected_product_code": "X"}]
    cov = accounting.balance(rows, results, items=[], default_file="f.xlsx")
    assert not _balanced(cov)
    assert cov.unaccounted_rows == [{"fileName": "f.xlsx", "sheet": "S", "row": 3, "reason": "no quote line came from this row"}]


def test_csv_and_docx_are_measured_and_pdf_is_not(tmp_path):
    csv_path = tmp_path / "lines.csv"
    csv_path.write_text("Description,Qty (m)\n4C 16mm2 Cu XLPE/PVC,120\n", encoding="utf-8")
    cov, _ = _account(csv_path)
    assert cov.measured and _balanced(cov) and cov.input_rows == 2

    from docx import Document

    doc = Document()
    doc.add_paragraph("Cables")
    doc.add_paragraph("4C 16mm2 Cu XLPE/PVC 120m")
    doc_path = tmp_path / "rfq.docx"
    doc.save(doc_path)
    cov, _ = _account(doc_path)
    assert cov.measured and _balanced(cov) and (cov.input_rows, cov.kept, cov.skipped) == (2, 1, 1)

    assert accounting.file_rows(tmp_path / "x.pdf", "x.pdf", []) is None
    assert accounting.balance(None, []).to_contract()["measured"] is False


# ---------------------------------------------------------------- over the contract


@pytest.fixture()
def client(monkeypatch):
    monkeypatch.setenv(contract.TOKEN_SETTING, TOKEN)
    service.app.config["TESTING"] = True
    return service.app.test_client()


def _document(name: str, data: bytes, public_id: str = "doc-1") -> dict:
    return {"documentPublicId": public_id, "fileName": name, "contentType": "", "contentBase64": base64.b64encode(data).decode()}


def _post_documents(client, *documents):
    return client.post(
        "/v1/parse",
        json={"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000, "text": None,
              "documents": list(documents), "options": {"allowModel": False, "maxModelCalls": 50}},
        headers={"Authorization": f"Bearer {TOKEN}"},
    )


def test_a_workbook_parses_over_the_contract_with_its_accounting(client, tmp_path):
    path = _workbook(tmp_path / "s.xlsx", {"Cables": [
        HEADER,
        [1, "4C 16mm2 Cu XLPE/PVC 0.6/1kV", 120],
        ["Price to include glands, lugs and cleats for all cables"],
    ]})
    response = _post_documents(client, _document("Customer schedule.xlsx", path.read_bytes()))
    body = response.get_json()

    assert response.status_code == 200, body
    assert len(body["lines"]) == 1
    assert body["lines"][0]["source"] == {**body["lines"][0]["source"], "file_name": "Customer schedule.xlsx", "sheet": "Cables", "row_number": 2}
    coverage = body["coverage"]
    assert (coverage["inputRows"], coverage["keptRows"], coverage["skippedRows"], coverage["measured"]) == (3, 1, 2, True)
    assert body["documents"] == [{"documentPublicId": "doc-1", "fileName": "Customer schedule.xlsx", "readerRoute": False,
                                  "lineCount": 1, "coverage": coverage}]


def test_an_image_is_not_read_deterministically_and_says_so(client):
    png = b"\x89PNG\r\n\x1a\n" + b"\x00" * 64
    body = _post_documents(client, _document("scan.png", png)).get_json()
    assert body["lines"] == []
    assert body["coverage"]["measured"] is False
    assert body["documents"][0]["readerRoute"] is False
    assert "no image reader is switched on" in body["notes"][0]


def test_several_documents_add_up(client, tmp_path):
    a = _workbook(tmp_path / "a.xlsx", {"S": [HEADER, [1, "4C 16mm2 Cu XLPE/PVC", 120]]})
    b = tmp_path / "b.csv"
    b.write_text("Description,Qty (m)\n3C 2.5mm2 XLPE/PVC,55\n", encoding="utf-8")
    body = _post_documents(client, _document("a.xlsx", a.read_bytes(), "d1"), _document("b.csv", b.read_bytes(), "d2")).get_json()
    assert len(body["lines"]) == 2
    assert body["coverage"]["inputRows"] == 4 and body["coverage"]["keptRows"] == 2
    assert [d["documentPublicId"] for d in body["documents"]] == ["d1", "d2"]


def test_text_and_documents_together_are_refused(client, tmp_path):
    a = _workbook(tmp_path / "a.xlsx", {"S": [HEADER]})
    response = client.post(
        "/v1/parse",
        json={"contract": contract.CONTRACT, "correlationId": CID, "deadlineMs": 60_000, "text": "4C 16mm2 120m",
              "documents": [_document("a.xlsx", a.read_bytes())], "options": {}},
        headers={"Authorization": f"Bearer {TOKEN}"},
    )
    assert response.status_code == 400 and response.get_json()["error"]["code"] == "invalid_request"


def test_contract_json_never_carries_a_python_only_value():
    """The coverage block is plain JSON that `ParseResponse` reads strictly."""
    import json

    cov = accounting.Coverage(input_rows=1, kept=1)
    assert json.loads(json.dumps(cov.to_contract())) == cov.to_contract()
