"""Request-local model overrides supplied by the authenticated PHP parser client."""

from __future__ import annotations

import os
import re
from contextvars import ContextVar

_MODELS: ContextVar[dict[str, str]] = ContextVar("rfq_models", default={})
ALLOWED = frozenset({
    "OPENROUTER_MODEL", "RFQ_READER_MODEL", "RFQ_READER_NORMALIZER_MODEL",
    "RFQ_NORMALIZER_MODEL", "RFQ_TABLE_SHAPE_MODEL",
    "VISION_OCR_PRIMARY_MODEL", "VISION_OCR_ESCALATION_MODEL",
})
_IDENTIFIER = re.compile(r"^[A-Za-z0-9._:/-]{1,160}$")


def install(models: object) -> bool:
    if models is None:
        _MODELS.set({})
        return True
    if not isinstance(models, dict):
        return False
    if any(not isinstance(key, str) or key not in ALLOWED or not isinstance(value, str) or not _IDENTIFIER.fullmatch(value) for key, value in models.items()):
        return False
    _MODELS.set(models.copy())
    return True


def get(name: str, default: str = "") -> str:
    return _MODELS.get().get(name) or os.environ.get(name, default)
