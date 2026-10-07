"""What a document must be before the parser opens it, and how long it may take (plan 3.3).

The source had no page, sheet, row or decompression cap and relied on PHP's 100 s
per-action timeout. Here every document is checked before any library reads it:

- **Type from the bytes, not the name.** The suffix picks the reader, as in the
  source, and the leading bytes must agree with it: an `.xlsx` is a ZIP, an `.xls`
  an OLE compound file, a `.pdf` starts `%PDF`. A file whose bytes say otherwise is
  `unsupported_format`, never parsed on a guess. An image only has to be a PNG, JPEG
  or WEBP: screenshots often carry another image type's extension, so its suffix and
  content type are taken from the bytes.
- **Bounded decompression.** An OOXML file is a ZIP, so a few kilobytes can inflate
  to gigabytes. The central directory is read without extracting anything, and the
  total declared size, the entry count and the ratio are capped. A ZIP whose
  declared sizes lie is still stopped: extraction is by the libraries, inside the
  time and memory of a child process.
- **Bounded structure.** At most `MAX_SHEETS` worksheets and `MAX_PDF_PAGES` pages,
  counted from the workbook part and the page tree before a parse starts.
- **Bounded runtime.** `parse_file` and the row accounting run in a child process
  that is killed when the document's time is up, so a pathological file cannot hold
  a gunicorn worker, and a conversion that hangs dies with it.

Rows are bounded after the parse by the caller (`MAX_LINES`), because a row count is
only known once the rows are read.
"""

from __future__ import annotations

import multiprocessing
import os
import re
import tempfile
import zipfile
from dataclasses import dataclass
from pathlib import Path
from typing import Any


def _int_setting(name: str, default: int) -> int:
    try:
        return max(1, int(os.environ.get(name) or default))
    except ValueError:
        return default


MAX_UNZIPPED_BYTES = _int_setting("RFQ_PARSER_MAX_UNZIPPED_BYTES", 200 * 1024 * 1024)
MAX_ZIP_ENTRIES = _int_setting("RFQ_PARSER_MAX_ZIP_ENTRIES", 5_000)
MAX_ZIP_RATIO = _int_setting("RFQ_PARSER_MAX_ZIP_RATIO", 200)
MAX_SHEETS = _int_setting("RFQ_PARSER_MAX_SHEETS", 50)
MAX_PDF_PAGES = _int_setting("RFQ_PARSER_MAX_PDF_PAGES", 200)
# Per document, and never more than what is left of the request's own deadline.
MAX_PARSE_SECONDS = _int_setting("RFQ_PARSER_MAX_PARSE_SECONDS", 45)

DOCUMENT_SUFFIXES = frozenset({".xlsx", ".xlsm", ".xls", ".docx", ".pdf", ".csv", ".txt"})
IMAGE_SUFFIXES = frozenset({".png", ".jpg", ".jpeg", ".webp"})
OOXML_SUFFIXES = frozenset({".xlsx", ".xlsm", ".docx"})

_OLE = b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1"
_SHEET_ELEMENT = re.compile(rb"<(?:\w+:)?sheet\b")


class IntakeRefused(Exception):
    """A document the parser will not open. `code` is an `RfqParserFailure` code."""

    def __init__(self, code: str, message: str) -> None:
        super().__init__(message)
        self.code = code
        self.message = message


@dataclass(frozen=True)
class Document:
    public_id: str
    file_name: str  # display metadata only, never a path
    suffix: str
    content_type: str
    data: bytes

    @property
    def is_image(self) -> bool:
        return self.suffix in IMAGE_SUFFIXES


def display_name(raw: Any) -> str:
    return Path(str(raw or "upload")).name[:255] or "upload"


def check(public_id: str, file_name: Any, content_type: Any, data: bytes) -> Document:
    """The document, or `IntakeRefused` naming why not. Opens no parsing library."""
    name = display_name(file_name)
    suffix = Path(name).suffix.lower()
    if suffix not in DOCUMENT_SUFFIXES and suffix not in IMAGE_SUFFIXES:
        raise IntakeRefused("unsupported_format", f"{suffix or 'A file with no extension'} is not a supported RFQ format.")
    if not data:
        raise IntakeRefused("invalid_request", f"{name} is empty.")
    content_type = str(content_type or "").split(";", 1)[0].strip().lower()
    if suffix in IMAGE_SUFFIXES:
        # Screenshots are often saved under the wrong image extension, and the source
        # opened them by content. Take the type from the bytes, so the model is not
        # sent a PNG labelled image/jpeg.
        suffix, content_type = _image_type(name, data)
    else:
        _check_signature(name, suffix, data)
    if suffix in OOXML_SUFFIXES:
        _check_zip(name, suffix, data)
    if suffix == ".pdf":
        _check_pdf(name, data)
    return Document(public_id, name, suffix, content_type, data)


def _image_type(name: str, data: bytes) -> tuple[str, str]:
    """The image's real suffix and content type, whatever its name says."""
    head = data[:16]
    if head.startswith(b"\x89PNG\r\n\x1a\n"):
        return ".png", "image/png"
    if head.startswith(b"\xff\xd8\xff"):
        return ".jpg", "image/jpeg"
    if head[:4] == b"RIFF" and head[8:12] == b"WEBP":
        return ".webp", "image/webp"
    raise IntakeRefused("unsupported_format", f"{name} is not a PNG, JPEG or WebP image.")


