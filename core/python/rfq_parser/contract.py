"""`rfq-parser.v1`: the boundary the PHP API calls (architecture doc §3.2, §6.1).

The `/api/*` routes in `app.py` answer exactly as the source's actions do and are
what the parity tools compare. This is the platform's own surface over the same
functions, in the shape `TriCab\\Core\\Rfq\\Parser\\ParseRequest` sends and
`ParseResponse` reads strictly:

- `POST /v1/parse` — pasted text, or up to ten documents, into parsed lines with
  the source accounting (`coverage`, `rfq_parser.accounting`) and what produced the
  answer (`engine`, `provider`). Documents are read deterministically inside the
  bounds of `rfq_parser.intake`; each says whether a model reader will re-read it
  (`documents[].readerRoute`), which is PHP's cue to enqueue a reader job.
- `POST /v1/read` — one document through the model reader, for a reader job
  (`rfq_parser.reading`): the source's portal re-read, with the job held by PHP.
- `POST /v1/readiness` — the source's `quote_readiness` over a draft's rows and
  header, so the status a save leaves is the source's rule, not a PHP copy of it.
- `POST /v1/products/search` — the source's product-code autocomplete
  (`product_search.search_products`) over the catalogue the matcher uses.
- `GET /v1/health`, `GET /v1/ready` — `RfqParserClient::isAlive()` / `isReady()`.

Every `/v1` route needs `Authorization: Bearer <RFQ_PARSER_INTERNAL_TOKEN>` and
refuses everything when the token is not configured: loopback keeps other hosts
off, the token keeps other processes on the instance off. Neither the token nor a
request body is ever logged.

Every answer carries `contract` and the caller's `correlationId`. Errors are
`{contract, correlationId, error: {code, message}}` with a code from
`RfqParserFailure::CODES`, and the message is safe to show a person: no document
content, no exception text.
"""

from __future__ import annotations

import base64
import binascii
import hmac
import json
import logging
import os
import re
import time
from pathlib import PurePath
from typing import Any

from flask import Blueprint, jsonify, request

from tricab_cable_rules import ENGINE_VERSION
from tricab_cable_rules.extractor import pair_circuit_label_lines

from . import accounting, corrections, intake, pipeline, reading
from .product_search import search_products
from ._source import SOURCE_COMMIT
from .quote_readiness import exclusion_reason, quote_readiness

log = logging.getLogger("rfq-parser")

CONTRACT = "rfq-parser.v1"
TOKEN_SETTING = "RFQ_PARSER_INTERNAL_TOKEN"

# `CorrelationId::PATTERN` on the PHP side. Anything else is refused, not echoed.
_CORRELATION_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$")

# `RfqServiceLimits` on the PHP side; PHP refuses first, these are the backstop.
MAX_DEADLINE_MS = 600_000
MIN_DEADLINE_MS = 1_000
MAX_READINESS_ROWS = 2_000
# `RfqServiceLimits::MAX_DOCUMENTS_PER_INTAKE`, `MAX_DOCUMENT_BYTES`, `MAX_IMAGE_BYTES`.
MAX_DOCUMENTS = 10
MAX_DOCUMENT_BYTES = 20 * 1024 * 1024
MAX_IMAGE_BYTES = 8 * 1024 * 1024

# Cost API answers that mean "the price lookup failed", as opposed to "this line
# has no code or length yet". Rows priced through a failure are degraded: they have
# no price, and no stock/make answer for the MOQ and prepack rules, so the rep has to
# be told. (Since source 6e70ee3 a failed lookup leaves the quantities as asked; before
# it, prepack rounding rewrote cut lengths.)
_PRICING_FAILED = frozenset({"error"})

v1 = Blueprint("rfq_parser_v1", __name__)


class ContractError(Exception):
    def __init__(self, code: str, message: str, status: int):
        super().__init__(message)
        self.code = code
        self.message = message
        self.status = status


def _correlation_id(value: Any) -> str | None:
    return value if isinstance(value, str) and _CORRELATION_ID.match(value) else None


def _answer(correlation_id: str | None, body: dict[str, Any], status: int = 200):
    return jsonify({"contract": CONTRACT, "correlationId": correlation_id, **body}), status


def _refuse(correlation_id: str | None, code: str, message: str, status: int):
    return _answer(correlation_id, {"error": {"code": code, "message": message}}, status)


