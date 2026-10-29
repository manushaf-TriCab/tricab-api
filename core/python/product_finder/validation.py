"""Field checks on a finder request, before anything is matched or sized.

The finder used to accept whatever arrived and coerce it: a core count of -2
became `KL-PAXN/-2C16OR` reported as `matched`, 0 cores quietly became 1, a
17 mm2 conductor was assembled into a code, an unknown style fell back to the
house default, and an unknown cable type or installation method raised a
500. Every one of those is a mistake the person can fix, so it comes back as
`status: invalid` with one message per field instead of a product code.

Checks run on the raw payload, not on `FinderRequest`: `from_payload` turns
"abc" and "" alike into None, and by then a typo is indistinguishable from a
field that was left blank.

Field names in the errors are the API's (`coreCount`, `loadAmps`, `lengthM`),
not the payload keys the finder reads (`cores`, `load`, `length`), because the
web form highlights fields by the names it sent to the API.
"""

from __future__ import annotations

import re
from dataclasses import dataclass
from functools import lru_cache
from typing import Any

from . import rules
from .constants import CABLE_TYPES, CONDUCTOR_SIZES_MM2, INSTALL_METHODS, PHASES

MAX_VOLTAGE = 33_000
AMBIENT_RANGE_C = (0, 70)


@dataclass(frozen=True)
class FieldError:
    field: str
    message: str

    def public(self) -> dict[str, str]:
        return {"field": self.field, "message": self.message}


def normalise_style(raw: Any) -> str:
    """`" kl- "` and `"KL"` are the same style; a product code's stem is too."""
    return str(raw or "").strip().upper().rstrip("-").strip()


@lru_cache(maxsize=1)
def _catalogue_shape() -> tuple[dict[str, frozenset[int]], int, tuple[float, ...]]:
    """Core counts per style, the largest core count made, and the metric sizes."""
    cores: dict[str, set[int]] = {}
    sizes: set[float] = set(CONDUCTOR_SIZES_MM2)
    for product in rules.catalog().products_by_code.values():
        try:
            cores.setdefault(str(product.style).upper(), set()).add(int(product.no_core))
        except (TypeError, ValueError):
            pass
        try:
            # AWG sizes such as 4/0 are not mm2 and are skipped.
            sizes.add(float(product.cross_core))
        except (TypeError, ValueError):
            pass
    largest = max((c for counts in cores.values() for c in counts), default=0)
    return (
        {style: frozenset(counts) for style, counts in cores.items()},
        largest,
        tuple(sorted(sizes)),
    )


def _number(payload: dict[str, Any], key: str) -> tuple[bool, float | None]:
    """(stated, value). Stated-but-unreadable is (True, None)."""
    value = payload.get(key)
    if value is None or value == "" or value == "auto":
        return False, None
    if isinstance(value, bool):
        return True, None
    try:
        number = float(value)
    except (TypeError, ValueError):
        return True, None
    if number != number or number in (float("inf"), float("-inf")):
        return True, None
    return True, number


def _size_label(size: float) -> str:
    return f"{size:g} mm²"


def validate_payload(payload: dict[str, Any]) -> list[FieldError]:
    errors: list[FieldError] = []
    style_cores: dict[str, frozenset[int]] = {}
    max_cores = 0
    sizes: tuple[float, ...] = tuple(CONDUCTOR_SIZES_MM2)
    try:
        style_cores, max_cores, sizes = _catalogue_shape()
    except Exception:  # noqa: BLE001 - catalogue-backed checks are skipped, not failed
        pass

    # ── style ────────────────────────────────────────────────────────────────
    style = normalise_style(payload.get("style"))
    if style:
        if not re.fullmatch(r"[A-Z0-9]{2}", style) or rules.style_by_code(style) is None:
            errors.append(FieldError("style", f"{style} is not one of our styles. Pick a style from the list."))
            style = ""

    # ── cable type, phase, installation: closed vocabularies ────────────────
    for key, vocabulary, label in (
        ("cableType", CABLE_TYPES, "a cable type"),
        ("phase", PHASES, "a phase"),
        ("install", INSTALL_METHODS, "an installation method"),
    ):
        stated, value = _number(payload, key)
        if stated and (value is None or not value.is_integer() or int(value) not in vocabulary):
            errors.append(FieldError(key, f"Choose {label} from the list."))

    # ── core count ───────────────────────────────────────────────────────────
    stated, cores = _number(payload, "cores")
    if stated:
        if cores is None or not cores.is_integer():
            errors.append(FieldError("coreCount", "Core count must be a whole number."))
        elif cores < 1:
            errors.append(FieldError("coreCount", "Core count must be at least 1."))
        elif max_cores and cores > max_cores:
            errors.append(FieldError("coreCount", f"We don't make cables with more than {max_cores} cores."))
        elif style and style_cores.get(style) == frozenset({1}) and cores > 1:
            errors.append(FieldError("coreCount", f"{style} is single core only."))

    # ── cross-section ────────────────────────────────────────────────────────
    stated, size = _number(payload, "crossSection")
    if stated:
        if size is None or size <= 0:
            errors.append(FieldError("crossSection", "Cross-section must be greater than zero."))
        elif not any(abs(size - known) < 1e-6 for known in sizes):
            below = [s for s in sizes if s < size]
            above = [s for s in sizes if s > size]
            nearest = " or ".join(_size_label(s) for s in (below[-1:] + above[:1]))
            errors.append(FieldError(
                "crossSection",
                f"{_size_label(size)} is not a standard conductor size"
                + (f". Nearest: {nearest}." if nearest else "."),
            ))

    # ── electrical duty ──────────────────────────────────────────────────────
    for key, field_name, label in (("load", "loadAmps", "Load"), ("length", "lengthM", "Run length")):
        stated, value = _number(payload, key)
        if stated and (value is None or value <= 0):
            errors.append(FieldError(field_name, f"{label} must be greater than zero."))

    stated, voltage = _number(payload, "voltage")
    if stated and (voltage is None or voltage <= 0 or voltage > MAX_VOLTAGE):
        errors.append(FieldError("voltage", f"Voltage must be between 1 and {MAX_VOLTAGE:,} V."))

    stated, ambient = _number(payload, "ambient")
    low, high = AMBIENT_RANGE_C
    if stated and (ambient is None or ambient < low or ambient > high):
        errors.append(FieldError("ambient", f"Ambient temperature must be between {low} and {high} °C."))

    return errors
