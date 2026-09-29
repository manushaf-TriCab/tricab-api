# Generated from the RFQ parser's matcher/reader_normalizer.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""The translator: the reader's model doing the normalizer's job.

`normalizer.py` (gemini, via OpenRouter) and `agent.py` (Claude, via Foundry) are two
implementations of one interface -- extracted row text in, resolver-shaped field
overrides out, `dict[int, NormalizedRow]` either way. This is the third, on the Azure
OpenAI Responses API that `attachment_reader` already uses, so the model that reads the
customer's spreadsheet can also translate the rows that come off it.

**The prompt, the schema and the parser are the normalizer's, byte for byte.**
`_SYSTEM_PROMPT`, `_request_schema()`, `batch_rows()` and `_row_from_payload()` are
imported rather than re-stated. That is the rule READER_STABILISATION_RESULTS §3/§5.1
arrived at the hard way: when two paths own two copies of the notation, a measurement
that reads as "model A against model B" is really "prompt A against prompt B". Here the
only differences from the normalizer are the model and the wire, so a comparison across
the two means what it appears to mean. A change to the notation still has one owner.

What this does NOT inherit is the reader's guarantee. `attachment_reader` grounds every
quantity in a cell or a quoted span and `attachment_validate` re-checks it against the
file. There is nothing to re-open here: the input is row text the parser already
extracted, and the output is fields, not quantities read out of a document. This path is
exactly as trustworthy as the normalizer it replaces -- no more -- and it is on its own
flag for that reason.