@v1.before_request
def _authenticate():
    expected = os.environ.get(TOKEN_SETTING) or ""
    correlation_id = _correlation_id(request.headers.get("X-Correlation-Id"))
    if not expected:
        # Fail closed: an unset token must not mean "anyone on the instance".
        return _refuse(correlation_id, "unavailable", "The RFQ parser has no internal token configured.", 503)
    header = request.headers.get("Authorization") or ""
    supplied = header[7:] if header.startswith("Bearer ") else ""
    if not hmac.compare_digest(supplied.encode("utf-8"), expected.encode("utf-8")):
        return _refuse(correlation_id, "invalid_request", "The caller is not the platform API.", 401)
    return None


def _envelope(payload: Any) -> tuple[dict[str, Any], str]:
    """The common request fields: contract version and correlation id."""
    if not isinstance(payload, dict):
        raise ContractError("invalid_request", "A JSON object is required.", 400)
    correlation_id = _correlation_id(payload.get("correlationId"))
    if correlation_id is None:
        raise ContractError("invalid_request", "correlationId is missing or malformed.", 400)
    if payload.get("contract") != CONTRACT:
        raise ContractError("contract_mismatch", f"This parser speaks {CONTRACT}.", 400)
    return payload, correlation_id


def _engine() -> dict[str, str]:
    from .app import _catalogue_fingerprint

    return {
        "version": f"rfq_parser@{SOURCE_COMMIT[:12]}",
        "rulesVersion": ENGINE_VERSION,
        "catalogueVersion": _catalogue_fingerprint(),
    }


def text_items(text: str) -> tuple[list[dict[str, Any]], int]:
    """The source's paste path (`app.py` `/api/parse/text`): items, and the count of
    non-blank input lines they came from. A circuit label folded into the line after
    it is carried on that line as `metadata.reference`, so it is kept, not lost."""
    lines = [line.strip() for line in text.replace("\r\n", "\n").split("\n")]
    lines = [line for line in lines if line]
    items = [
        {
            "text": line,
            "source": {"line_number": idx + 1},
            "context": [],
            "metadata": {"reference": reference} if reference else {},
        }
        for idx, (line, reference) in enumerate(pair_circuit_label_lines(lines))
    ]
    return items, len(lines)


def text_coverage(text: str, items: list[dict[str, Any]], results: list[dict[str, Any]]) -> accounting.Coverage:
    """Every pasted line kept, excluded or skipped (invariant 3; `rfq_parser.accounting`).

    A label folded into the next line is counted with the lines. An item that produced
    no result at all is in none of the three unless it asked for zero, so the counts do
    not balance and the RFQ reads `partial`, which is the point: a line that vanished
    must not read as a complete parse.
    """
    return accounting.balance(accounting.text_rows(text, len(items)), results, items=items)


def _notes(results: list[dict[str, Any]]) -> tuple[bool, list[str]]:
    failed = sum(1 for r in results if ((r.get("pricing") or {}).get("status") in _PRICING_FAILED))
    if not failed:
        return False, []
    plural = "line" if failed == 1 else "lines"
    return True, [
        f"Pricing was unavailable for {failed} {plural}. They are at the quantities asked for, "
        "with no price and no MOQ or prepack rule applied; price them again before the quote goes."
    ]


def _deadline(payload: dict[str, Any]) -> int:
    deadline_ms = payload.get("deadlineMs")
    if not isinstance(deadline_ms, int) or isinstance(deadline_ms, bool) or not MIN_DEADLINE_MS <= deadline_ms <= MAX_DEADLINE_MS:
        raise ContractError("invalid_request", "deadlineMs is missing or out of range.", 400)
    return deadline_ms


def _budget(payload: dict[str, Any], line_count: int) -> dict[str, Any]:
    """PHP decides whether a model may run, per request; the text cannot turn it on."""
    options = payload.get("options") if isinstance(payload.get("options"), dict) else {}
    allow_model = options.get("allowModel") is True
    max_calls = options.get("maxModelCalls")
    budget = pipeline.new_llm_budget(line_count=line_count)
    usable = allow_model and isinstance(max_calls, int) and not isinstance(max_calls, bool) and max_calls >= 0
    budget["limit"] = min(budget["limit"], max_calls) if usable else 0
    return budget


