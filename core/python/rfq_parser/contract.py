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
- `POST /v1/ocr` — one screenshot through vision OCR, for Scan Screenshot: the
  transcribed text for the rep to review before it is matched (the source's
  `/api/vision-ocr`). It creates no lines and stores nothing.
- `POST /v1/price` — a draft's rows priced as the source editor does (cost, MOQ,
  prepack, currency, then margin, sell price and total), parity plan E5.
- `POST /v1/export` — the product summary and the cells of both quote CSVs over
  rows already priced (`quote_export`), parity plan E7.1.
- `POST /v1/sync-plan` — the 4js quote plan for a draft's rows (`quote_sync`, the
  source's `prepare_quote_push`): the header, the priced lines, the exclusions, or why
  the quote cannot be sent. Pure: it contacts nothing, parity plan E7.3.
- `POST /v1/aliflex` — a finished quote's rows converted to the ALI-FLEX alternative
  (`aliflex`, the source's mapping policy `aliflex-map/2`). Pure; prices, margins and the
  store are PHP's, parity plan E7.4.
- `POST /v1/products/styles` — the catalogue's style for each product code, for
  the customer default margin lookup.
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
from datetime import date
from pathlib import PurePath
from typing import Any

from flask import Blueprint, jsonify, request

from tricab_cable_rules import ENGINE_VERSION
from tricab_cable_rules.extractor import pair_circuit_label_lines

from . import accounting, aliflex, corrections, intake, pipeline, quote_export, quote_import, quote_pricing, quote_sync, reading, vision_ocr
from .product_search import search_products
from ._source import SOURCE_COMMIT
from .quote_readiness import REGION_PROFILE, SUPPORTED_CURRENCIES, exclusion_reason, quote_readiness

log = logging.getLogger("rfq-parser")

CONTRACT = "rfq-parser.v1"
TOKEN_SETTING = "RFQ_PARSER_INTERNAL_TOKEN"

# `CorrelationId::PATTERN` on the PHP side. Anything else is refused, not echoed.
_CORRELATION_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$")

# `RfqServiceLimits` on the PHP side; PHP refuses first, these are the backstop.
MAX_DEADLINE_MS = 600_000
MIN_DEADLINE_MS = 1_000
MAX_READINESS_ROWS = 2_000
# The correction memory a parse carries; the live table held 1,9xx keys on 28 Sep.
MAX_CORRECTIONS = 50_000
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


def _learned(payload: dict[str, Any]) -> dict[str, dict[str, Any]]:
    """The request's `corrections`: correction memory the API holds, `{key: {product_code, confirmations}}`.

    Keys are :func:`corrections.correction_key` of a line's text, as `/v1/corrections/keys`
    made them; a key two codes tie on is left out by the API, as the source declines it.
    """
    from .app import _corrections_from

    if len(payload.get("corrections") or {}) > MAX_CORRECTIONS:
        raise ContractError("input_too_large", f"More than {MAX_CORRECTIONS} corrections.", 413)
    learned = _corrections_from(payload.get("corrections"))
    if learned is None:
        raise ContractError("invalid_request", "corrections must map a key to {product_code, confirmations}.", 400)
    return learned


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
        quote = quote_import.read_upload_quote(document.data, document.file_name)
        if quote is not None:
            # A TriCab quotation (4js or Dynamics) is read by its own reader and never by
            # the RFQ parser or the model reader, as the source's upload does: the generic
            # parser would read headings, page numbers and doubled bold text as lines.
            read = quote_import.quote_upload_result(quote)
            results = read["results"]
            if len(lines) + len(results) > MAX_LINES:
                raise ContractError("input_too_large", f"The documents have more than {MAX_LINES} rows to read.", 413)
            excluded = sum(1 for row in results if row.get("disposition") == "excluded")
            cov = accounting.Coverage(input_rows=len(results), kept=len(results) - excluded, excluded=excluded)
            summary.update(readerRoute=False, lineCount=len(results), coverage=cov.to_contract(), quoteImport=read["quote_import"])
            notes.append(f"{document.file_name} was read as TriCab quote {read['quote_import']['quote_number'] or '(no number)'}: {len(results)} {'line' if len(results) == 1 else 'lines'}.")
            notes.extend(problem["error"] for problem in read["errors"])
            total.add(cov)
            summaries.append(summary)
            lines.extend(results)
            log.info("v1 parse %s: quote %s, %d lines", correlation_id, read["quote_import"]["template"], len(results))
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
        with corrections.using(_learned(payload)):
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
            with corrections.using(_learned(payload)):
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
        with corrections.using(_learned(payload)):
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


@v1.post("/v1/ocr")
def ocr():
    """One screenshot through vision OCR; answers the text, never lines.

    Request: the envelope, `deadlineMs` and one `document` (as in `/v1/parse`) that is
    a png, jpeg or webp. Answer: `{text, confidence, model, escalated}`, where `text`
    is the table's rows as one line each, for the textarea. A provider failure is
    typed as in `/v1/read` (`provider_rejected` / `provider_unavailable`), with plain
    words and never the provider's body.
    """
    started = time.monotonic()
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        deadline_ms = _deadline(payload)
        (document,) = _documents([payload.get("document")]) if isinstance(payload.get("document"), dict) else (None,)
        if document is None:
            raise ContractError("invalid_request", "document is required.", 400)
        if not document.is_image:
            raise ContractError("unsupported_format", "Scan Screenshot reads png, jpeg and webp images.", 415)
        if not vision_ocr.vision_ocr_enabled():
            raise ContractError("provider_rejected", "Screenshot reading is not switched on.", 502)
        result = vision_ocr.extract_table_with_qwen(document.data, document.content_type or "")
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001
        log.exception("v1 ocr failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The screenshot could not be read.", 500)

    elapsed_ms = int((time.monotonic() - started) * 1000)
    if not result.ok:
        log.warning("v1 ocr %s failed: %s", correlation_id, (result.error or "")[:500])
        if result.error == "No RFQ rows found.":
            return _refuse(correlation_id, "invalid_request", "No RFQ rows were found in that screenshot.", 422)
        code, words = reading.classify(result.error)
        return _refuse(correlation_id, code, f"The screenshot could not be read: {words}.", 502 if code.startswith("provider") else 500)
    if elapsed_ms > deadline_ms:
        return _refuse(correlation_id, "timeout", "Reading the screenshot took longer than its deadline.", 504)
    log.info("v1 ocr %s: %d rows, confidence %.2f, %d ms", correlation_id, len(result.rows), result.confidence, elapsed_ms)
    return _answer(
        correlation_id,
        {
            "text": result.text,
            "rowCount": len(result.rows),
            "confidence": result.confidence,
            "model": result.model,
            "escalated": result.escalated,
            "elapsedMs": elapsed_ms,
        },
    )


MAX_PRICE_ROWS = 600


@v1.post("/v1/price")
def price():
    """The rows priced; the source's `/api/prices` plus the browser's margin pass.

    Request: the envelope, `deadlineMs` and `draftJson`, one JSON string (as in
    `/v1/readiness`, so rows keep `{}`, `NaN` and large integers) of
    `{rows, region, currency, defaultMargins, reprice}`. `defaultMargins` maps a
    product code to the customer's default margin; `reprice` false only re-derives
    margin, sell price and total from the cost the rows already hold.

    Answer: `{rows, unpriced, engine}`, the rows in the order sent.
    """
    started = time.monotonic()
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        deadline_ms = _deadline(payload)
        try:
            draft = json.loads(payload.get("draftJson") or "")
        except (TypeError, ValueError):
            raise ContractError("invalid_request", "draftJson must be a JSON object.", 400) from None
        if not isinstance(draft, dict):
            raise ContractError("invalid_request", "draftJson must be a JSON object.", 400)
        rows = draft.get("rows")
        if not isinstance(rows, list) or not all(isinstance(r, dict) for r in rows):
            raise ContractError("invalid_request", "rows must be a list of objects.", 400)
        if len(rows) > MAX_PRICE_ROWS:
            raise ContractError("input_too_large", f"More than {MAX_PRICE_ROWS} rows to price.", 413)
        region = draft.get("region")
        currency = draft.get("currency")
        if region not in REGION_PROFILE:
            raise ContractError("invalid_request", "region is not one the Cost API prices.", 400)
        if currency not in SUPPORTED_CURRENCIES:
            raise ContractError("invalid_request", "currency is not one of AUD, NZD, USD, SGD.", 400)
        margins = draft.get("defaultMargins")
        if margins is None:
            margins = {}
        if not isinstance(margins, dict) or not all(
            v == quote_pricing.COST_RULE or (isinstance(v, (int, float)) and not isinstance(v, bool)) for v in margins.values()
        ):
            raise ContractError("invalid_request", 'defaultMargins must map a product code to a margin, or to "cost".', 400)
        reprice = draft.get("reprice")
        if not isinstance(reprice, bool):
            raise ContractError("invalid_request", "reprice must be true or false.", 400)
        priced = quote_pricing.price_rows(
            rows,
            region=region,
            currency=currency,
            default_margins={str(code).upper(): value for code, value in margins.items()},
            reprice=reprice,
        )
        unpriced = quote_pricing.unpriced_count(priced)
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001
        log.exception("v1 price failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The RFQ parser could not price these rows.", 500)
    elapsed_ms = int((time.monotonic() - started) * 1000)
    if elapsed_ms > deadline_ms:
        return _refuse(correlation_id, "timeout", "Pricing took longer than its deadline.", 504)
    log.info("v1 price %s: %d rows, reprice=%s, %d unpriced, %d ms", correlation_id, len(priced), reprice, unpriced, elapsed_ms)
    return _answer(correlation_id, {"rows": priced, "unpriced": unpriced, "engine": _engine(), "elapsedMs": elapsed_ms})


@v1.post("/v1/export")
def export():
    """The product summary and the CSV cells, over rows as the price route left them.

    Request: the envelope, `deadlineMs` and `draftJson`, one JSON string of
    `{rows, includePrices}`. `includePrices` false drops the two money columns.

    Answer: `{summary, quoteLines, summaryLines, engine}`. `summary` is the source's
    `build_product_summary` (`line_indexes` index into `rows`); the two tables are
    `{columns, rows}` of text cells. The CSV text, and its escaping, are PHP's.
    """
    started = time.monotonic()
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        deadline_ms = _deadline(payload)
        try:
            draft = json.loads(payload.get("draftJson") or "")
        except (TypeError, ValueError):
            raise ContractError("invalid_request", "draftJson must be a JSON object.", 400) from None
        if not isinstance(draft, dict):
            raise ContractError("invalid_request", "draftJson must be a JSON object.", 400)
        rows = draft.get("rows")
        if not isinstance(rows, list) or not all(isinstance(r, dict) for r in rows):
            raise ContractError("invalid_request", "rows must be a list of objects.", 400)
        if len(rows) > MAX_PRICE_ROWS:
            raise ContractError("input_too_large", f"More than {MAX_PRICE_ROWS} rows to export.", 413)
        include_prices = draft.get("includePrices")
        if not isinstance(include_prices, bool):
            raise ContractError("invalid_request", "includePrices must be true or false.", 400)
        built = quote_export.build_export(rows, include_prices=include_prices)
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001
        log.exception("v1 export failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The RFQ parser could not build the export.", 500)
    elapsed_ms = int((time.monotonic() - started) * 1000)
    if elapsed_ms > deadline_ms:
        return _refuse(correlation_id, "timeout", "The export took longer than its deadline.", 504)
    return _answer(correlation_id, {**built, "engine": _engine(), "elapsedMs": elapsed_ms})


@v1.post("/v1/sync-plan")
def sync_plan():
    """The plan a sync would freeze, or why none can be made. Sends nothing.

    Request: the envelope, `deadlineMs` and `draftJson`, one JSON string of
    `{rows, quote_details, margins, today, existing_refno, version, origin}`:
    the rows and header as stored, the customer's default margin per product code,
    today's date (`YYYY-MM-DD`, the caller's clock and zone), and for a new version
    of a quote already in 4js its number and the version to raise.

    Answer: `{plan}` or `{problem: {status, payload}}`. A problem is an answer, not a
    failure: 400 for a header or lines that are not ready, 409 when a reviewed price
    differs from what the customer's rule requires. The source's `blocking_issues`
    ride in `payload`.
    """
    started = time.monotonic()
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        deadline_ms = _deadline(payload)
        try:
            draft = json.loads(payload.get("draftJson") or "")
        except (TypeError, ValueError):
            raise ContractError("invalid_request", "draftJson must be a JSON object.", 400) from None
        if not isinstance(draft, dict):
            raise ContractError("invalid_request", "draftJson must be a JSON object.", 400)
        rows = draft.get("rows")
        details = draft.get("quote_details")
        margins = draft.get("margins")
        if not isinstance(rows, list) or not all(isinstance(r, dict) for r in rows):
            raise ContractError("invalid_request", "rows must be a list of objects.", 400)
        if len(rows) > MAX_READINESS_ROWS:
            raise ContractError("input_too_large", f"More than {MAX_READINESS_ROWS} rows.", 413)
        if not isinstance(details, dict):
            raise ContractError("invalid_request", "quote_details must be an object.", 400)
        if not isinstance(margins, dict):
            raise ContractError("invalid_request", "margins must map a product code to a margin.", 400)
        try:
            today = date.fromisoformat(str(draft.get("today")))
        except ValueError:
            raise ContractError("invalid_request", "today must be a date (YYYY-MM-DD).", 400) from None
        existing = draft.get("existing_refno")
        version = draft.get("version")
        if existing is not None and not isinstance(existing, str):
            raise ContractError("invalid_request", "existing_refno must be text or null.", 400)
        if version is not None and (not isinstance(version, int) or isinstance(version, bool) or version < 2):
            raise ContractError("invalid_request", "version must be 2 or more, or null.", 400)
        if (existing or None) is None and version is not None:
            raise ContractError("invalid_request", "version needs existing_refno.", 400)
        origin = draft.get("origin", "")
        if origin not in ("", quote_sync.QUOTE_ORIGIN_PORTAL, quote_sync.QUOTE_ORIGIN_EMAIL):
            raise ContractError("invalid_request", "origin must be portal, email or blank.", 400)
        plan, problem = quote_sync.prepare_quote_push(
            rows,
            details,
            margins={str(code).upper(): value for code, value in margins.items()},
            today=today,
            existing_refno=existing or None,
            version=version,
            origin=origin,
            reviewed=True,
        )
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001
        log.exception("v1 sync-plan failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The RFQ parser could not plan this sync.", 500)
    elapsed_ms = int((time.monotonic() - started) * 1000)
    if elapsed_ms > deadline_ms:
        return _refuse(correlation_id, "timeout", "Planning took longer than its deadline.", 504)
    body: dict[str, Any] = {"engine": _engine(), "elapsedMs": elapsed_ms}
    if plan is not None:
        body["plan"] = plan
    else:
        assert problem is not None
        body["problem"] = {"payload": problem[0], "status": problem[1]}
    return _answer(correlation_id, body)


@v1.post("/v1/aliflex")
def aliflex_convert():
    """A finished quote's rows as the ALI-FLEX alternative: the agreed copper-to-aluminium
    proposal per line, the header with the customer-facing note, the label and the counts.

    Request: the envelope, `deadlineMs` and `draftJson`, one JSON string of
    `{rows, quote_details, label}`. Answer: `{conversion}` with `rows` (one per source row,
    in order, nothing dropped), `quote_details`, `label`, `counts`
    (`changed`/`unchanged`/`review`), `message` and `policy_version`. Prices are not asked
    for: a changed row has its source prices cleared for the caller to price afresh.
    """
    started = time.monotonic()
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        deadline_ms = _deadline(payload)
        try:
            draft = json.loads(payload.get("draftJson") or "")
        except (TypeError, ValueError):
            raise ContractError("invalid_request", "draftJson must be a JSON object.", 400) from None
        if not isinstance(draft, dict):
            raise ContractError("invalid_request", "draftJson must be a JSON object.", 400)
        rows = draft.get("rows")
        details = draft.get("quote_details")
        label = draft.get("label", "")
        if not isinstance(rows, list) or not all(isinstance(r, dict) for r in rows):
            raise ContractError("invalid_request", "rows must be a list of objects.", 400)
        if len(rows) > MAX_READINESS_ROWS:
            raise ContractError("input_too_large", f"More than {MAX_READINESS_ROWS} rows.", 413)
        if not isinstance(details, dict) or not isinstance(label, str):
            raise ContractError("invalid_request", "quote_details must be an object and label text.", 400)
        conversion = aliflex.convert(rows, details, label)
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001
        log.exception("v1 aliflex failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The RFQ parser could not convert this quote.", 500)
    elapsed_ms = int((time.monotonic() - started) * 1000)
    if elapsed_ms > deadline_ms:
        return _refuse(correlation_id, "timeout", "The conversion took longer than its deadline.", 504)
    return _answer(correlation_id, {"conversion": conversion, "engine": _engine(), "elapsedMs": elapsed_ms})


@v1.post("/v1/products/styles")
def products_styles():
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        codes = payload.get("codes")
        if not isinstance(codes, list) or len(codes) > MAX_READINESS_ROWS or not all(isinstance(c, str) and len(c) <= 100 for c in codes):
            raise ContractError("invalid_request", "codes must be a list of product codes.", 400)
        products = pipeline.catalog.products_by_code
        styles = {}
        for code in codes:
            product = products.get(code.strip().upper())
            if product is not None and getattr(product, "style", ""):
                styles[code.strip().upper()] = str(product.style).upper()
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001
        log.exception("v1 styles failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The product styles could not be read.", 500)
    return _answer(correlation_id, {"styles": styles})


@v1.post("/v1/corrections/keys")
def correction_keys():
    """What Save Review may learn (the source's `learn_approved_corrections`).

    `lines`: `[{text, productCode}]`, the approved lines. Answer: `entries`, one per
    line that can be learned, in order: `{index, key, productCode}`. A line is
    skipped when its text has no key or its code is not in the catalogue, as the
    source skips it. The API owns the table and writes these; nothing is stored here.
    """
    correlation_id = None
    try:
        payload, correlation_id = _envelope(request.get_json(silent=True))
        lines = payload.get("lines")
        if not isinstance(lines, list) or len(lines) > MAX_READINESS_ROWS or not all(
            isinstance(line, dict) and isinstance(line.get("text"), str) and isinstance(line.get("productCode"), str) for line in lines
        ):
            raise ContractError("invalid_request", "lines must be a list of {text, productCode}.", 400)
        products = pipeline.catalog.products_by_code
        entries = []
        for index, line in enumerate(lines):
            code = line["productCode"].strip().upper()
            key = corrections.correction_key(line["text"])
            if key and code in products:
                entries.append({"index": index, "key": key, "productCode": code})
    except ContractError as exc:
        return _refuse(correlation_id, exc.code, exc.message, exc.status)
    except Exception:  # noqa: BLE001
        log.exception("v1 correction keys failed (correlation %s)", correlation_id)
        return _refuse(correlation_id, "internal_error", "The correction keys could not be made.", 500)
    return _answer(correlation_id, {"entries": entries})


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
