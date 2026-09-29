"""The RFQ parser's HTTP surface: a private service the PHP API calls.

In production this binds 127.0.0.1 only and has no nginx location, exactly like
the product finder: PHP authenticates the caller, checks `RFQ.*` permissions and
owns every store, so this process has no authentication of its own and must
never be reachable from outside the instance. It runs as its own gunicorn
process (`rfq_wsgi.py`) so a long schedule cannot hold up the finder.

Envelope matches `tricab-api`'s `JsonResponder`: `{success, data}` and
`{success, error:{code, message}}`.

Two parse routes, one per source action, with the same answers:

- `POST /api/parse/text` is the source's `match-lines`: the browser splits the
  pasted text on newlines, trims and drops blank lines, and pairs circuit labels
  with their lines. Here the split happens server side so the API sends the text
  as the rep pasted it.
- `POST /api/parse/file` is the source's `parse-file`: `file_parser.parse_file`
  on the upload, then `match_items`. The upload is written to a private temp file
  under a generated name — its filename is display metadata, never a path.

Both accept `corrections` (see `rfq_parser.corrections`). Neither stores
anything.

The PHP API does not call these. It calls `rfq-parser.v1` (`rfq_parser.contract`,
registered below): the same functions behind the internal token, with source
accounting and the contract envelope. These two stay as the parity surface that
`tools/compare_rfq_parser.py` and the service tests hold to the source.
"""

from __future__ import annotations

import hashlib
import json
import logging
import os
import tempfile
import time
from functools import lru_cache
from pathlib import Path
from typing import Any

from flask import Flask, jsonify, request
from werkzeug.exceptions import RequestEntityTooLarge

from tricab_cable_rules import ENGINE_VERSION
from tricab_cable_rules.extractor import pair_circuit_label_lines

from . import attachment_reader, corrections, llm_fallback, normalizer, pipeline, reader_normalizer, table_shape, vision_ocr
from . import intake
from ._source import SOURCE_COMMIT
from .file_parser import parse_file

logging.basicConfig(level=logging.INFO)
log = logging.getLogger("rfq-parser")


def _int_setting(name: str, default: int) -> int:
    try:
        return max(1, int(os.environ.get(name) or default))
    except ValueError:
        return default


# Bounds on one request. The source had none on the paste path and relied on PHP's
# 100 s per-action timeout; the platform states them.
MAX_UPLOAD_BYTES = _int_setting("RFQ_PARSER_MAX_UPLOAD_BYTES", 25 * 1024 * 1024)
MAX_TEXT_CHARS = _int_setting("RFQ_PARSER_MAX_TEXT_CHARS", 200_000)
MAX_LINES = _int_setting("RFQ_PARSER_MAX_LINES", 2_000)

# What `file_parser.parse_file` reads (its own suffix dispatch). Anything else is
# refused here with a clear error rather than failing inside the parser.
FILE_SUFFIXES = frozenset({".xlsx", ".xlsm", ".xls", ".docx", ".pdf", ".csv", ".txt"})

app = Flask(__name__, static_folder=None)
app.config["MAX_CONTENT_LENGTH"] = MAX_UPLOAD_BYTES + 64 * 1024  # room for the form fields

from .contract import v1 as _contract_v1  # noqa: E402 - needs the limits above

app.register_blueprint(_contract_v1)


def success(data: Any):
    return jsonify({"success": True, "data": data})


def failure(code: str, message: str, status: int = 400):
    return jsonify({"success": False, "error": {"code": code, "message": message}}), status


@app.errorhandler(RequestEntityTooLarge)
def too_large(_exc):
    return failure("FILE_TOO_LARGE", f"The upload is larger than {MAX_UPLOAD_BYTES} bytes.", 413)


def _corrections_from(raw: Any) -> dict[str, dict[str, Any]] | None:
    """`{key: {product_code, confirmations}}`, or None when malformed (then it is refused)."""
    if raw in (None, "", {}):
        return {}
    if isinstance(raw, str):
        try:
            raw = json.loads(raw)
        except ValueError:
            return None
    if not isinstance(raw, dict):
        return None
    out: dict[str, dict[str, Any]] = {}
    for key, value in raw.items():
        if not isinstance(key, str) or not isinstance(value, dict) or not value.get("product_code"):
            return None
        out[key] = {"product_code": str(value["product_code"]), "confirmations": int(value.get("confirmations") or 0)}
    return out