def _documents(raw: Any) -> list[intake.Document]:
    """`documents[]` decoded and checked, or a refusal naming the first bad one."""
    if not isinstance(raw, list) or not raw:
        raise ContractError("invalid_request", "documents must be a non-empty list.", 400)
    if len(raw) > MAX_DOCUMENTS:
        raise ContractError("input_too_large", f"At most {MAX_DOCUMENTS} documents per request.", 413)
    out: list[intake.Document] = []
    for entry in raw:
        if not isinstance(entry, dict):
            raise ContractError("invalid_request", "Each document must be an object.", 400)
        public_id, encoded = entry.get("documentPublicId"), entry.get("contentBase64")
        if not isinstance(public_id, str) or not public_id or not isinstance(encoded, str):
            raise ContractError("invalid_request", "Each document needs documentPublicId, fileName, contentType and contentBase64.", 400)
        try:
            data = base64.b64decode(encoded, validate=True)
        except (binascii.Error, ValueError):
            raise ContractError("invalid_request", "contentBase64 is not base64.", 400) from None
        name = intake.display_name(entry.get("fileName"))
        cap = MAX_IMAGE_BYTES if PurePath(name).suffix.lower() in intake.IMAGE_SUFFIXES else MAX_DOCUMENT_BYTES
        if len(data) > cap:
            raise ContractError("input_too_large", f"{name} is larger than {cap // (1024 * 1024)} MB.", 413)
        try:
            out.append(intake.check(public_id, name, entry.get("contentType"), data))
        except intake.IntakeRefused as exc:
            raise ContractError(exc.code, exc.message, _STATUS.get(exc.code, 400)) from None
    return out


_STATUS = {"unsupported_format": 415, "input_too_large": 413, "timeout": 504, "invalid_request": 400}


def _work_items(parsed: list[dict[str, Any]], file_name: str) -> list[dict[str, Any]]:
    """The source's `rfq_cli.action_parse_file`, field for field (see `app.api_parse_file`)."""
    return [
        {
            "text": item["text"],
            "source": {"file_name": file_name, "sheet": item.get("sheet"), "row_number": item.get("row_number") or idx + 1},
            "context": item.get("context") or [],
            "metadata": item.get("metadata") or {},
        }
        for idx, item in enumerate(parsed)
    ]


# `file_parser.parse_pdf`'s placeholder when a PDF has no text layer.
_NO_PDF_TEXT = "No extractable PDF text found. This file may need OCR."


def _parse_documents(documents: list[intake.Document], payload: dict[str, Any], started: float, deadline_ms: int, correlation_id: str):
    from .app import MAX_LINES

    lines: list[dict[str, Any]] = []
    total = accounting.Coverage()
    summaries: list[dict[str, Any]] = []
    notes: list[str] = []
    budget = _budget(payload, 0)
    for document in documents:
        route = reading.reader_route(document)
        summary: dict[str, Any] = {"documentPublicId": document.public_id, "fileName": document.file_name, "readerRoute": route}
        if document.is_image:
            # No deterministic reader reads a picture; the reader job does, when one is on.
            cov = accounting.unmeasured()
            summary.update(lineCount=0, coverage=cov.to_contract())
            notes.append(f"{document.file_name} is an image; " + ("it is being read by the reader." if route else "no image reader is switched on, so it was not read."))
            total.add(cov)
            summaries.append(summary)
            continue
        remaining = deadline_ms / 1000 - (time.monotonic() - started)
        try:
            parsed, rows = intake.parse_bounded(document, remaining - 1)
        except intake.IntakeRefused as exc:
            raise ContractError(exc.code, exc.message, _STATUS.get(exc.code, 400)) from None
        items = _work_items(parsed, document.file_name)
        if len(lines) + len(items) > MAX_LINES:
            raise ContractError("input_too_large", f"The documents have more than {MAX_LINES} rows to read.", 413)
        if document.suffix == ".pdf" and [i["text"] for i in parsed] == [_NO_PDF_TEXT]:
            notes.append(f"{document.file_name} has no text layer (a scan); " + ("it is being read from the page images by the reader." if route else "no reader is switched on to read it."))
        with corrections.using({}):
            results = pipeline.match_items(items, budget=budget)
        cov = accounting.balance(rows, results, items=items, default_file=document.file_name)
        summary.update(lineCount=len(results), coverage=cov.to_contract())
        total.add(cov)
        summaries.append(summary)
        lines.extend(results)
        log.info("v1 parse %s: %s, %d rows, %d lines", correlation_id, document.suffix, cov.input_rows, len(results))
    return lines, total, summaries, notes, budget


