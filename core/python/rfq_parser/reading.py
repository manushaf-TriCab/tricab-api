"""A reader job's read: one document through a model reader, then matched (plan 3.5).

The source re-reads a portal upload in a WebJob (`reader_jobs.run_reader_jobs`): the
file goes through `mail_ingest.read_one_file` — terra for spreadsheets, PDFs (a
scanned one from its page image), documents and images — and the rows are matched
with a message context, which is what lets the terra translator run. The platform's
reader jobs belong to PHP (architecture doc §6.4: the parser is a function and holds
no queue state), so this module is that read with the job taken away: PHP claims the
job, sends the bytes, and applies or proposes what comes back.

What it adds to the source, all at the boundary:

- **A read happens only when a model will answer.** `reader_route` is the source's
  `reader_route_enabled`, plus vision OCR for images (the source's mail path falls
  back to it; its portal path had a separate synchronous scan action instead). With
  no reader on, `read_one_file` would quietly run the deterministic parse and call it
  a read, so the request is refused and PHP stands the job down.
- **Failures are typed.** A read that produced nothing says why in a
  `RfqParserFailure` code: credentials or a refused request are
  `provider_rejected` (an operator fixes it, no retry), quota, throttling, 5xx and
  an unreachable service are `provider_unavailable` (retry later).
- **No provider body leaves the parser.** The reader's errors quote up to 600
  characters of the service's answer. Notes are rewritten to say what failed in
  plain words; the full text goes to the log under the correlation id only.
- **Caps come from PHP.** Model calls for the translator are PHP's
  `maxModelCalls`; the reader's own token ceiling and timeout are its settings
  (`RFQ_READER_MAX_TOKENS`, `RFQ_READER_TIMEOUT_SECONDS`), inside PHP's deadline.
"""

from __future__ import annotations

from . import model_settings

import logging
import os
import re
from dataclasses import dataclass, field
from typing import Any

from . import attachment_reader, pipeline, reader_normalizer, vision_ocr
from .intake import Document
from .reader_intake import _default_vision_ocr, read_one_file

log = logging.getLogger("rfq-parser")

# (pattern over a reader error, failure code, what a person is told)
_FAILURES: tuple[tuple[re.Pattern[str], str, str], ...] = (
    (re.compile(r"HTTP (401|403)\b"), "provider_rejected", "the model service refused the reader's credentials"),
    (re.compile(r"HTTP 429\b|rate.?limit|quota", re.I), "provider_unavailable", "the model service is throttling requests"),
    (re.compile(r"HTTP (5\d\d|408|409)\b"), "provider_unavailable", "the model service had an error"),
    (re.compile(r"HTTP (400|404|413|415|422)\b"), "provider_rejected", "the model service refused the request"),
    (re.compile(r"request failed|URLError|TimeoutError|timed out|Connection", re.I), "provider_unavailable", "the model service could not be reached"),
    (re.compile(r"token ceiling", re.I), "provider_rejected", "the read needed more than its token allowance"),
    (re.compile(r"not configured", re.I), "provider_rejected", "the reader is not configured"),
    (re.compile(r"structured output|no structured|response status|not JSON|truncated|malformed|Could not parse", re.I), "provider_unavailable", "the model's answer could not be used"),
)

# The note shapes `read_one_file` writes around a reader error. The error is the tail.
_NOTE_WITH_ERROR = (
    re.compile(r"^(?P<head>(?:Image|Attachment) '.*' could not be read(?: by the reader)?)(?P<sep>: | \()(?P<error>.*?)(?P<tail>\); vision OCR was used instead\.)?$", re.S),
)


@dataclass
class ReadOutcome:
    items: list[dict[str, Any]]
    notes: list[str]
    failure_code: str | None = None  # set only when nothing was read
    failure_message: str = ""
    models: dict[str, str] = field(default_factory=dict)


def reader_route(document: Document) -> bool:
    """Whether a model will read this document now (source `reader_route_enabled`)."""
    suffix = document.suffix
    if suffix in attachment_reader.READER_SUFFIXES:
        return attachment_reader.reader_enabled()
    if suffix in attachment_reader.PDF_SUFFIXES:
        return attachment_reader.pdf_reader_enabled()
    if suffix in attachment_reader.TEXT_SUFFIXES:
        return attachment_reader.document_reader_enabled()
    if suffix in attachment_reader.IMAGE_SUFFIXES:
        return attachment_reader.image_reader_enabled() or vision_ocr.vision_ocr_enabled()
    return False


