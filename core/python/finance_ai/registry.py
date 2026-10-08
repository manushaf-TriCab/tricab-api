"""The purposes `finance_ai` can complete, and what each one owns.

A caller names a purpose and sends only data. The purpose owns the prompt, the response
schema, the model, the temperature and the timeout (decision D10): the browser, and PHP,
never send a prompt. Phase 1 registers none; each app's phase adds its own (Tri-AP's
invoice extraction, Tri-AR's classification, and so on). Tests register a fake.
"""

from __future__ import annotations

import base64
import binascii
from dataclasses import dataclass
from typing import Any, Callable

DEFAULT_MODEL = "google/gemini-2.5-flash"
DEFAULT_TIMEOUT_SECONDS = 60
MAX_TIMEOUT_SECONDS = 300


class InvalidData(ValueError):
    """The caller's `data` is not what the purpose takes. The message is safe to show a person."""


@dataclass(frozen=True)
class Purpose:
    name: str
    # Builds the prompt from the caller's data, raising InvalidData if the data is wrong.
    # User text in the data must be wrapped as data, never as instructions, by the builder.
    build_prompt: Callable[[dict[str, Any]], str]
    # The JSON Schema the answer must satisfy (the subset `provider.validate` knows).
    schema: dict[str, Any]
    model: str = DEFAULT_MODEL
    # None sends no temperature, so the provider default applies (Tri-AR prose and extraction calls do this, as the source did).
    temperature: float | None = 0.3
    timeout_seconds: int = DEFAULT_TIMEOUT_SECONDS
    # --- Phase 5 (T5.8): everything below is optional, and a purpose that sets none of it behaves as before. ---
    # "chat" is OpenRouter chat completions. "decisions" is its Alpha Decisions API (Tri-AR classification):
    # the request is `build_request(data)` and the raw reply is given to `parse`.
    call: str = "chat"
    # For a chat purpose: "json_schema" sends `schema` as a strict response format; "json_object" asks for any JSON
    # object (the source's own calls) and `schema` is checked on our side only.
    response_format: str = "json_schema"
    # The whole request body of a decisions call, built from the caller's data (raises InvalidData).
    build_request: Callable[[dict[str, Any]], dict[str, Any]] | None = None
    # File parts (a PDF or an image, base64) added after the prompt of a chat call, built from the caller's data.
    build_parts: Callable[[dict[str, Any]], list[dict[str, Any]]] | None = None
    # Reads the model reply (chat: the message text; decisions: the decoded response) into the result. Raising
    # ValueError, KeyError, TypeError or IndexError is a `bad_model_answer`. Default: the fenced-JSON reader.
    parse: Callable[[Any, dict[str, Any]], dict[str, Any]] | None = None

    def __post_init__(self) -> None:
        if not self.name or not all(ch.islower() or ch.isdigit() or ch in "-_." for ch in self.name):
            raise ValueError(f"A purpose name is lower-case letters, digits, '-', '_' or '.': {self.name!r}")
        if not 1 <= self.timeout_seconds <= MAX_TIMEOUT_SECONDS:
            raise ValueError(f"timeout_seconds must be 1 to {MAX_TIMEOUT_SECONDS}")
        if self.call not in ("chat", "decisions"):
            raise ValueError("call is chat or decisions")
        if self.response_format not in ("json_schema", "json_object"):
            raise ValueError("response_format is json_schema or json_object")
        if self.call == "decisions" and (self.build_request is None or self.parse is None):
            raise ValueError("A decisions purpose needs build_request and parse")


REGISTRY: dict[str, Purpose] = {}


def register(purpose: Purpose) -> Purpose:
    if purpose.name in REGISTRY:
        raise ValueError(f"The purpose {purpose.name!r} is already registered.")
    REGISTRY[purpose.name] = purpose
    return purpose


def unregister(name: str) -> None:
    REGISTRY.pop(name, None)


def get(name: str) -> Purpose | None:
    return REGISTRY.get(name)


def names() -> list[str]:
    return sorted(REGISTRY)


# A file handed to a purpose by the caller: {"name", "content_type", "data"} with data base64. The ceiling is the
# platform file store ceiling (FinanceAttachmentRules::MAX_BYTES); FinanceAiContract::MAX_FILE_BYTES is the same number.
MAX_FILE_BYTES = 33_554_432
FILE_TYPES = ("application/pdf", "image/png", "image/jpeg", "image/tiff")


def file_part(value: Any, field: str = "file") -> dict[str, Any]:
    """A caller file as an OpenRouter content part: a PDF as a `file` part, an image as an `image_url` part.

    Raises InvalidData (a 400 to the caller, with a message safe to show) for anything that is not a file of an
    accepted type within the size ceiling. The bytes are never logged.
    """
    if not isinstance(value, dict):
        raise InvalidData(f"{field} must be an object with name, content_type and data.")
    name = value.get("name")
    content_type = value.get("content_type")
    encoded = value.get("data")
    if not isinstance(name, str) or not name.strip() or len(name) > 255:
        raise InvalidData(f"{field}.name is required.")
    if content_type not in FILE_TYPES:
        raise InvalidData(f"{field}.content_type must be one of {', '.join(FILE_TYPES)}.")
    if not isinstance(encoded, str) or not encoded:
        raise InvalidData(f"{field}.data is required.")
    # base64 is 4 characters for 3 bytes: refuse a file over the ceiling before decoding it.
    if len(encoded) > (MAX_FILE_BYTES // 3 + 1) * 4:
        raise InvalidData(f"{field} is larger than the file ceiling.")
    try:
        raw = base64.b64decode(encoded, validate=True)
    except (binascii.Error, ValueError) as exc:
        raise InvalidData(f"{field}.data is not valid base64.") from exc
    if not raw:
        raise InvalidData(f"{field} is empty.")
    if len(raw) > MAX_FILE_BYTES:
        raise InvalidData(f"{field} is larger than the file ceiling.")
    data_uri = f"data:{content_type};base64,{encoded}"
    if content_type == "application/pdf":
        return {"type": "file", "file": {"filename": name.strip(), "file_data": data_uri}}
    return {"type": "image_url", "image_url": {"url": data_uri}}