@v1.post("/v1/parse")
def parse():
    started = time.monotonic()
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        deadline_ms = _deadline(payload)
        text = payload.get("text")
        raw_documents = payload.get("documents")
        if raw_documents:
            if text not in (None, ""):
                raise ContractError("invalid_request", "Send text or documents, not both.", 400)
            documents = _documents(raw_documents)
            results, cov, summaries, notes, budget = _parse_documents(documents, payload, started, deadline_ms, correlation_id)
            input_rows = cov.input_rows
        else:
            if not isinstance(text, str) or not text.strip():
                raise ContractError("invalid_request", "text is required.", 400)

            from .app import MAX_LINES, MAX_TEXT_CHARS

            if len(text) > MAX_TEXT_CHARS:
                raise ContractError("input_too_large", f"The text is longer than {MAX_TEXT_CHARS} characters.", 413)
            items, input_rows = text_items(text)
            if len(items) > MAX_LINES:
                raise ContractError("input_too_large", f"The text has more than {MAX_LINES} lines.", 413)
            budget = _budget(payload, len(items))
            with corrections.using({}):
                results = pipeline.match_items(items, budget=budget)
            cov = text_coverage(text, items, results)
            summaries, notes = [], []
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001 - logged by id; the message stays content-free
        log.exception("v1 parse failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The RFQ parser failed on this input.", 500)

    elapsed_ms = int((time.monotonic() - started) * 1000)
    if elapsed_ms > deadline_ms:
        # The caller has stopped waiting; say so rather than answer into a closed socket.
        return _refuse(correlation_id, "timeout", "The parse took longer than its deadline.", 504)

    from .app import versions

    degraded, pricing_notes = _notes(results)
    calls = int(budget["used"])
    model = versions()["models"].get("line_fallback") if calls else None
    log.info("v1 parse %s: %d rows, %d lines, %d model calls, %d ms", correlation_id, input_rows, len(results), calls, elapsed_ms)
    body: dict[str, Any] = {
        "lines": results,
        "coverage": cov.to_contract(),
        "engine": _engine(),
        "provider": {"mode": "model" if calls else "deterministic", "modelCalls": calls, "model": model},
        "degraded": degraded,
        "notes": notes + pricing_notes,
        "elapsedMs": elapsed_ms,
    }
    if summaries:
        body["documents"] = summaries
    return _answer(correlation_id, body)


@v1.post("/v1/read")
def read():
    """One document through the model reader, for one reader job (`rfq_parser.reading`).

    Request: the envelope, `deadlineMs`, one `document` (as in `/v1/parse`), `options`
    (`allowModel` must be true: a read is nothing but a model call) and `context`
    (`subject`, `attachments`: the draft's label and the files uploaded with it, the
    source worker's message context). A document no model reader will read is refused
    as `unsupported_format` so PHP stands the job down rather than record a read that
    never happened. A read that produced no lines is refused with the typed reason.
    """
    started = time.monotonic()
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        deadline_ms = _deadline(payload)
        options = payload.get("options") if isinstance(payload.get("options"), dict) else {}
        if options.get("allowModel") is not True:
            raise ContractError("invalid_request", "A read is a model call; options.allowModel must be true.", 400)
        (document,) = _documents([payload.get("document")]) if isinstance(payload.get("document"), dict) else (None,)
        if document is None:
            raise ContractError("invalid_request", "document is required.", 400)
        if not reading.reader_route(document):
            raise ContractError("unsupported_format", f"No model reader is switched on for {document.suffix} files.", 415)
        context = payload.get("context") if isinstance(payload.get("context"), dict) else {}
        subject = str(context.get("subject") or "")[:500]
        attachments = [intake.display_name(a) for a in (context.get("attachments") or []) if isinstance(a, str)][:MAX_DOCUMENTS]
        outcome = reading.read(document, subject=subject, attachments=attachments or [document.file_name], correlation_id=correlation_id)
        if outcome.failure_code:
            raise ContractError(outcome.failure_code, outcome.failure_message[:1000], 502 if outcome.failure_code.startswith("provider") else 422)
        from .app import MAX_LINES

        if len(outcome.items) > MAX_LINES:
            raise ContractError("input_too_large", f"The reader returned more than {MAX_LINES} rows.", 413)
        max_calls = options.get("maxModelCalls") if isinstance(options.get("maxModelCalls"), int) else 0
        with corrections.using({}):
            results, degraded, budget = reading.match(outcome.items, subject=subject, attachments=attachments or [document.file_name], max_calls=max_calls)
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001
        log.exception("v1 read failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The RFQ reader failed on this document.", 500)

    elapsed_ms = int((time.monotonic() - started) * 1000)
    if elapsed_ms > deadline_ms:
        return _refuse(correlation_id, "timeout", "The read took longer than its deadline.", 504)

    pricing_degraded, pricing_notes = _notes(results)
    translator_note = reading.degraded_reason(degraded, correlation_id)
    translator = reading.translator_model()
    models = dict(outcome.models)
    if translator and degraded.get("run", {}).get("ran", reading.reader_normalizer.last_run().get("ran")):
        models["translator"] = translator
    answered_by = reading.input_type(results)
    log.info("v1 read %s: %s by %s, %d lines, %d ms", correlation_id, document.suffix, answered_by, len(results), elapsed_ms)
    return _answer(
        correlation_id,
        {
            "lines": results,
            # A model's rows are its claims, re-checked against the file where the file
            # allows it; which source rows it passed over is not known.
            "coverage": accounting.unmeasured().to_contract(),
            "engine": _engine(),
            "provider": {"mode": "model", "modelCalls": int(budget["used"]), "model": models.get("reader") or models.get("vision_ocr"), "models": models, "answeredBy": answered_by},
            "degraded": bool(pricing_degraded or translator_note),
            "notes": outcome.notes + ([translator_note] if translator_note else []) + pricing_notes,
            "elapsedMs": elapsed_ms,
        },
    )