def classify(error: str) -> tuple[str, str]:
    """`(failure code, plain words)` for a reader or vision error string."""
    for pattern, code, words in _FAILURES:
        if pattern.search(error or ""):
            return code, words
    return "internal_error", "the reader failed"


def safe_note(note: str) -> tuple[str, str | None]:
    """The note with any reader error replaced by plain words, and that error's code."""
    for pattern in _NOTE_WITH_ERROR:
        match = pattern.match(note)
        if match:
            code, words = classify(match.group("error"))
            if match.group("tail"):
                return f"{match.group('head')} ({words}); vision OCR was used instead.", code
            return f"{match.group('head')}: {words}.", code
    return note, None


def read(document: Document, *, subject: str, attachments: list[str], correlation_id: str) -> ReadOutcome:
    """One document through `read_one_file`, as the source's reader worker reads it."""
    models: dict[str, str] = {}
    if attachment_reader.reader_model():
        models["reader"] = attachment_reader.reader_model()
    vision = _default_vision_ocr() if document.is_image else None
    if vision is not None:
        models["vision_ocr"] = model_settings.get("VISION_OCR_PRIMARY_MODEL", vision_ocr.DEFAULT_PRIMARY_MODEL)

    items, raw_notes = read_one_file(document.file_name, document.data, document.content_type, vision_ocr=vision)
    notes: list[str] = []
    codes: list[str] = []
    for note in raw_notes:
        safe, code = safe_note(note)
        if code:
            codes.append(code)
            log.warning("reader note (correlation %s): %s", correlation_id, note[:1000])
        notes.append(safe)

    outcome = ReadOutcome(items=items, notes=notes, models=models)
    if not items:
        # The source's `_fail`: nothing to apply. Why, in the contract's terms.
        outcome.failure_code = _worst(codes) or "internal_error"
        outcome.failure_message = "; ".join(notes) or "The reader returned no lines."
    return outcome


def _worst(codes: list[str]) -> str | None:
    """A credential problem outranks a transient one: retrying cannot fix it."""
    for code in ("provider_rejected", "provider_unavailable", "internal_error"):
        if code in codes:
            return code
    return None


def match(items: list[dict[str, Any]], *, subject: str, attachments: list[str], max_calls: int) -> tuple[list[dict[str, Any]], dict[str, Any], dict[str, Any]]:
    """`(results, degraded, budget)`: the source worker's `match_items` with a message
    context, so the terra translator runs when it is on (`pipeline.match_items`)."""
    context = {"subject": subject, "attachments": list(attachments), "residue": []}
    budget = pipeline.new_llm_budget(line_count=len(items))
    budget["limit"] = min(budget["limit"], max(0, max_calls))
    degraded: dict[str, Any] = {}
    results = pipeline.match_items(items, budget=budget, message_context=context, degraded=degraded)
    return results, degraded, budget


def translator_model() -> str | None:
    return reader_normalizer.translator_model() if reader_normalizer.translator_enabled() else None


def input_type(results: list[dict[str, Any]]) -> str:
    """Which reader answered, off the rows (source `reader_jobs._reader_name`)."""
    types = {str(((r.get("source") or {}) if isinstance(r.get("source"), dict) else {}).get("input_type") or "") for r in results}
    if types & set(pipeline.READER_INPUT_TYPES):
        return "reader"
    if "vision_ocr" in types:
        return "vision_ocr"
    return "deterministic"


def degraded_reason(degraded: dict[str, Any], correlation_id: str) -> str | None:
    """The translator's degraded reason in plain words (it can quote a provider body)."""
    reason = str(degraded.get("reason") or "")
    if not degraded.get("degraded"):
        return None
    log.warning("translator degraded (correlation %s): %s", correlation_id, reason[:1000])
    _code, words = classify(reason)
    return f"The row translator did not run for every line: {words}. Those lines were read without it."