@lru_cache(maxsize=1)
def _catalogue_fingerprint() -> str:
    """First 16 hex digits of a SHA-256 over the catalogue files this process reads."""
    digest = hashlib.sha256()
    for path in (pipeline.PRODUCT_CSV, pipeline.STYLE_XLSX, pipeline.MARINE_XLSX):
        if path.exists():
            digest.update(path.name.encode("utf-8"))
            digest.update(path.read_bytes())
    return digest.hexdigest()[:16]


def versions() -> dict[str, Any]:
    """What produced an answer, stamped on every one (plan 3.6): the code grammar, the
    source commit the parser was generated from, the catalogue, and the model of each
    AI path that is switched on. An AI path that is off is absent, not null."""
    models: dict[str, str] = {}
    if llm_fallback.openrouter_enabled():
        models["line_fallback"] = os.environ.get("OPENROUTER_MODEL", llm_fallback.DEFAULT_MODEL)
    if normalizer.normalizer_enabled():
        models["normalizer"] = normalizer.normalizer_model()
    if table_shape.table_shape_enabled():
        models["table_shape"] = table_shape.table_shape_model()
    if attachment_reader.reader_enabled():
        models["reader"] = attachment_reader.reader_model()
    if reader_normalizer.translator_enabled():
        models["translator"] = reader_normalizer.translator_model()
    if vision_ocr.vision_ocr_enabled():
        models["vision_ocr"] = os.environ.get("VISION_OCR_PRIMARY_MODEL", vision_ocr.DEFAULT_PRIMARY_MODEL)
    return {
        "engine": ENGINE_VERSION,
        "parser_source": SOURCE_COMMIT,
        "catalogue": _catalogue_fingerprint(),
        "models": models,
    }


def _match(items: list[dict[str, Any]], learned: dict[str, dict[str, Any]]) -> dict[str, Any]:
    budget = pipeline.new_llm_budget(line_count=len(items))
    started = time.monotonic()
    with corrections.using(learned):
        results = pipeline.match_items(items, budget=budget)
    return {
        "results": results,
        "llm_calls": budget["used"],
        "elapsed_ms": int((time.monotonic() - started) * 1000),
        "versions": versions(),
    }


@app.get("/api/health")
def api_health():
    """Alive, and what would run. Names and flags only, never a setting's value."""
    return success(
        {
            "status": "ok",
            "versions": versions(),
            "catalogue_loaded": pipeline.catalog.loaded,
            "ai": {
                "line_fallback": llm_fallback.openrouter_enabled(),
                "normalizer": normalizer.normalizer_enabled(),
                "table_shape": table_shape.table_shape_enabled(),
                # The reader paths, per input (source `reader_route_enabled`), and the
                # translator, which runs only for a reader job's message context.
                "reader": {
                    "spreadsheets": attachment_reader.reader_enabled(),
                    "pdf": attachment_reader.pdf_reader_enabled(),
                    "documents": attachment_reader.document_reader_enabled(),
                    "images": attachment_reader.image_reader_enabled(),
                },
                "translator": reader_normalizer.translator_enabled(),
                "vision_ocr": vision_ocr.vision_ocr_enabled(),
            },
            "limits": {
                "max_upload_bytes": MAX_UPLOAD_BYTES,
                "max_text_chars": MAX_TEXT_CHARS,
                "max_lines": MAX_LINES,
                "max_unzipped_bytes": intake.MAX_UNZIPPED_BYTES,
                "max_sheets": intake.MAX_SHEETS,
                "max_pdf_pages": intake.MAX_PDF_PAGES,
                "max_parse_seconds": intake.MAX_PARSE_SECONDS,
            },
        }
    )


@app.get("/api/ready")
def api_ready():
    """Ready means the catalogue is in memory. The first call loads it (seconds, once)."""
    try:
        count = len(pipeline.catalog.products_by_code)
    except Exception as exc:  # noqa: BLE001 - reported, not raised
        log.exception("catalogue failed to load")
        return failure("CATALOGUE_UNAVAILABLE", str(exc), 503)
    return success({"status": "ready", "products": count})