@v1.post("/v1/readiness")
def readiness():
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        # One JSON string, written by PHP's `PythonJson`: rows keep `{}`, `NaN` and
        # large integers exactly as the store holds them.
        try:
            draft = json.loads(payload.get("draftJson") or "")
        except (TypeError, ValueError):
            raise ContractError("invalid_request", "draftJson must be a JSON object.", 400) from None
        if not isinstance(draft, dict):
            raise ContractError("invalid_request", "draftJson must be a JSON object.", 400)
        rows = draft.get("rows")
        details = draft.get("quote_details")
        if not isinstance(rows, list) or not all(isinstance(r, dict) for r in rows):
            raise ContractError("invalid_request", "rows must be a list of objects.", 400)
        if len(rows) > MAX_READINESS_ROWS:
            raise ContractError("input_too_large", f"More than {MAX_READINESS_ROWS} rows.", 413)
        if not isinstance(details, dict):
            raise ContractError("invalid_request", "quoteDetails must be an object.", 400)
        # `reviewed=True` and no reader state: the source's `draft_is_ready`, as
        # `update_draft` calls it for a save.
        answer = quote_readiness(rows, details, reviewed=True, reader_state=None)
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001
        log.exception("v1 readiness failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The RFQ parser could not assess this draft.", 500)
    return _answer(correlation_id, {"readiness": answer, "engine": _engine()})


@v1.post("/v1/products/search")
def products_search():
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        query = payload.get("q")
        limit = payload.get("limit", 20)
        if not isinstance(query, str) or len(query) > 100:
            raise ContractError("invalid_request", "q must be text of up to 100 characters.", 400)
        if not isinstance(limit, int) or isinstance(limit, bool):
            raise ContractError("invalid_request", "limit must be a whole number.", 400)
        items = search_products(pipeline.catalog.products, query, limit)
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001
        log.exception("v1 product search failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The product search failed.", 500)
    return _answer(correlation_id, {"items": items})


@v1.get("/v1/health")
def health():
    return _answer(_correlation_id(request.headers.get("X-Correlation-Id")), {"status": "ok"})


@v1.get("/v1/ready")
def ready():
    correlation_id = _correlation_id(request.headers.get("X-Correlation-Id"))
    try:
        count = len(pipeline.catalog.products_by_code)
    except Exception:  # noqa: BLE001
        log.exception("catalogue failed to load")
        return _refuse(correlation_id, "unavailable", "The catalogue is not loaded.", 503)
    return _answer(correlation_id, {"status": "ready", "products": count, "engine": _engine()})
