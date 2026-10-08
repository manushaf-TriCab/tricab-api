"""`finance-ai.v1`: the boundary the PHP API calls (core/src/Finance/Shared/Ai).

Modelled on `rfq-parser.v1`:

- `GET /v1/health`: the contract and the purposes registered.
- `POST /v1/complete/<purpose>`: the purpose is looked up in the registry, which owns the
  prompt, the response schema, the model and the timeout; the caller sends only `data`.
  An unknown purpose is a 404.

Every `/v1` route needs `Authorization: Bearer <FINANCE_AI_INTERNAL_TOKEN>` and refuses
everything when the token is not configured: loopback keeps other hosts off, the token
keeps other processes on the instance off. Neither the token, nor a request body, nor a
prompt, nor a model answer is ever logged.

Every answer carries `contract` and the caller's `correlationId`. Errors are
`{contract, correlationId, error: {code, message}}` with a code from `CODES`, mirrored by
`FinanceAiFailure::CODES` on the PHP side (a test compares the two). The message is safe to
show a person: no data, no prompt, no provider text.
"""

from __future__ import annotations

import hmac
import logging
import os
import re
from typing import Any

from flask import Blueprint, jsonify, request

from . import provider, registry

log = logging.getLogger("finance-ai")

CONTRACT = "finance-ai.v1"
TOKEN_SETTING = "FINANCE_AI_INTERNAL_TOKEN"

# `FinanceAiContract::CORRELATION_PATTERN` on the PHP side. Anything else is refused, not echoed.
_CORRELATION_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{7,63}$")

MIN_DEADLINE_MS = 1_000
# App Service ends a web request at about 230 s; a deadline plus the caller's grace must stay under it.
MAX_DEADLINE_MS = 200_000

# A file a purpose reads (T5.8: a remittance advice): {"name", "content_type", "data"} inside `data`, base64, one file
# per call, at most this many bytes decoded. `FinanceAiContract::MAX_FILE_BYTES` and `FILE_TYPES` are the same values
# (a test compares them); the checks themselves are `registry.file_part`.
MAX_FILE_BYTES = registry.MAX_FILE_BYTES
FILE_TYPES = registry.FILE_TYPES

CODES = (
    "unavailable",
    "timeout",
    "invalid_request",
    "unknown_purpose",
    "contract_mismatch",
    "provider_unavailable",
    "provider_rejected",
    "bad_model_answer",
    "internal_error",
)

v1 = Blueprint("finance_ai_v1", __name__)


def _correlation_id(value: Any) -> str | None:
    return value if isinstance(value, str) and _CORRELATION_ID.match(value) else None


def _answer(correlation_id: str | None, body: dict[str, Any], status: int = 200):
    return jsonify({"contract": CONTRACT, "correlationId": correlation_id, **body}), status


def _refuse(correlation_id: str | None, code: str, message: str, status: int):
    assert code in CODES, code
    return _answer(correlation_id, {"error": {"code": code, "message": message}}, status)


@v1.before_request
def _authenticate():
    expected = os.environ.get(TOKEN_SETTING) or ""
    correlation_id = _correlation_id(request.headers.get("X-Correlation-Id"))
    if not expected:
        # Fail closed: an unset token must not mean "anyone on the instance".
        return _refuse(correlation_id, "unavailable", "The finance AI service has no internal token configured.", 503)
    header = request.headers.get("Authorization") or ""
    supplied = header[7:] if header.startswith("Bearer ") else ""
    if not hmac.compare_digest(supplied.encode("utf-8"), expected.encode("utf-8")):
        return _refuse(correlation_id, "invalid_request", "The caller is not the platform API.", 401)
    return None


# A request is a purpose data, not a document (app.py caps it at 2 MB). A purpose that reads a file is the exception: the
# file arrives base64 inside `data`, so its request may be the file ceiling in base64 plus a margin for the rest.
# Flask from 3.1 lets a request lower or raise its own limit; on an older Flask the 2 MB cap stays and a larger file
# is refused with a 413 in the contract's error shape.
FILE_REQUEST_BYTES = (MAX_FILE_BYTES // 3 + 1) * 4 + 64 * 1024


@v1.before_request
def _allow_files():
    purpose = registry.get((request.view_args or {}).get("purpose_name") or "")
    if purpose is not None and purpose.build_parts is not None:
        try:
            request.max_content_length = FILE_REQUEST_BYTES
        except AttributeError:
            pass
    return None


@v1.get("/v1/health")
def health():
    correlation_id = _correlation_id(request.headers.get("X-Correlation-Id"))
    return _answer(correlation_id, {"status": "ok", "purposes": registry.names()})


@v1.post("/v1/complete/<purpose_name>")
def complete(purpose_name: str):
    payload = request.get_json(silent=True)
    if not isinstance(payload, dict):
        return _refuse(None, "invalid_request", "A JSON object is required.", 400)
    correlation_id = _correlation_id(payload.get("correlationId"))
    if correlation_id is None:
        return _refuse(None, "invalid_request", "correlationId is missing or malformed.", 400)
    if payload.get("contract") != CONTRACT:
        return _refuse(correlation_id, "contract_mismatch", f"This service speaks {CONTRACT}.", 400)

    purpose = registry.get(purpose_name)
    if purpose is None:
        return _refuse(correlation_id, "unknown_purpose", "No such purpose is registered.", 404)

    data = payload.get("data")
    if not isinstance(data, dict):
        return _refuse(correlation_id, "invalid_request", "data must be a JSON object.", 400)
    deadline_ms = payload.get("deadlineMs")
    timeout_seconds: float | None = None
    if deadline_ms is not None:
        if not isinstance(deadline_ms, int) or isinstance(deadline_ms, bool) or not MIN_DEADLINE_MS <= deadline_ms <= MAX_DEADLINE_MS:
            return _refuse(correlation_id, "invalid_request", "deadlineMs is out of range.", 400)
        timeout_seconds = deadline_ms / 1000

    try:
        prompt = purpose.build_prompt(data)
        parts = purpose.build_parts(data) if purpose.build_parts is not None else None
        request_body = purpose.build_request(data) if purpose.build_request is not None else None
    except registry.InvalidData as exc:
        return _refuse(correlation_id, "invalid_request", str(exc), 400)

    try:
        result = provider.complete_json(
            purpose, prompt, correlation_id=correlation_id, timeout_seconds=timeout_seconds,
            data=data, parts=parts, request=request_body,
        )
    except provider.ProviderError as exc:
        status = {"unavailable": 503, "timeout": 504, "provider_unavailable": 502, "provider_rejected": 502, "bad_model_answer": 502}.get(exc.code, 500)
        return _refuse(correlation_id, exc.code, exc.message, status)
    except Exception:  # noqa: BLE001 - last resort; the id finds the traceback, the answer carries none
        log.exception("finance_ai failed purpose=%s correlation=%s", purpose.name, correlation_id)
        return _refuse(correlation_id, "internal_error", "The finance AI service failed.", 500)

    return _answer(
        correlation_id,
        {"purpose": purpose.name, "result": result, "provider": {"name": "openrouter", "model": purpose.model}},
    )