Failure is never silent. Every path returns `{}`, which drops those rows to the regex
trial result, and :func:`last_run` records why so `match_items` can stamp the draft
degraded. The failure this is written against is on the record: a dead
`OPENROUTER_API_KEY` once dropped the parser to regex-only with `/api/health` still
green, and it amplified ~30x before anyone noticed.
"""

from __future__ import annotations

import json
import os
import time
from typing import Any

from rfq_parser.attachment_reader import (
    ReaderError,
    _collect,
    _flag,
    _float_env,
    _int_env,
    _post,
    reader_api_version,
    reader_enabled,
    reader_endpoint,
    reader_model,
)
from rfq_parser.normalizer import (
    NormalizedRow,
    _request_schema,
    _row_from_payload,
    _SYSTEM_PROMPT,
    batch_rows,
)

# Rows per call. The normalizer sends 40-80 because a flash model answers a whole sheet in
# one pass; the agent sends 12 because one shot at an oversized batch runs the output
# budget out mid-submission -- measured, on a 62-row sheet that spent four turns and $0.47
# and returned nothing. Terra is a reasoning model like the agent's, so this starts at the
# agent's end of that range rather than the normalizer's. It is an analogy, not a
# measurement, and the measurement pass is what settles it.
_DEFAULT_MAX_ITEMS = 20

# Reasoning tokens are output tokens. The agent's 32K was established on the same shape of
# task with a similar chunk size; a truncated structured response is unusable rather than
# partially usable, so this is sized to not be hit and `_run_chunk` halves the batch when
# it is anyway.
_DEFAULT_MAX_OUTPUT_TOKENS = 32_000

# Per call, and every caller is the email path. Two ceilings sit above it: `rfq_api.php`
# allows `poll-mail` 580s, and the WebJob runs `rfq_cli.py poll-mail` directly with none.
# A message that chunks into four calls must still fit the smaller of those, which is why
# this is well under it rather than at the reader's 600.
_DEFAULT_TIMEOUT = 180.0

# Whole-message stop, counted across chunks. Unlike the reader's, which is per file read,
# this bounds one email: a 200-row sheet is ten calls, and ten runaways is the thing worth
# stopping. Zero disables the check.
_DEFAULT_MAX_TOKENS = 400_000

_last_run: dict[str, Any] = {"ran": False}


# --- configuration -----------------------------------------------------------------

def translator_enabled() -> bool:
    """On only when the reader is configured AND this flag is set.

    Separate from `RFQ_READER_ENABLED` for the same reason `RFQ_READER_TEXT_ENABLED` is:
    switching on the path that reads spreadsheets must not silently switch on a second,
    differently-evidenced use of the same credentials. Reading a workbook and translating
    a row are different jobs with different failure modes, and the spreadsheet path's 69
    live runs are not evidence about this one.
    """
    return reader_enabled() and _flag("RFQ_READER_NORMALIZER_ENABLED")


def translator_model() -> str:
    """The deployment that translates, which need not be the one that reads.

    Its own variable so a cheaper deployment can be measured on translation without
    touching the reader, whose numbers were taken on the model named there.
    """
    return os.environ.get("RFQ_READER_NORMALIZER_MODEL", "").strip() or reader_model()


def translator_max_items() -> int:
    return max(1, _int_env("RFQ_READER_NORMALIZER_MAX_ITEMS", _DEFAULT_MAX_ITEMS))


def translator_effort() -> str:
    """Reasoning effort, or "" for the service default.

    Unset by default on purpose: the request shape without a `reasoning` block is the one
    live-confirmed against `tricab-ai2` by the text reader. Adding a field no deployment
    has been asked for turns a working call into a 400 on the first real message, and the
    poller is unattended.
    """
    value = os.environ.get("RFQ_READER_NORMALIZER_EFFORT", "").strip().lower()
    return value if value in {"minimal", "low", "medium", "high"} else ""


def translator_config() -> dict[str, Any]:
    """Health-endpoint view. Mirrors `agent.agent_config()`."""
    return {
        "enabled": translator_enabled(),
        "configured": _flag("RFQ_READER_NORMALIZER_ENABLED"),
        # The gate `match_items` applies is "do I have an email to read?" -- the same one
        # that keeps the agent off the PHP request path. Health has no message in hand, so
        # a green "enabled" here would otherwise read as "translating everywhere".
        "requires_message_context": True,
        "model": translator_model() or None,
        "max_items": translator_max_items(),
        "effort": translator_effort() or None,
        "max_output_tokens": _int_env(
            "RFQ_READER_NORMALIZER_MAX_OUTPUT_TOKENS", _DEFAULT_MAX_OUTPUT_TOKENS
        ),
        "max_total_tokens": _int_env("RFQ_READER_NORMALIZER_MAX_TOKENS", _DEFAULT_MAX_TOKENS),
        "timeout_seconds": _float_env("RFQ_READER_NORMALIZER_TIMEOUT_SECONDS", _DEFAULT_TIMEOUT),
        "last_run": last_run(),
    }


# --- what the last run did ---------------------------------------------------------

def last_run() -> dict[str, Any]:
    """What the most recent translation did, for the poll summary and draft stamping."""
    return dict(_last_run)


def reset_run() -> None:
    """Clear the last-run record. Call before each message in a multi-message run.

    `_last_run` is a module global and `match_items` legitimately does not call the
    translator at all when the trial-match pre-filter resolves every row. Without a reset
    that message inherits the previous one's counts.
    """
    global _last_run
    _last_run = {"ran": False}


def degraded_reason() -> str | None:
    """Why the last run produced a quote worse than it should have, or None.

    Degradation is not only total failure. A message where two of three chunks died still
    had two thirds of its hard rows answered by regex, and the draft that results looks
    exactly like a clean one. Mirrors `agent.degraded_reason()` deliberately: the caller
    treats the two identically and must not have to know which model ran.
    """
    run = _last_run
    if not run.get("ran"):
        return None
    if run.get("reason") == "disabled":
        return None
    failed = int(run.get("chunks_failed") or 0)
    if not run.get("ok"):
        return run.get("reason") or "the translator returned no rows"
    if failed:
        total = int(run.get("chunks") or 0)
        return (
            f"{failed} of {total} row group(s) fell back to the regex parser: "
            f"{run.get('reason') or 'no reason recorded'}"
        )
    return None


# --- the request -------------------------------------------------------------------

def _build_payload(batch: list[tuple[int, dict]], model: str) -> dict[str, Any]:
    """The normalizer's call, in Responses shape.

    `instructions` is the Responses equivalent of the normalizer's system message, and the
    user content is its user message character for character -- the same JSON envelope,
    the same `ensure_ascii`. Nothing here reformats the rows.
    """
    payload: dict[str, Any] = {
        "model": model,
        "instructions": _SYSTEM_PROMPT,
        "text": {
            "format": {
                "type": "json_schema",
                "name": "rfq_normalize",
                "strict": True,
                "schema": _request_schema(),
            }
        },
        "input": [
            {
                "role": "user",
                "content": [
                    {
                        "type": "input_text",
                        "text": json.dumps({"rows": batch_rows(batch)}, ensure_ascii=True),
                    }
                ],
            }
        ],
        "max_output_tokens": _int_env(
            "RFQ_READER_NORMALIZER_MAX_OUTPUT_TOKENS", _DEFAULT_MAX_OUTPUT_TOKENS
        ),
    }
    effort = translator_effort()
    if effort:
        payload["reasoning"] = {"effort": effort}
    return payload


class _Unusable(ReaderError):
    """A response that arrived and could not be used: truncated, or not the declared shape.

    Distinguished from a transport failure because only this kind is worth retrying on a
    smaller batch. A 401 or a timeout fails identically on both halves and costs twice.
    """


def _run_chunk(
    batch: list[tuple[int, dict]], *, model: str, timeout: float, budget: dict | None
) -> tuple[dict[int, NormalizedRow], int]:
    """One call for `batch`. Returns (rows, total tokens). Raises on any failure.

    On a truncated or malformed response the batch is halved and each half retried, as
    `normalizer._run_batch` does and for the reason recorded there: a fixed chunk size
    that works for most files still overflows on one with longer descriptions, and
    dropping the whole batch means a sheet comes back with nothing translated at all.
    Unlike the normalizer, a transport failure is NOT split -- see `_Unusable`.
    """
    if budget is not None:
        if budget["used"] >= budget["limit"]:
            raise ReaderError("llm budget exhausted")
        budget["used"] += 1

    tokens = 0
    try:
        response = _post(
            f"/openai/v1/responses?api-version={reader_api_version()}",
            _build_payload(batch, model),
            timeout,
        )
        usage = response.get("usage") or {}
        tokens = int(usage.get("total_tokens") or 0)

        error = response.get("error")
        if error:
            raise ReaderError(f"{error.get('code')}: {error.get('message')}")
        status = str(response.get("status") or "")
        if status != "completed":
            detail = response.get("incomplete_details") or {}
            raise _Unusable(f"response status {status!r} ({detail or 'no detail'})")

        out, _code = _collect(response)
        if not out.strip():
            # The reader's §7 fault: a model that answers somewhere other than the
            # response leaves an empty list that looks exactly like "no cables here".
            raise _Unusable("model returned no structured output")
        try:
            parsed = json.loads(out)
        except json.JSONDecodeError as exc:
            raise _Unusable(f"structured output was not JSON: {exc}") from exc
        if not isinstance(parsed.get("rows"), list):
            raise _Unusable("structured output carried no `rows` array")
    except _Unusable:
        if len(batch) < 2:
            raise
        mid = len(batch) // 2
        rows, spent = _run_chunk(batch[:mid], model=model, timeout=timeout, budget=budget)
        more, spent_more = _run_chunk(batch[mid:], model=model, timeout=timeout, budget=budget)
        rows.update(more)
        return rows, tokens + spent + spent_more

    response_model = str(response.get("model") or model)
    valid = {index for index, _ in batch}
    normalized: dict[int, NormalizedRow] = {}
    for row in parsed.get("rows") or []:
        if not isinstance(row, dict):
            continue
        try:
            index = int(row.get("index"))
        except (TypeError, ValueError):
            continue
        if index not in valid:
            # An index the model invented cannot be mapped onto a row, and guessing which
            # one was meant would attach a translation to the wrong line.
            continue
        parsed_row = _row_from_payload(row, response_model)
        if parsed_row is not None:
            normalized[index] = parsed_row
    return normalized, tokens


# --- the entry point ---------------------------------------------------------------

def normalize_items(
    items: list[dict], *, budget: dict | None = None, skip_indexes: set[int] | None = None
) -> dict[int, NormalizedRow]:
    """Translator equivalent of `normalizer.normalize_items` -- same signature, same return.

    Rows absent from the result fall back to the regex path, exactly as they do when the
    normalizer drops one. A failed chunk is absent rather than fatal: a partially
    translated sheet still beats an entirely regex one, and `last_run()` records every
    failure so the draft can be stamped degraded. This never raises into the pipeline.
    """
    global _last_run
    started = time.monotonic()
    _last_run = {
        "ran": True, "ok": False, "reason": "", "rows": 0,
        "chunks": 0, "chunks_failed": 0, "tokens": 0, "model": translator_model(),
    }

    if not translator_enabled():
        _last_run.update(reason="disabled")
        return {}

    skip_indexes = skip_indexes or set()
    indexed = [
        (index, item)
        for index, item in enumerate(items)
        if index not in skip_indexes and str(item.get("text") or "").strip()
    ]
    if not indexed:
        _last_run.update(ok=True, reason="no rows to normalize")
        return {}

    if budget is not None and budget["used"] >= budget["limit"]:
        _last_run.update(reason="llm budget exhausted before start")
        return {}

    model = translator_model()
    timeout = _float_env("RFQ_READER_NORMALIZER_TIMEOUT_SECONDS", _DEFAULT_TIMEOUT)
    size = translator_max_items()
    ceiling = _int_env("RFQ_READER_NORMALIZER_MAX_TOKENS", _DEFAULT_MAX_TOKENS)
    chunks = [indexed[start : start + size] for start in range(0, len(indexed), size)]

    normalized: dict[int, NormalizedRow] = {}
    failures: list[str] = []
    tokens = 0
    _last_run["chunks"] = len(chunks)

    for chunk in chunks:
        if ceiling and tokens > ceiling:
            # Counted after the fact rather than predicted: the ceiling stops the next
            # call, it cannot un-spend the one that crossed it. Stated as a failure so the
            # rows it did not translate show up as degraded rather than as regex rows
            # nobody was told about.
            failures.append(f"token ceiling {ceiling} exceeded ({tokens}); stopped early")
            break
        try:
            rows, spent = _run_chunk(chunk, model=model, timeout=timeout, budget=budget)
        except ReaderError as exc:
            failures.append(str(exc))
            if "budget exhausted" in str(exc):
                # A budget is a whole-message stop; one bad chunk is not.
                break
            continue
        except Exception as exc:  # noqa: BLE001 - the pipeline must always get a dict back
            failures.append(f"{type(exc).__name__}: {exc}")
            continue
        normalized.update(rows)
        tokens += spent

    _last_run.update(
        ok=bool(normalized),
        rows=len(normalized),
        chunks_failed=len(failures),
        tokens=tokens,
        seconds=round(time.monotonic() - started, 2),
        reason="; ".join(failures[:3]),
    )
    return normalized
