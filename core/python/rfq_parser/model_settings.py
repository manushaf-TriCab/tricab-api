"""Request-local model overrides supplied by the authenticated PHP parser client."""

from __future__ import annotations

import logging
import os
import re
from contextvars import ContextVar

_MODELS: ContextVar[dict[str, str]] = ContextVar("rfq_models", default={})
ALLOWED = frozenset({
    "RFQ_LLM_MODEL", "RFQ_READER_MODEL", "RFQ_READER_NORMALIZER_MODEL",
    "RFQ_NORMALIZER_MODEL", "RFQ_TABLE_SHAPE_MODEL",
    "RFQ_VISION_MODEL", "RFQ_VISION_ESCALATION_MODEL",
})
# The selectors that were OpenRouter ids and are Foundry deployment names now.
FOUNDRY_NAMES = frozenset({"RFQ_LLM_MODEL", "RFQ_NORMALIZER_MODEL", "RFQ_TABLE_SHAPE_MODEL", "RFQ_VISION_MODEL", "RFQ_VISION_ESCALATION_MODEL"})
_IDENTIFIER = re.compile(r"^[A-Za-z0-9._:/-]{1,160}$")


def install(models: object) -> bool:
    if models is None:
        _MODELS.set({})
        return True
    if not isinstance(models, dict):
        return False
    if any(not isinstance(key, str) or key not in ALLOWED or not isinstance(value, str) or not _IDENTIFIER.fullmatch(value) for key, value in models.items()):
        return False
    # A Foundry/Azure deployment name has no "/". An OpenRouter id ("openai/gpt-4o-mini") left in Platform Settings
    # from before the move would only make the call fail, so it is dropped and the slot's own setting applies.
    kept = {key: value for key, value in models.items() if key not in FOUNDRY_NAMES or "/" not in value}
    if len(kept) != len(models):
        logging.getLogger("rfq-parser").warning("ignored model overrides that are not deployment names: %s", sorted(set(models) - set(kept)))
    _MODELS.set(kept)
    return True


def get(name: str, default: str = "") -> str:
    return _MODELS.get().get(name) or os.environ.get(name, default)
