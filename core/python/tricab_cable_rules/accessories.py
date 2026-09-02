from __future__ import annotations

import re
from typing import Any


ACCESSORY_CODE_RE = re.compile(
    r"(?<![A-Z0-9])"
    r"([A-Z0-9]{3}-[A-Z][A-Z0-9][A-Z]{2}/[0-9A-Z.]+[A-Z][0-9X.]+[A-Z]{0,2})"
    r"(?![A-Z0-9])",
    re.I,
)

_ACCESSORY_CODE_FULL_RE = re.compile(
    r"^([A-Z0-9]{3})-([A-Z])([A-Z0-9])([A-Z]{2})/"
    r"([0-9A-Z.]+)([A-Z])([0-9X.]+)([A-Z]{0,2})$",
    re.I,
)

PACK_SIZES = {
    "A": 1,
    "B": 2,
    "C": 4,
    "D": 5,
    "E": 25,
    "F": 3,
    "G": 10,
    "H": 20,
    "I": 6,
    "J": 8,
    "K": 50,
    "L": 30,
    "M": 100,
    "N": 250,
    "P": 1000,
    "Q": 500,
    "R": 200,
    "S": 12,
    "T": 300,
    "1": 12,
    "2": 16,
    "3": 18,
    "4": 22,
    "5": 24,
    "6": 34,
    "7": 36,
    "8": 50,
    "9": 56,
}

SERIES_PACK_LABELS = {
    "A": "bag",
    "B": "bag",
    "D": "bag",
    "E": "box",
    "F": "bag",
    "G": "reel",
    "H": "box",
    "J": "bag",
    "S": "bag",
    "T": "box",
    "U": "item",
    "W": "bag",
    "X": "bag",
    "Y": "pack",
    "Z": "pack",
}

SERIES_UNIT_LABELS = {
    "A": "terminal",
    "B": "lug",
    "D": "gland",
    "E": "timer",
    "F": "gland",
    "G": "metre",
    "H": "duct",
    "J": "socket",
    "M": "clamp",
    "S": "lockout",
    "T": "insulator",
    "U": "item",
    "W": "tie",
    "X": "mount",
    "Y": "roll",
    "Z": "tool",
}

STYLE_OVERRIDES = {
    "A20": {"unit_label": "kit", "pack_label": "pack"},
    "A40": {"unit_label": "reel", "pack_label": "box"},
    "A50": {"unit_label": "box", "pack_label": "pack"},
    "A51": {"unit_label": "bar", "pack_label": "box"},
    "F07": {"unit_label": "metre", "pack_label": "roll"},
    "F20": {"unit_label": "metre", "pack_label": "roll"},
    "F21": {"unit_label": "metre", "pack_label": "roll"},
    "F22": {"unit_label": "metre", "pack_label": "roll"},
    "F25": {"unit_label": "metre", "pack_label": "roll"},
    "G03": {"unit_label": "pc", "pack_label": "pack"},
    "G06": {"unit_label": "", "pack_label": "pack"},
    "G07": {"unit_label": "", "pack_label": "pack"},
    "G08": {"unit_label": "", "pack_label": "pack"},
    "G10": {"unit_label": "breakout", "pack_label": "bag"},
    "G12": {"unit_label": "qty", "pack_label": "pack"},
    "J16": {"unit_label": "switch", "pack_label": "box"},
    "J17": {"unit_label": "isolator", "pack_label": "box"},
    "J18": {"unit_label": "switch", "pack_label": "box"},
    "J19": {"unit_label": "inlet", "pack_label": "box"},
    "LKEN": {"unit_label": "kit", "pack_label": "pack", "units_per_pack": 1},
    "LL1E": {"unit_label": "lead", "pack_label": "pack"},
    "LLA1": {"unit_label": "lead", "pack_label": "pack"},
    "EBL": {"unit_label": "lead", "pack_label": "box"},
    "TC12": {"unit_label": "spool", "pack_label": "pack"},
    "U01": {"unit_label": "tube"},
    "X07": {"unit_label": "unit", "pack_label": "box"},
    "X08": {"unit_label": "unit", "pack_label": "box"},
    "X12": {"unit_label": "qty", "pack_label": "bag"},
    "X13": {"unit_label": "qty", "pack_label": "bag"},
    "Y02": {"unit_label": "tape", "pack_label": "box"},
    "Y03": {"unit_label": "tape", "pack_label": "box"},
    "Z29": {"unit_label": "box", "units_per_pack": 1},
    "Z33": {"unit_label": "set", "units_per_pack": 1},
    "Z35": {"unit_label": "set", "units_per_pack": 1},
    "Z36": {"unit_label": "set", "units_per_pack": 1},
}


def find_accessory_code(text: str) -> str | None:
    match = ACCESSORY_CODE_RE.search(str(text or "").upper())
    return match.group(1).upper() if match else None


def parse_accessory_code(code: str) -> dict[str, Any] | None:
    normalized = str(code or "").strip().upper()
    match = _ACCESSORY_CODE_FULL_RE.match(normalized)
    if not match:
        return None

    style, code_prefix, pack_code, extra_code, x_size, dimension_code, y_size, colour = match.groups()
    series = style[:1]
    metadata = {
        "product_code": normalized,
        "style": style,
        "series": series,
        "code_prefix": code_prefix,
        "pack_code": pack_code,
        "code_extra": extra_code,
        "x_size": x_size,
        "dimension_code": dimension_code,
        "y_size": y_size,
        "colour": colour,
        "units_per_pack": PACK_SIZES.get(pack_code, 1),
        "pack_label": SERIES_PACK_LABELS.get(series, "pack"),
        "unit_label": SERIES_UNIT_LABELS.get(series, "unit"),
    }
    metadata.update(STYLE_OVERRIDES.get(style, {}))
    if colour == "RP" and series == "Y":
        metadata["unit_label"] = "pack"
    return metadata


def accessory_quantity_from_text(text: str, code: str | None = None) -> float | int | None:
    raw = str(text or "")
    upper = raw.upper()
    patterns: list[str] = []
    if code:
        escaped = re.escape(code.upper())
        patterns.extend(
            [
                rf"^\s*(\d+(?:\.\d+)?)\s*(?:X|x|×|NO\.?|NOS\.?|QTY|EA|EACH|PCS?|UNITS?)\s+{escaped}\b",
                rf"\b{escaped}\b\s*(?:X|x|×|QTY|QUANTITY|EA|EACH|PCS?|UNITS?)\s*(\d+(?:\.\d+)?)\b",
            ]
        )
    patterns.extend(
        [
            r"\b(?:QTY|QUANTITY)\D{0,8}(\d+(?:\.\d+)?)\b",
            r"\|\s*(\d+(?:\.\d+)?)\s*\|\s*(?:EA|EACH|UNIT|UNITS|PCS?)\b",
            r"^\s*(\d+(?:\.\d+)?)\s*(?:X|x|×|NO\.?|NOS\.?|QTY|EA|EACH|PCS?|UNITS?)\b",
            r"\b(\d+(?:\.\d+)?)\s*(?:EA|EACH|PCS?|UNITS?)\b",
        ]
    )
    for pattern in patterns:
        match = re.search(pattern, upper, re.I)
        if match:
            return _clean_number(match.group(1))
    return None


def _clean_number(value):
    number = float(value)
    return int(number) if number.is_integer() else number
