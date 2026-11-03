"""Quotegen inspector: pure functions over generated fixtures."""

from __future__ import annotations

import json
import zipfile

import pytest

from quotegen_inspector import eml, image, limits, msg, pdf, xlsx
from quotegen_inspector import docx as docx_mod
from quotegen_inspector.inventory import inventory

from quotegen_fixtures import (build_blank_pdf, build_docx, build_eml, build_html_only_eml, build_pdf,
                               build_xlsx, png_bytes)


def _jsonable(d):
    json.dumps(d)  # raises if not serialisable
    for k in ("truncated", "next_cursor", "unreadable"):
        assert k in d, f"missing {k}"


# ---- inventory ----

def test_inventory_sniffs_content_not_name(tmp_path):
    p = build_xlsx(tmp_path / "looks_like.pdf")
    inv = inventory(str(p))
    assert inv["mediaType"].endswith("spreadsheetml.sheet")
    assert inv["inspectors"] == ["xlsx"] and len(inv["sha256"]) == 64 and inv["size"] > 0


@pytest.mark.parametrize("builder,expected", [(build_eml, "message/rfc822"), (build_pdf, "application/pdf"),
                                              (build_docx, "application/vnd.openxmlformats-officedocument.wordprocessingml.document")])
def test_inventory_types(tmp_path, builder, expected):
    assert inventory(str(builder(tmp_path / "f.bin")))["mediaType"] == expected


def test_inventory_png(tmp_path):
    p = tmp_path / "x"
    p.write_bytes(png_bytes())
    assert inventory(str(p))["mediaType"] == "image/png"


def test_file_size_limit(tmp_path, monkeypatch):
    p = build_pdf(tmp_path / "a.pdf")
    monkeypatch.setattr("quotegen_inspector.inventory.MAX_FILE_BYTES", 10)
    with pytest.raises(limits.LimitError):
        inventory(str(p))


