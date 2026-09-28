"""Learned corrections, held for the duration of one parse request.

The source parser reads these from its own correction-memory table
(``log_store.correction_for``) in the middle of matching a line. On the platform
that table belongs to the PHP API, which owns every RFQ store, so the parser
never opens it: the API sends the corrections that apply with the request, and
``rfq_parser.pipeline`` asks this module instead of a database.

A correction is keyed by :func:`correction_key` of the line text — the same key
the source uses — and names a product code and how many times people confirmed
it. The source declines to apply one when two codes tie on confirmations; the
API resolves that before sending, so a key here maps to one answer or is absent.

The value lives in a ``ContextVar`` rather than a module global, so two requests
on the same worker can never see each other's corrections.
"""

from __future__ import annotations

from contextlib import contextmanager
from contextvars import ContextVar
from typing import Any, Iterator, Mapping

from tricab_cable_rules.normalize import normalize_text

_current: ContextVar[Mapping[str, Mapping[str, Any]]] = ContextVar("rfq_parser_corrections", default={})


def correction_key(raw_text: Any) -> str:
    """The source's key (``log_store.correction_key``): normalised, then case-folded."""
    return normalize_text(str(raw_text or "")).casefold()


def correction_for(raw_text: str) -> dict[str, Any] | None:
    """``{"product_code", "confirmations"}`` for this line, or None — the shape the source returns."""
    key = correction_key(raw_text)
    if not key:
        return None
    found = _current.get().get(key)
    if not found or not found.get("product_code"):
        return None
    return {
        "product_code": str(found["product_code"]),
        "confirmations": int(found.get("confirmations") or 0),
    }


@contextmanager
def using(corrections: Mapping[str, Mapping[str, Any]] | None) -> Iterator[None]:
    """Apply ``corrections`` (already keyed by :func:`correction_key`) inside the block."""
    token = _current.set(dict(corrections or {}))
    try:
        yield
    finally:
        _current.reset(token)
