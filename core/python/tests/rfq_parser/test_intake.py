"""Intake bounds (plan 3.3): what a document must be before the parser opens it.

Platform-owned. Every refusal here happens before a parsing library reads the file,
except the time limit, which is the child process being killed.
"""

from __future__ import annotations

import io
import zipfile

import pytest
from openpyxl import Workbook

from rfq_parser import intake


def _xlsx(rows: list[list], sheets: int = 1) -> bytes:
    wb = Workbook()
    for index in range(1, sheets):
        wb.create_sheet(f"S{index}")
    for row in rows:
        wb.active.append(row)
    buffer = io.BytesIO()
    wb.save(buffer)
    return buffer.getvalue()


def _refused(name: str, data: bytes) -> intake.IntakeRefused:
    with pytest.raises(intake.IntakeRefused) as caught:
        intake.check("doc", name, "", data)
    return caught.value


@pytest.mark.parametrize(
    "name,data",
    [
        ("schedule.xlsx", b"not a zip at all"),
        ("schedule.xls", b"PK\x03\x04 a zip calling itself xls"),
        ("drawing.pdf", b"PK\x03\x04 a zip calling itself pdf"),
        ("scan.png", b"%PDF-1.7 a pdf calling itself png"),
        ("lines.csv", b"a,b\x00\x00binary"),
    ],
)
def test_the_bytes_must_agree_with_the_name(name, data):
    assert _refused(name, data).code == "unsupported_format"


@pytest.mark.parametrize(
    "name,supplied,data,suffix,content_type",
    [
        ("screenshot.jpg", "image/jpeg", b"\x89PNG\r\n\x1a\n a png", ".png", "image/png"),
        ("scan.png", "image/png", b"\xff\xd8\xff a jpeg", ".jpg", "image/jpeg"),
        ("photo.jpeg", "", b"RIFF\x00\x00\x00\x00WEBP a webp", ".webp", "image/webp"),
        ("table.png", "image/png", b"\x89PNG\r\n\x1a\n a png", ".png", "image/png"),
    ],
)
def test_an_image_is_typed_by_its_bytes_not_its_name(name, supplied, data, suffix, content_type):
    document = intake.check("doc", name, supplied, data)
    assert (document.file_name, document.suffix, document.content_type) == (name, suffix, content_type)
    assert document.is_image


def test_an_unknown_type_and_an_empty_file_are_refused():
    assert _refused("setup.exe", b"MZ").code == "unsupported_format"
    assert _refused("empty.csv", b"").code == "invalid_request"


def test_the_file_name_is_display_metadata_only():
    document = intake.check("doc", "../../etc/Customer schedule.csv", "text/csv; charset=utf-8", b"a,b\n")
    assert document.file_name == "Customer schedule.csv"
    assert document.content_type == "text/csv"


def test_a_zip_bomb_is_refused_from_its_directory():
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w", zipfile.ZIP_DEFLATED) as archive:
        archive.writestr("xl/workbook.xml", "<workbook><sheet name='a'/></workbook>")
        archive.writestr("xl/worksheets/sheet1.xml", b"\0" * (intake.MAX_UNZIPPED_BYTES + 1))
    data = buffer.getvalue()
    assert len(data) < 1024 * 1024
    assert _refused("bomb.xlsx", data).code == "input_too_large"


def test_too_many_sheets_is_refused_before_parsing(monkeypatch):
    monkeypatch.setattr(intake, "MAX_SHEETS", 3)
    refusal = _refused("wide.xlsx", _xlsx([["a"]], sheets=4))
    assert refusal.code == "input_too_large" and "4 sheets" in refusal.message
    assert intake.check("doc", "ok.xlsx", "", _xlsx([["a"]], sheets=3)).suffix == ".xlsx"


def test_too_many_pages_is_refused_from_the_page_tree(monkeypatch):
    import pypdfium2

    pdf = pypdfium2.PdfDocument.new()
    for _ in range(3):
        pdf.new_page(200, 200)
    buffer = io.BytesIO()
    pdf.save(buffer)
    pdf.close()
    monkeypatch.setattr(intake, "MAX_PDF_PAGES", 2)
    refusal = _refused("long.pdf", buffer.getvalue())
    assert refusal.code == "input_too_large" and "3 pages" in refusal.message
    assert _refused("broken.pdf", b"%PDF-1.4 truncated").code == "unsupported_format"


def test_a_parse_runs_in_a_child_and_returns_its_accounting():
    document = intake.check("doc", "s.xlsx", "", _xlsx([["Description", "Qty (m)"], ["4C 16mm2 Cu XLPE/PVC", 120]]))
    parsed, rows = intake.parse_bounded(document, 30)
    assert [item["text"] for item in parsed] == ["4C 16mm2 Cu XLPE/PVC"]
    assert [row.kind for row in rows] == ["skipped", "line"]


def test_a_parse_that_runs_out_of_time_is_killed():
    """60,000 real rows take about nine seconds to parse; with one second the parent
    stops waiting, kills the child and says `timeout`."""
    import time

    lines = "\n".join(f"{n},4C 16mm2 Cu XLPE/PVC 0.6/1kV,{n}" for n in range(1, 60_001))
    document = intake.check("doc", "huge.csv", "", ("Item,Description,Qty\n" + lines).encode())
    started = time.monotonic()
    with pytest.raises(intake.IntakeRefused) as caught:
        intake.parse_bounded(document, 1)
    assert caught.value.code == "timeout"
    assert time.monotonic() - started < 5


def test_a_file_the_parser_cannot_read_is_unsupported_not_a_crash():
    buffer = io.BytesIO()
    with zipfile.ZipFile(buffer, "w") as archive:
        archive.writestr("xl/workbook.xml", "<workbook><sheet name='a'/></workbook>")
    document = intake.check("doc", "hollow.xlsx", "", buffer.getvalue())
    with pytest.raises(intake.IntakeRefused) as caught:
        intake.parse_bounded(document, 30)
    assert caught.value.code == "unsupported_format"