def test_zip_bomb_guard(tmp_path, monkeypatch):
    p = tmp_path / "bomb.xlsx"
    with zipfile.ZipFile(p, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("xl/workbook.xml", "<x/>")
        z.writestr("big.bin", b"\0" * 5_000_000)
    with pytest.raises(limits.LimitError, match="ratio"):
        inventory(str(p))


# ---- eml ----

def test_eml_overview_tree_cid_attachments_nested(tmp_path):
    p = build_eml(tmp_path / "m.eml")
    o = eml.eml_overview(str(p))
    _jsonable(o)
    assert o["headers"]["Subject"] == "RFQ test"
    types = [n["contentType"] for n in o["tree"]]
    assert "multipart/related" in types and "message/rfc822" in types and "image/png" in types
    names = {a["name"] for a in o["attachments"]}
    assert {"spec.pdf", "logo.png"} <= names
    pdf_att = next(a for a in o["attachments"] if a["name"] == "spec.pdf")
    assert pdf_att["size"] == len(b"%PDF-1.4 fake") and len(pdf_att["sha256"]) == 64
    refs = next(iter(o["cidReferences"].values()))
    assert refs == [{"cid": "logo1", "partId": next(a["partId"] for a in o["attachments"] if a["name"] == "logo.png"), "resolved": True}]
    assert any(n["contentType"] == "text/plain" and n["depth"] > 1 for n in o["tree"]), "nested message body is walked"
    assert any(n.get("nestedSubject") == "Inner forwarded" for n in o["tree"])


def test_eml_pagination_never_silent(tmp_path, monkeypatch):
    p = build_eml(tmp_path / "m.eml")
    monkeypatch.setattr("quotegen_inspector.limits.MAX_ITEMS", 3)
    monkeypatch.setattr("quotegen_inspector.eml.page", lambda items, c: limits.page(items, c, 3))
    first = eml.eml_overview(str(p))
    assert first["truncated"] and first["next_cursor"] == "3" and len(first["tree"]) == 3
    second = eml.eml_overview(str(p), first["next_cursor"])
    assert second["tree"][0]["partId"] != first["tree"][0]["partId"]


def test_html_only_email_exposes_table(tmp_path):
    p = build_html_only_eml(tmp_path / "h.eml")
    o = eml.eml_overview(str(p))
    assert not o["hasTextPlain"] and o["hasTextHtml"]
    part = next(n for n in o["tree"] if n["contentType"] == "text/html")
    res = eml.eml_part(str(p), part["partId"])
    assert res["tables"] == [[["Cable", "Metres"], ["XLPE 3C 16mm", "1200"]]]
    assert "XLPE 3C 16mm\t1200" in res["structuredText"]


def test_eml_part_text_paging_and_missing(tmp_path):
    p = build_eml(tmp_path / "m.eml")
    r = eml.eml_part(str(p), "1.1.1", max_chars=5)
    assert r["truncated"] and r["next_cursor"] == "5" and len(r["text"]) == 5
    miss = eml.eml_part(str(p), "9.9")
    assert miss["unreadable"]


def test_eml_extract_attachment(tmp_path):
    p = build_eml(tmp_path / "m.eml")
    o = eml.eml_overview(str(p))
    pid = next(a["partId"] for a in o["attachments"] if a["name"] == "spec.pdf")
    data, name = eml.eml_extract(str(p), pid)
    assert data == b"%PDF-1.4 fake" and name == "spec.pdf"


def test_msg_reports_unsupported_or_error(tmp_path):
    p = tmp_path / "bad.msg"
    p.write_bytes(b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1" + b"junk" * 50)
    r = msg.msg_overview(str(p))
    _jsonable(r)
    assert r["unreadable"]


# ---- xlsx ----

def test_xlsx_overview_hidden_merged(tmp_path):
    p = build_xlsx(tmp_path / "w.xlsx")
    o = xlsx.xlsx_overview(str(p))
    _jsonable(o)
    states = {s["name"]: s["state"] for s in o["sheets"]}
    assert states == {"Schedule": "visible", "Secret": "hidden", "VeryHidden": "veryHidden"}
    s = o["sheets"][0]
    assert s["mergedCells"] == ["A1:C1"] and 4 in s["hiddenRows"] and "E" in s["hiddenColumns"] and s["commentCount"] == 1


def test_xlsx_read_formula_value_colours_comment(tmp_path):
    p = build_xlsx(tmp_path / "w.xlsx")
    r = xlsx.xlsx_read(str(p), "Schedule", "A1:C4")
    cells = {c["ref"]: c for c in r["cells"]}
    assert cells["C3"]["formula"] == "=B3*2"
    assert cells["C3"]["value"] is None and "no cached value" in cells["C3"]["note"]  # generated file never calculated
    assert cells["B3"]["fill"].endswith("FFFF00")
    assert cells["A3"]["fontColor"].endswith("FF0000") and cells["A3"]["bold"] and cells["A3"]["comment"] == "check size"
    assert cells["A4"]["hidden"] and r["merged"] == ["A1:C1"]


def test_xlsx_read_cell_cap_paginates(tmp_path, monkeypatch):
    p = build_xlsx(tmp_path / "w.xlsx")
    r = xlsx.xlsx_read(str(p), "Schedule", "A1:E4", max_cells=6)
    assert r["truncated"] and r["next_cursor"] == "6" and r["scannedCells"] == 6
    r2 = xlsx.xlsx_read(str(p), "Schedule", "A1:E4", cursor=r["next_cursor"], max_cells=100)
    assert not r2["truncated"]


def test_xlsx_missing_sheet_reported(tmp_path):
    p = build_xlsx(tmp_path / "w.xlsx")
    r = xlsx.xlsx_read(str(p), "Nope")
    assert r["unreadable"] and "Schedule" in r["sheets"]


def test_xls_via_xlrd_reports_clean_error_on_non_xls(tmp_path):
    p = tmp_path / "x.xls"
    p.write_bytes(b"not an xls")
    with pytest.raises(Exception):
        xlsx.xls_overview(str(p))


# ---- pdf ----

def test_pdf_text_tables_render(tmp_path):
    p = str(build_pdf(tmp_path / "a.pdf"))
    o = pdf.pdf_overview(p)
    _jsonable(o)
    assert o["pageCount"] == 1 and not o["pages"][0]["likelyScanned"]
    assert "Cable quote request" in pdf.pdf_text(p, 1)["text"]
    t = pdf.pdf_tables(p, 1)
    assert t["tables"] and t["tables"][0]["rows"][1] == ["CU4", "500"]
    png, meta = pdf.render_pdf(p, 1, 1.0)
    assert png[:8] == b"\x89PNG\r\n\x1a\n" and meta["width"] == 612
    region, rmeta = pdf.render_pdf(p, 1, 1.0, region=[72, 100, 272, 200])
    assert rmeta["width"] == 200 and rmeta["height"] == 100


def test_pdf_blank_flagged_scanned(tmp_path):
    o = pdf.pdf_overview(str(build_blank_pdf(tmp_path / "b.pdf")))
    assert o["likelyScannedPages"] == [1]


def test_pdf_bad_page(tmp_path):
    with pytest.raises(ValueError):
        pdf.pdf_text(str(build_pdf(tmp_path / "a.pdf")), 5)


# ---- docx / image ----

def test_docx_overview(tmp_path):
    o = docx_mod.docx_overview(str(build_docx(tmp_path / "d.docx")))
    _jsonable(o)
    assert any("300m" in p["text"] for p in o["paragraphs"])
    assert o["tables"][0]["rows"][1] == ["3C 4mm", "300"] and len(o["images"]) == 1


def test_image_info_and_png(tmp_path):
    p = tmp_path / "i.png"
    p.write_bytes(png_bytes(size=(4000, 2000)))
    assert image.image_info(str(p))["width"] == 4000
    png, meta = image.image_png(str(p), max_side=500)
    assert meta["width"] == 500 and meta["originalWidth"] == 4000 and png[:4] == b"\x89PNG"
