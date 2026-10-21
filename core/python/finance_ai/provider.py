"""The one place `finance_ai` calls a model provider: OpenRouter, as the finance apps do today.

`complete_json` is the only entry point, so moving to Azure AI Foundry later (plan 6, gated on
the invoice accuracy check) is a change to this file. The key is `FINANCE_OPENROUTER_API_KEY`,
the finance apps' own key (plan Q4), never `OPENROUTER_API_KEY`, which the RFQ parser reads.

**Every failure is logged**, at ERROR, with the purpose, the correlation id and the reason,
because failures in the finance apps today reach no log and the person just sees an empty draft
(plan 4.5). What is never logged: the prompt, the data, the model's answer, the key. Tests stub
`_post_json` and must never reach the network.
"""

from __future__ import annotations

import json
import logging
import os
import re
import socket
import urllib.error
import urllib.request
from typing import Any

from .registry import Purpose

log = logging.getLogger("finance-ai")

OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
# The Alpha Decisions API, which Tri-AR classifies through (a "decisions" purpose).
OPENROUTER_DECISIONS_URL = "https://openrouter.ai/api/alpha/decisions"
KEY_SETTING = "FINANCE_OPENROUTER_API_KEY"


class ProviderError(RuntimeError):
    """The model call failed. `code` is a `finance-ai.v1` error code; the message is safe to show."""

    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code
        self.message = message


def _post_json(url: str, payload: dict[str, Any], api_key: str, timeout: float) -> tuple[int, str]:
    """POST and return (status, body). Raises `socket.timeout`/`urllib.error.URLError` for transport faults."""
    request = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        method="POST",
        headers={
            "Authorization": f"Bearer {api_key}",
            "Content-Type": "application/json",
            "HTTP-Referer": "https://tricab.ai/finance",
            "X-Title": os.environ.get("OPENROUTER_APP_NAME", "TriCab Finance"),
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:  # noqa: S310 - fixed https URL
            return response.status, response.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as exc:
        return exc.code, exc.read().decode("utf-8", errors="replace")


_TYPES = {"object": dict, "array": list, "string": str, "number": (int, float), "boolean": bool}


def validate(value: Any, schema: dict[str, Any], path: str = "result") -> None:
    """Just enough JSON Schema for the answers finance asks for: type, enum, required, properties, items."""
    expected = schema.get("type")
    if expected:
        python_type = _TYPES[expected]
        # bool is an int in Python; a boolean answer is not a number.
        if not isinstance(value, python_type) or (expected == "number" and isinstance(value, bool)):
            raise ValueError(f"{path} should be {expected}")
    if "enum" in schema and value not in schema["enum"]:
        raise ValueError(f"{path} is not one of the allowed values")
    if expected == "object":
        for key in schema.get("required", []):
            if key not in value:
                raise ValueError(f"{path}.{key} is missing")
        for key, sub in schema.get("properties", {}).items():
            if key in value:
                validate(value[key], sub, f"{path}.{key}")
    elif expected == "array":
        for i, item in enumerate(value):
            validate(item, schema.get("items", {}), f"{path}[{i}]")


def _extract_json(content: str) -> Any:
    text = (content or "").strip()
    fenced = re.match(r"^```(?:json)?\s*(.*?)\s*```$", text, re.S)
    if fenced:
        text = fenced.group(1)
    return json.loads(text)


def complete_json(
    purpose: Purpose,
    prompt: str,
    *,
    correlation_id: str,
    timeout_seconds: float | None = None,
    data: dict[str, Any] | None = None,
    parts: list[dict[str, Any]] | None = None,
    request: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """One prompt in, one schema-valid JSON object out, or `ProviderError`.

    `data` is the caller data (handed to the purpose `parse`), `parts` the file parts of a chat call and `request`
    the whole body of a decisions call; all three are optional and a plain purpose uses none of them.
    """
    try:
        return _complete(purpose, prompt, timeout_seconds, data or {}, parts, request)
    except ProviderError as exc:
        # The purpose and the correlation id find the request; the text of it is never here.
        log.error("model call failed purpose=%s correlation=%s code=%s: %s", purpose.name, correlation_id, exc.code, exc.message)
        raise


def _complete(
    purpose: Purpose,
    prompt: str,
    timeout_seconds: float | None,
    data: dict[str, Any],
    parts: list[dict[str, Any]] | None,
    request: dict[str, Any] | None,
) -> dict[str, Any]:
    api_key = os.environ.get(KEY_SETTING, "").strip()
    if not api_key:
        raise ProviderError("unavailable", f"The model provider is not configured ({KEY_SETTING} is not set).")
    timeout = min(float(timeout_seconds), purpose.timeout_seconds) if timeout_seconds else float(purpose.timeout_seconds)
    if purpose.call == "decisions":
        if request is None:
            raise ProviderError("internal_error", "A decisions purpose was called without its request.")
        url = OPENROUTER_DECISIONS_URL
        payload: dict[str, Any] = {**request, "model": purpose.model}
    else:
        url = OPENROUTER_URL
        content: Any = [{"type": "text", "text": prompt}, *parts] if parts else prompt
        payload = {"model": purpose.model, "messages": [{"role": "user", "content": content}]}
        if purpose.temperature is not None:
            payload["temperature"] = purpose.temperature
        if purpose.response_format == "json_object":
            payload["response_format"] = {"type": "json_object"}
        else:
            payload["response_format"] = {
                "type": "json_schema",
                "json_schema": {"name": "result", "strict": True, "schema": purpose.schema},
            }
    try:
        status, body = _post_json(url, payload, api_key, timeout)
    except (socket.timeout, TimeoutError) as exc:
        raise ProviderError("timeout", "The model provider did not answer in time.") from exc
    except (urllib.error.URLError, OSError) as exc:
        raise ProviderError("provider_unavailable", "The model provider could not be reached.") from exc

    if status in (401, 402, 403):
        # A key, quota or billing fault: an operator fixes it, a retry does not.
        raise ProviderError("provider_rejected", f"The model provider refused the request (HTTP {status}).")
    if status == 408 or status == 504:
        raise ProviderError("timeout", "The model provider did not answer in time.")
    if status == 429 or status >= 500:
        raise ProviderError("provider_unavailable", f"The model provider is unavailable (HTTP {status}).")
    if status >= 400:
        raise ProviderError("provider_rejected", f"The model provider refused the request (HTTP {status}).")

    try:
        decoded = json.loads(body)
        raw = decoded if purpose.call == "decisions" else decoded["choices"][0]["message"]["content"]
        result = purpose.parse(raw, data) if purpose.parse is not None else _extract_json(raw)
        validate(result, purpose.schema)
    except (KeyError, IndexError, TypeError, ValueError) as exc:
        # The reason names the schema path, never the model's text.
        raise ProviderError("bad_model_answer", "The model's answer could not be read as the expected result.") from exc
    return result