@app.post("/api/parse/text")
def api_parse_text():
    payload = request.get_json(silent=True)
    if not isinstance(payload, dict) or not isinstance(payload.get("text"), str):
        return failure("VALIDATION_FAILED", "A JSON object with a text string is required.", 422)
    text = payload["text"]
    if len(text) > MAX_TEXT_CHARS:
        return failure("TEXT_TOO_LONG", f"The text is longer than {MAX_TEXT_CHARS} characters.", 413)
    learned = _corrections_from(payload.get("corrections"))
    if learned is None:
        return failure("VALIDATION_FAILED", "corrections must map a key to {product_code, confirmations}.", 422)

    # The source browser's split (static/app.js, the Match button), then the source
    # CLI's pairing (rfq_cli.action_match_lines).
    lines = [line.strip() for line in text.replace("\r\n", "\n").split("\n")]
    lines = [line for line in lines if line]
    if not lines:
        return failure("VALIDATION_FAILED", "The text has no lines to read.", 422)
    if len(lines) > MAX_LINES:
        return failure("TOO_MANY_LINES", f"The text has more than {MAX_LINES} lines.", 413)
    items = [
        {
            "text": line,
            "source": {"line_number": idx + 1},
            "context": [],
            "metadata": {"reference": reference} if reference else {},
        }
        for idx, (line, reference) in enumerate(pair_circuit_label_lines(lines))
    ]
    try:
        data = _match(items, learned)
    except Exception as exc:  # noqa: BLE001 - surfaced as a clean API error
        log.exception("text parse failed")
        return failure("PARSE_FAILED", str(exc)[:500], 500)
    return success({"line_count": len(items), **data})


@app.post("/api/parse/file")
def api_parse_file():
    upload = request.files.get("file")
    if upload is None:
        return failure("VALIDATION_FAILED", "A multipart file field named 'file' is required.", 422)
    display_name = Path(upload.filename or "upload").name[:255] or "upload"
    suffix = Path(display_name).suffix.lower()
    if suffix not in FILE_SUFFIXES:
        return failure("UNSUPPORTED_FILE", f"{suffix or 'This file type'} is not a supported RFQ format.", 415)
    learned = _corrections_from(request.form.get("corrections"))
    if learned is None:
        return failure("VALIDATION_FAILED", "corrections must map a key to {product_code, confirmations}.", 422)

    with tempfile.TemporaryDirectory(prefix="rfq-parse-") as work:
        # The parser dispatches on suffix, so the temp file keeps it; the name is ours.
        path = Path(work) / f"upload{suffix}"
        upload.save(path)
        if path.stat().st_size == 0:
            return failure("VALIDATION_FAILED", "The file is empty.", 422)

        # rfq_cli.action_parse_file, field for field.
        items: list[dict[str, Any]] = []
        errors: list[dict[str, Any]] = []
        try:
            for idx, item in enumerate(parse_file(path)):
                items.append(
                    {
                        "text": item["text"],
                        "source": {
                            "file_name": display_name,
                            "sheet": item.get("sheet"),
                            "row_number": item.get("row_number") or idx + 1,
                        },
                        "context": item.get("context") or [],
                        "metadata": item.get("metadata") or {},
                    }
                )
        except Exception as exc:  # noqa: BLE001 - the source reports a file error, it does not raise
            errors.append({"file_name": display_name, "error": str(exc)[:500]})

    if len(items) > MAX_LINES:
        return failure("TOO_MANY_LINES", f"The file has more than {MAX_LINES} rows to read.", 413)
    try:
        data = _match(items, learned)
    except Exception as exc:  # noqa: BLE001 - surfaced as a clean API error
        log.exception("file parse failed")
        return failure("PARSE_FAILED", str(exc)[:500], 500)
    return success({"ok": bool(data["results"]) or not errors, "item_count": len(items), "errors": errors, **data})


if __name__ == "__main__":
    port = _int_setting("RFQ_PARSER_PORT", 8002)
    log.info("RFQ parser on http://127.0.0.1:%s", port)
    app.run(host="127.0.0.1", port=port)