def _check_signature(name: str, suffix: str, data: bytes) -> None:
    head = data[:16]
    expected = {
        ".xlsx": head.startswith(b"PK\x03\x04"),
        ".xlsm": head.startswith(b"PK\x03\x04"),
        ".docx": head.startswith(b"PK\x03\x04"),
        ".xls": head.startswith(_OLE),
        ".pdf": b"%PDF" in data[:1024],
    }.get(suffix)
    if expected is False:
        raise IntakeRefused("unsupported_format", f"{name} does not contain what its {suffix} name says.")
    if suffix in {".csv", ".txt"} and b"\x00" in data[:4096]:
        raise IntakeRefused("unsupported_format", f"{name} is not a text file.")


def _check_zip(name: str, suffix: str, data: bytes) -> None:
    import io

    try:
        archive = zipfile.ZipFile(io.BytesIO(data))
    except zipfile.BadZipFile:
        raise IntakeRefused("unsupported_format", f"{name} is not a readable {suffix} file.") from None
    with archive:
        entries = archive.infolist()
        if len(entries) > MAX_ZIP_ENTRIES:
            raise IntakeRefused("input_too_large", f"{name} has more than {MAX_ZIP_ENTRIES} parts.")
        declared = sum(max(0, entry.file_size) for entry in entries)
        if declared > MAX_UNZIPPED_BYTES:
            raise IntakeRefused("input_too_large", f"{name} expands to more than {MAX_UNZIPPED_BYTES // (1024 * 1024)} MB.")
        if declared > MAX_ZIP_RATIO * max(1, len(data)) and declared > 10 * 1024 * 1024:
            raise IntakeRefused("input_too_large", f"{name} expands too far to be a real document.")
        if suffix in {".xlsx", ".xlsm"}:
            try:
                info = archive.getinfo("xl/workbook.xml")
            except KeyError:
                raise IntakeRefused("unsupported_format", f"{name} has no workbook in it.") from None
            if info.file_size > 16 * 1024 * 1024:
                raise IntakeRefused("input_too_large", f"{name} has an oversized workbook part.")
            sheets = len(_SHEET_ELEMENT.findall(archive.read(info)))
            if sheets > MAX_SHEETS:
                raise IntakeRefused("input_too_large", f"{name} has {sheets} sheets; the limit is {MAX_SHEETS}.")


def _check_pdf(name: str, data: bytes) -> None:
    """The page count from the page tree, through pypdfium2 (installed with pdfplumber),
    which reads the cross-reference table without laying out a page."""
    try:
        import pypdfium2

        pdf = pypdfium2.PdfDocument(data)
        try:
            pages = len(pdf)
        finally:
            pdf.close()
    except Exception:  # noqa: BLE001 - an unreadable PDF is refused, not investigated
        raise IntakeRefused("unsupported_format", f"{name} is not a readable PDF.") from None
    if pages > MAX_PDF_PAGES:
        raise IntakeRefused("input_too_large", f"{name} has {pages} pages; the limit is {MAX_PDF_PAGES}.")


# --- the bounded parse ---------------------------------------------------------------


def _child(conn: Any, path: str, file_name: str) -> None:
    """Runs in the child: `parse_file`, then the accounting walk over the same file."""
    try:
        from . import accounting
        from .file_parser import parse_file

        parsed = parse_file(Path(path))
        rows = accounting.file_rows(Path(path), file_name, parsed)
        conn.send(("ok", parsed, rows))
    except Exception as exc:  # noqa: BLE001 - reported to the parent as a file error
        conn.send(("error", type(exc).__name__, str(exc)[:300]))
    finally:
        conn.close()


_CONTEXT: Any = None


def _context() -> Any:
    """`forkserver` where there is one: a gunicorn worker runs pricing threads, and a
    plain `fork` of a threaded process can deadlock the child on a lock a thread held.
    The server is started once, preloaded with the parser, and forks clean children."""
    global _CONTEXT
    if _CONTEXT is None:
        methods = multiprocessing.get_all_start_methods()
        _CONTEXT = multiprocessing.get_context("forkserver" if "forkserver" in methods else "spawn")
        if "forkserver" in methods:
            _CONTEXT.set_forkserver_preload(["rfq_parser.file_parser", "rfq_parser.accounting"])
    return _CONTEXT


def parse_bounded(document: Document, seconds: float) -> tuple[list[dict[str, Any]], Any]:
    """`(parse_file items, accounting rows)` for one document, within `seconds`.

    Raises `IntakeRefused("timeout", …)` when the child runs out of time and
    `IntakeRefused("unsupported_format", …)` when the file cannot be parsed.
    """
    seconds = max(0.05, min(float(MAX_PARSE_SECONDS), seconds))
    context = _context()
    with tempfile.TemporaryDirectory(prefix="rfq-parse-") as work:
        # The parser dispatches on suffix, so the temp file keeps it; the name is ours.
        path = Path(work) / f"upload{document.suffix}"
        path.write_bytes(document.data)
        receive, send = context.Pipe(duplex=False)
        process = context.Process(target=_child, args=(send, str(path), document.file_name), daemon=True)
        process.start()
        send.close()
        try:
            if not receive.poll(seconds):
                raise IntakeRefused("timeout", f"{document.file_name} took longer than {int(seconds)} s to read.")
            try:
                outcome = receive.recv()
            except EOFError:
                raise IntakeRefused("unsupported_format", f"{document.file_name} could not be read.") from None
        finally:
            receive.close()
            if process.is_alive():
                process.kill()
            process.join(5)
    if outcome[0] != "ok":
        raise IntakeRefused("unsupported_format", f"{document.file_name} could not be read ({outcome[1]}).")
    return outcome[1], outcome[2]
