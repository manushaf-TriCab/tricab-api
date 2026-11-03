"""The RFQ parser's model transport: Claude on Microsoft Foundry, and the Azure OpenAI token for the reader.

This module is the platform's own (not generated). The generated parser modules were written against OpenRouter's
chat-completions shape, and `sync_rfq_parser.py` patches their transport to call `chat` here, so the prompts, schemas
and response handling in the generated code stay exactly as the source has them. `chat` takes that request shape,
sends it as one Anthropic Messages call through finance_ai's `AnthropicFoundry` client (the slot's managed identity,
the same `FOUNDRY_ENDPOINT` / `FOUNDRY_AUTH_MODE` the assistant uses), and returns the answer in the shape the
generated code reads: `{"model", "choices": [{"message": {"content"}, "finish_reason"}]}`.

Deployment names are settings, never code: `RFQ_LLM_MODEL` (line fallback, normalizer, table shape) and
`RFQ_VISION_MODEL` / `RFQ_VISION_ESCALATION_MODEL` (vision OCR), read through `model_settings`.

Never sent, because Claude 5.5 models answer them with a 400: `temperature`, `top_p`, `top_k`, a forced `tool_choice`,
an assistant prefill. Structured output is `output_config.format`, which is not a forced tool call.

The reader (Azure OpenAI Responses on the same Foundry account) authenticates with the same managed identity, for the
`https://cognitiveservices.azure.com` resource. `RFQ_READER_API_KEY` survives only as a local-development fallback and
is refused when `APP_ENV=production`, as `Settings.php` refuses `FOUNDRY_AUTH_MODE=api-key` there.
"""

from __future__ import annotations

import logging
import os
from typing import Any

log = logging.getLogger("rfq-parser")

READER_TOKEN_RESOURCE = "https://cognitiveservices.azure.com"
EFFORT_SETTING = "RFQ_LLM_EFFORT"
_EFFORTS = ("low", "medium", "high", "xhigh", "max")


class AuthError(RuntimeError):
    """No credential for the reader."""


def enabled() -> bool:
    """On when a Foundry endpoint is configured and `RFQ_LLM_ENABLED` has not switched the parser's model calls off."""
    if os.environ.get("RFQ_LLM_ENABLED", "").strip().lower() in {"0", "false", "no", "off"}:
        return False
    return bool(os.environ.get("FOUNDRY_ENDPOINT", "").strip())


def _effort() -> str:
    value = os.environ.get(EFFORT_SETTING, "").strip().lower()
    return value if value in _EFFORTS else "low"


def to_messages_request(payload: dict[str, Any]) -> dict[str, Any]:
    """A chat-completions request (system/user messages, `image_url` parts, a `json_schema` response format) as an
    Anthropic Messages request."""
    from finance_ai import provider

    system: list[str] = []
    messages: list[dict[str, Any]] = []
    for message in payload.get("messages") or []:
        role, content = message.get("role"), message.get("content")
        if role == "system":
            system.append(content if isinstance(content, str) else "\n".join(p.get("text", "") for p in content or []))
            continue
        if isinstance(content, str):
            blocks: list[dict[str, Any]] = [{"type": "text", "text": content}]
        else:
            blocks = []
            for part in content or []:
                if part.get("type") == "image_url":
                    blocks.append(provider.foundry_block(part))
                elif part.get("type") == "text":
                    blocks.append({"type": "text", "text": part.get("text", "")})
            # Images before the question, as Claude reads them best.
            blocks.sort(key=lambda block: block["type"] != "image")
        messages.append({"role": "assistant" if role == "assistant" else "user", "content": blocks})

    output_config: dict[str, Any] = {"effort": _effort()}
    response_format = payload.get("response_format") or {}
    if response_format.get("type") == "json_schema":
        output_config["format"] = {"type": "json_schema", "schema": response_format["json_schema"]["schema"]}
    request: dict[str, Any] = {
        "model": payload["model"],
        "max_tokens": int(payload.get("max_tokens") or 1024),
        "messages": messages,
        "output_config": output_config,
    }
    if system:
        request["system"] = "\n\n".join(system)
    return request


def chat(payload: dict[str, Any], *, timeout: float) -> tuple[dict[str, Any] | None, str | None]:
    """Send one chat-style request to Claude on Foundry. Returns `(data, error)` exactly as the generated
    `_openrouter_chat` did: `data` in chat-completions shape on success, otherwise an error message. The messages keep
    the `HTTP <status>` / `request failed` wording `reading.py` classifies."""
    from finance_ai import provider

    try:
        message = provider._foundry_messages_create(to_messages_request(payload), timeout)  # noqa: SLF001
    except provider.ProviderError as exc:
        log.error("rfq-parser foundry call failed: code=%s reason=%s", exc.code, exc.message)
        return None, f"Foundry request failed: {exc.message}"
    stop_reason = message.get("stop_reason")
    if stop_reason == "refusal":
        category = (message.get("stop_details") or {}).get("category")
        return None, f"Foundry request failed: the model declined the request (refusal, category {category or 'none'})."
    text = "".join(block.get("text", "") for block in message.get("content") or [] if block.get("type") == "text")
    return {
        "model": message.get("model") or payload.get("model"),
        "choices": [{"message": {"content": text}, "finish_reason": "length" if stop_reason == "max_tokens" else "stop"}],
        "usage": message.get("usage"),
    }, None


# --- the reader's credential ---------------------------------------------------------

def _dev_key() -> str:
    if os.environ.get("APP_ENV", "").strip().lower() == "production":
        return ""
    return os.environ.get("RFQ_READER_API_KEY", "").strip()


def _identity_present() -> bool:
    return bool(os.environ.get("IDENTITY_ENDPOINT", "").strip() and os.environ.get("IDENTITY_HEADER", "").strip())


def reader_auth_mode() -> str | None:
    """How the reader would authenticate, for the health view: `managed-identity`, `api-key (local)` or None."""
    if _dev_key():
        return "api-key (local)"
    return "managed-identity" if _identity_present() else None


def reader_auth_ready() -> bool:
    return reader_auth_mode() is not None


def reader_auth_headers() -> dict[str, str]:
    """Headers for one Azure OpenAI call. The token is cached by finance_ai until a minute before it expires."""
    key = _dev_key()
    if key:
        return {"api-key": key}
    from finance_ai import provider

    try:
        return {"Authorization": f"Bearer {provider._managed_identity_token(READER_TOKEN_RESOURCE)}"}  # noqa: SLF001
    except provider._TokenError as exc:  # noqa: SLF001
        raise AuthError(f"the managed identity gave no token: {exc}") from exc
