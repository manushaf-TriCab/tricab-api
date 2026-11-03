"""The one place `finance_ai` calls a model provider: OpenRouter, as the finance apps do today, or Claude on
Microsoft Foundry (`tricab-ai2`) for a purpose that has been switched to it.

`complete_json` is the only entry point. **Which provider a call uses is a setting, read per call**
(`provider_for`): `FINANCE_AI_PROVIDER_<PURPOSE>` (e.g. `FINANCE_AI_PROVIDER_AR_CLASSIFY=foundry`), then
`FINANCE_AI_PROVIDER`, then OpenRouter. So switching one purpose is an app setting, not a deploy, and nothing
switches until the accuracy check says so (plan O4/O5). The OpenRouter key is `FINANCE_OPENROUTER_API_KEY`,
the finance apps' own key (plan Q4). The RFQ parser used to read `OPENROUTER_API_KEY`; it now calls Claude on Foundry.

The Foundry path uses the Anthropic SDK's `AnthropicFoundry` client against `{FOUNDRY_ENDPOINT}/anthropic/`, the
endpoint and auth mode the assistant uses: the slot's managed identity (resource `https://ai.azure.com`) unless
`FOUNDRY_AUTH_MODE=api-key`, which is for local development and refused when `APP_ENV=production`, as
`Settings.php` refuses it. The SDK is imported only on that path, so the OpenRouter path runs without it.

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
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from typing import Any

from .registry import Purpose

log = logging.getLogger("finance-ai")

OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
# The Alpha Decisions API, which Tri-AR classifies through (a "decisions" purpose).
OPENROUTER_DECISIONS_URL = "https://openrouter.ai/api/alpha/decisions"
KEY_SETTING = "FINANCE_OPENROUTER_API_KEY"

OPENROUTER = "openrouter"
FOUNDRY = "foundry"
PROVIDERS = (OPENROUTER, FOUNDRY)
PROVIDER_SETTING = "FINANCE_AI_PROVIDER"

FOUNDRY_ENDPOINT_SETTING = "FOUNDRY_ENDPOINT"
FOUNDRY_AUTH_MODE_SETTING = "FOUNDRY_AUTH_MODE"
FOUNDRY_KEY_SETTING = "FOUNDRY_API_KEY"
FOUNDRY_TOKEN_RESOURCE = "https://ai.azure.com"
# Claude reads these image types; a TIFF (which Tri-AP accepts) has no image block.
FOUNDRY_IMAGE_TYPES = ("image/png", "image/jpeg", "image/gif", "image/webp")


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


def provider_for(purpose: Purpose) -> str:
    """`FINANCE_AI_PROVIDER_<PURPOSE>`, then `FINANCE_AI_PROVIDER`, then OpenRouter. An unknown value is logged and
    ignored, so a mistyped setting leaves the purpose where it was rather than breaking it. The shared setting moves
    only the purposes that have a Foundry setup (Tri-Task's have none yet); naming one directly is never ignored."""
    own = f"{PROVIDER_SETTING}_{re.sub(r'[^A-Z0-9]', '_', purpose.name.upper())}"
    for setting in (own, PROVIDER_SETTING):
        value = os.environ.get(setting, "").strip().lower()
        if not value:
            continue
        if value not in PROVIDERS:
            log.warning("ignoring %s=%r: the providers are %s", setting, value, ", ".join(PROVIDERS))
            continue
        if setting == PROVIDER_SETTING and value == FOUNDRY and purpose.foundry is None:
            return OPENROUTER
        return value
    return OPENROUTER


def complete_json(
    purpose: Purpose,
    prompt: str,
    *,
    correlation_id: str,
    timeout_seconds: float | None = None,
    data: dict[str, Any] | None = None,
    parts: list[dict[str, Any]] | None = None,
    request: dict[str, Any] | None = None,
    provider: str | None = None,
    trace: dict[str, Any] | None = None,
) -> dict[str, Any]:
    """One prompt in, one schema-valid JSON object out, or `ProviderError`.

    `data` is the caller data (handed to the purpose `parse`), `parts` the file parts of a chat call and `request`
    the whole body of a decisions call; all three are optional and a plain purpose uses none of them. `provider`
    overrides the setting (the shadow evaluation runs both), and `trace`, when given, is filled with the provider,
    the model, the latency and the token usage of the call, whether it succeeded or not.
    """
    chosen = provider or provider_for(purpose)
    trace = trace if trace is not None else {}
    trace.update({"provider": chosen, "purpose": purpose.name})
    started = time.monotonic()
    try:
        if chosen == FOUNDRY:
            return _complete_foundry(purpose, prompt, timeout_seconds, data or {}, parts, trace)
        if chosen != OPENROUTER:
            raise ProviderError("internal_error", f"Unknown model provider {chosen!r}.")
        return _complete(purpose, prompt, timeout_seconds, data or {}, parts, request, trace)
    except ProviderError as exc:
        # The purpose and the correlation id find the request; the text of it is never here.
        log.error(
            "model call failed purpose=%s provider=%s correlation=%s code=%s: %s",
            purpose.name, chosen, correlation_id, exc.code, exc.message,
        )
        trace["error"] = exc.code
        raise
    finally:
        trace["latency_ms"] = round((time.monotonic() - started) * 1000)


def _complete(
    purpose: Purpose,
    prompt: str,
    timeout_seconds: float | None,
    data: dict[str, Any],
    parts: list[dict[str, Any]] | None,
    request: dict[str, Any] | None,
    trace: dict[str, Any] | None = None,
) -> dict[str, Any]:
    trace = trace if trace is not None else {}
    api_key = os.environ.get(KEY_SETTING, "").strip()
    if not api_key:
        raise ProviderError("unavailable", f"The model provider is not configured ({KEY_SETTING} is not set).")
    timeout = min(float(timeout_seconds), purpose.timeout_seconds) if timeout_seconds else float(purpose.timeout_seconds)
    if purpose.call == "decisions":
        if request is None:
            raise ProviderError("internal_error", "A decisions purpose was called without its request.")
        url = OPENROUTER_DECISIONS_URL
        payload: dict[str, Any] = {**request, "model": purpose.model}
        trace["model"] = purpose.model
    else:
        url = OPENROUTER_URL
        content: Any = [{"type": "text", "text": prompt}, *parts] if parts else prompt
        payload = {"model": purpose.model, "messages": [{"role": "user", "content": content}]}
        trace["model"] = purpose.model
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
        if isinstance(decoded, dict) and isinstance(decoded.get("usage"), dict):
            trace["usage"] = decoded["usage"]
        raw = decoded if purpose.call == "decisions" else decoded["choices"][0]["message"]["content"]
        result = purpose.parse(raw, data) if purpose.parse is not None else _extract_json(raw)
        validate(result, purpose.schema)
    except (KeyError, IndexError, TypeError, ValueError) as exc:
        # The reason names the schema path, never the model's text.
        raise ProviderError("bad_model_answer", "The model's answer could not be read as the expected result.") from exc
    return result


# --------------------------------------------------------------------------
# Claude on Microsoft Foundry
# --------------------------------------------------------------------------


def _complete_foundry(
    purpose: Purpose,
    prompt: str,
    timeout_seconds: float | None,
    data: dict[str, Any],
    parts: list[dict[str, Any]] | None,
    trace: dict[str, Any],
) -> dict[str, Any]:
    spec = purpose.foundry
    if spec is None:
        raise ProviderError("unavailable", f"The purpose {purpose.name} has no Foundry setup.")
    timeout = min(float(timeout_seconds), purpose.timeout_seconds) if timeout_seconds else float(purpose.timeout_seconds)
    text = spec.build_prompt(data) if spec.build_prompt is not None else prompt
    # Documents and images before the question, as Claude reads them best.
    content = [*(foundry_block(part) for part in parts or []), {"type": "text", "text": text}]
    payload = {
        "model": spec.model,
        "max_tokens": spec.max_tokens,
        # No temperature, top_p or top_k: Haiku 5.5 answers a non-default one with a 400. Thinking is adaptive by
        # default, and effort is how much of it there is. No assistant prefill (also a 400): the schema shapes it.
        "messages": [{"role": "user", "content": content}],
        "output_config": {"effort": spec.effort, "format": {"type": "json_schema", "schema": spec.schema_for(data)}},
    }
    trace["model"] = spec.model
    message = _foundry_messages_create(payload, timeout)

    usage = message.get("usage")
    if isinstance(usage, dict):
        trace["usage"] = usage
    stop_reason = message.get("stop_reason")
    trace["stop_reason"] = stop_reason
    if stop_reason == "refusal":
        # Haiku has no server-side fallback: a declined request stays declined, and the person retries or does it.
        category = (message.get("stop_details") or {}).get("category")
        trace["refusal_category"] = category
        raise ProviderError("provider_rejected", f"The model declined the request (refusal, category {category or 'none'}).")
    if stop_reason == "max_tokens":
        raise ProviderError("bad_model_answer", "The model's answer was cut off before it was complete.")

    raw = "".join(block.get("text", "") for block in message.get("content") or [] if block.get("type") == "text")
    parse = spec.parse or purpose.parse
    try:
        result = parse(raw, data) if parse is not None else _extract_json(raw)
        validate(result, purpose.schema)
    except (KeyError, IndexError, TypeError, ValueError) as exc:
        raise ProviderError("bad_model_answer", "The model's answer could not be read as the expected result.") from exc
    return result


def foundry_block(part: dict[str, Any]) -> dict[str, Any]:
    """A caller file part (`registry.file_part`'s OpenRouter shape) as a Claude content block: a PDF as a `document`
    block, an image as an `image` block, both base64."""
    if part.get("type") == "file":
        uri = part["file"]["file_data"]
    elif part.get("type") == "image_url":
        uri = part["image_url"]["url"]
    else:
        raise ProviderError("internal_error", "A file part of an unknown kind reached the Foundry provider.")
    header, _, encoded = uri.partition(",")
    media_type = header.removeprefix("data:").removesuffix(";base64")
    if media_type == "application/pdf":
        return {"type": "document", "source": {"type": "base64", "media_type": media_type, "data": encoded}}
    if media_type in FOUNDRY_IMAGE_TYPES:
        return {"type": "image", "source": {"type": "base64", "media_type": media_type, "data": encoded}}
    raise ProviderError("provider_rejected", f"Claude cannot read a {media_type} file.")


_client_lock = threading.Lock()
_clients: dict[tuple[str, str], Any] = {}


def _foundry_client() -> Any:
    """One `AnthropicFoundry` client per endpoint and auth mode. Retries are off: a slow or failed call is reported
    within the purpose's timeout, as the OpenRouter path reports it, and the caller decides whether to try again."""
    endpoint = os.environ.get(FOUNDRY_ENDPOINT_SETTING, "").strip().rstrip("/")
    if not endpoint:
        raise ProviderError("unavailable", f"The model provider is not configured ({FOUNDRY_ENDPOINT_SETTING} is not set).")
    mode = os.environ.get(FOUNDRY_AUTH_MODE_SETTING, "managed-identity").strip().lower() or "managed-identity"
    if mode not in ("managed-identity", "api-key"):
        raise ProviderError("unavailable", f"{FOUNDRY_AUTH_MODE_SETTING} must be managed-identity or api-key.")
    if mode == "api-key" and os.environ.get("APP_ENV", "").strip().lower() == "production":
        raise ProviderError("unavailable", "Foundry API keys are refused in production; use the managed identity.")
    key = os.environ.get(FOUNDRY_KEY_SETTING, "").strip() if mode == "api-key" else ""
    if mode == "api-key" and not key:
        raise ProviderError("unavailable", f"The model provider is not configured ({FOUNDRY_KEY_SETTING} is not set).")
    with _client_lock:
        cache_key = (endpoint, mode + ":" + key[-6:])
        if cache_key not in _clients:
            try:
                from anthropic import AnthropicFoundry
            except ImportError as exc:
                raise ProviderError("unavailable", "The Anthropic SDK is not installed for this service.") from exc
            auth: dict[str, Any] = {"api_key": key} if key else {"azure_ad_token_provider": _managed_identity_token}
            _clients[cache_key] = AnthropicFoundry(base_url=f"{endpoint}/anthropic/", max_retries=0, **auth)
        return _clients[cache_key]


def _foundry_messages_create(payload: dict[str, Any], timeout: float) -> dict[str, Any]:
    """Send one Messages request and return the response as a dict. Tests stub this and never import the SDK."""
    client = _foundry_client()
    import anthropic

    try:
        message = client.with_options(timeout=timeout).messages.create(**payload)
    except anthropic.APITimeoutError as exc:
        raise ProviderError("timeout", "The model provider did not answer in time.") from exc
    except anthropic.APIConnectionError as exc:
        raise ProviderError("provider_unavailable", "The model provider could not be reached.") from exc
    except (anthropic.RateLimitError, anthropic.InternalServerError) as exc:
        raise ProviderError("provider_unavailable", f"The model provider is unavailable (HTTP {exc.status_code}).") from exc
    except anthropic.APIStatusError as exc:
        # 400 (a request Claude will not take), 401/403 (identity or role), 404 (no such deployment): an operator fixes it.
        raise ProviderError("provider_rejected", f"The model provider refused the request (HTTP {exc.status_code}).") from exc
    except _TokenError as exc:
        raise ProviderError("unavailable", f"The managed identity gave no token: {exc}") from exc
    return message.model_dump(mode="json")


class _TokenError(RuntimeError):
    pass


_token_lock = threading.Lock()
_token: dict[str, Any] = {"value": None, "expires_at": 0.0}
# Tokens for any other resource (the RFQ reader's Azure OpenAI), one cache entry each.
_other_tokens: dict[str, dict[str, Any]] = {}
TOKEN_SKEW_SECONDS = 60


def _managed_identity_token(resource: str = FOUNDRY_TOKEN_RESOURCE) -> str:
    """The slot's managed-identity token for Foundry (or, with `resource`, another Azure resource the same identity
    may call), cached until a minute before it expires: the same endpoints, API versions and resource as PHP's
    `ManagedIdentityTokenClient` (App Service's `IDENTITY_ENDPOINT` first, then IMDS)."""
    with _token_lock:
        cache = _token if resource == FOUNDRY_TOKEN_RESOURCE else _other_tokens.setdefault(resource, {"value": None, "expires_at": 0.0})
        if cache["value"] and cache["expires_at"] > time.time() + TOKEN_SKEW_SECONDS:
            return cache["value"]
        endpoint = os.environ.get("IDENTITY_ENDPOINT", "").strip()
        header = os.environ.get("IDENTITY_HEADER", "").strip()
        if endpoint or header:
            if not (endpoint and header):
                raise _TokenError("the managed identity environment is incomplete")
            if urllib.parse.urlparse(endpoint).hostname not in ("localhost", "127.0.0.1") and not (
                urllib.parse.urlparse(endpoint).hostname or ""
            ).startswith("169.254."):
                raise _TokenError("IDENTITY_ENDPOINT is not a local address")
            url, headers, version = endpoint, {"X-IDENTITY-HEADER": header}, "2019-08-01"
        else:
            url, headers, version = "http://169.254.169.254/metadata/identity/oauth2/token", {"Metadata": "true"}, "2018-02-01"
        query = urllib.parse.urlencode({"api-version": version, "resource": resource})
        request = urllib.request.Request(f"{url}{'&' if '?' in url else '?'}{query}", headers=headers)
        try:
            with urllib.request.urlopen(request, timeout=5) as response:  # noqa: S310 - a local identity endpoint
                decoded = json.loads(response.read().decode("utf-8"))
        except (urllib.error.URLError, OSError, ValueError) as exc:
            raise _TokenError(type(exc).__name__) from exc
        value = decoded.get("access_token") if isinstance(decoded, dict) else None
        if not isinstance(value, str) or not value:
            raise _TokenError("the response carried no access_token")
        try:
            expires_at = float(decoded.get("expires_on"))
        except (TypeError, ValueError):
            expires_at = time.time() + 300
        cache.update({"value": value, "expires_at": expires_at})
        return value
