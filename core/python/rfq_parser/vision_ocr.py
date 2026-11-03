# Generated from the RFQ parser's matcher/vision_ocr.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
from __future__ import annotations

from . import model_settings

import base64
import json
import os
import re
import urllib.error
import urllib.request
from dataclasses import dataclass, field
from typing import Any

from rfq_parser.llm_fallback import _float_env, _loads_json_object, _openrouter_chat, openrouter_enabled
from tricab_cable_rules.normalize import int_if_whole


DEFAULT_PRIMARY_MODEL = "claude-haiku-5-5"
DEFAULT_ESCALATION_MODEL = "claude-sonnet-5-5"
ALLOWED_IMAGE_TYPES = {"image/png", "image/jpeg", "image/webp"}
IMAGE_TYPE_ALIASES = {
    "image/jpg": "image/jpeg",
    "image/pjpeg": "image/jpeg",
    "image/x-png": "image/png",
}


@dataclass
class VisionOcrResult:
    ok: bool
    model: str
    rows: list[dict[str, Any]]
    text: str
    confidence: float
    escalated: bool = False
    error: str = ""
    columns: list[str] = field(default_factory=list)
    has_length_source: bool = True


def vision_ocr_enabled() -> bool:
    disabled = os.environ.get("VISION_OCR_ENABLED", "true").strip().lower() in {"0", "false", "no", "off"}
    return openrouter_enabled() and not disabled


def extract_table_with_qwen(image_bytes: bytes, mime_type: str) -> VisionOcrResult:
    mime_type = normalize_image_type(image_bytes, mime_type)
    primary = model_settings.get("RFQ_VISION_MODEL", DEFAULT_PRIMARY_MODEL)
    escalation = model_settings.get("RFQ_VISION_ESCALATION_MODEL", DEFAULT_ESCALATION_MODEL)
    threshold = _float_env("VISION_OCR_ESCALATION_THRESHOLD", 0.82)

    if mime_type not in ALLOWED_IMAGE_TYPES:
        return VisionOcrResult(False, primary, [], "", 0, error=f"Unsupported image type: {mime_type}")
    image_url = f"data:{mime_type};base64,{base64.b64encode(image_bytes).decode('ascii')}"

    first = _request_vision_ocr(image_url, primary)
    if not escalation or escalation == primary:
        return first
    uncertain = first.ok and any(
        bool(row.get("uncertain")) or _row_missing_required_length(row, first.has_length_source) for row in first.rows
    )
    if first.ok and first.confidence >= threshold and not uncertain:
        return first

    second = _request_vision_ocr(image_url, escalation)
    second.escalated = True
    if second.ok:
        return second
    if not first.ok:
        return second
    first.error = f"Escalation failed: {second.error}"
    return first


def _request_vision_ocr(image_url: str, model: str) -> VisionOcrResult:
    if not vision_ocr_enabled():
        return VisionOcrResult(False, model, [], "", 0, error="Vision OCR is not configured.")

    payload = {
        "model": model,
        "messages": [
            {
                "role": "system",
                "content": (
                    "You transcribe RFQ tables from screenshots of spreadsheets, drawings, and documents. "
                    "You are a transcriber, not an interpreter: report the printed content of the table and nothing else. "
                    "Ignore application chrome, filenames, buttons, and blank rows.\n"
                    "1. COLUMNS. Find the table's header row and copy its cells verbatim, left to right, into `columns`. "
                    "Include every column, even ones that look unimportant or empty. Do not rename a header, do not "
                    "translate it into a term you expect, and do not add a column that is not printed. If the content "
                    "genuinely has no header row, return an empty `columns` list.\n"
                    "2. CELLS. For every body row, fill `cells` with exactly one entry per column, in the same left-to-right "
                    "order, transcribed verbatim. Use an empty string for a blank cell so the entries stay aligned with "
                    "`columns`. Never move a value into a neighbouring column, never merge two columns into one entry, "
                    "never split one cell across two entries, and never carry a value down from the row above or up from "
                    "the row below. Re-read the row against its own horizontal band before committing it.\n"
                    "3. NEVER INVENT. Report only properties that are actually printed. If the table has no Neutral, Earth, "
                    "Insulation, Voltage, Sheath, or Configuration/Formation column, then those properties do not exist for "
                    "this RFQ -- do not supply them from a typical cable schedule, and do not restate another RFQ's layout. "
                    "Do not normalise a value into what a cable schedule usually says: if the conductor cell says 'Al', "
                    "never write 'Cu'; if the arrangement cell says '4 x 1 Core', never write '3 x 1 core Trefoil'. "
                    "Likewise never merge a count from one column with a count from another column. An empty cell stays "
                    "an empty string.\n"
                    "4. `description` is only for content with no header row; when `columns` is non-empty leave it empty "
                    "and put everything in `cells`.\n"
                    "5. LENGTH. `length` is the quantity of cable requested, and only that. Set it when the row states one: "
                    "either under a column whose header names a length or quantity with a unit (Length, Length (m), Qty (m), "
                    "Total Metres), or as a number with a unit token (M, METERS, METRES, FT) printed beside it. A "
                    "cross-section, size, or diameter column is NOT a length: 300 under 'Active (mm2)' is a 300mm2 "
                    "conductor and 150 under 'Conduit Size' is a 150mm duct, not 300 or 150 metres. A bare number under "
                    "Qty, No of Sets, Pack, Price, or Rate with no unit printed anywhere is NOT a length. If the table has "
                    "no length column at all, leave `length` and `unit` null on every row -- that is a correct answer, not "
                    "a failure. If a length exists but cannot be read, use null and mark the row uncertain.\n"
                    "6. Inherit a section heading that sits above a group of rows (such as control cable or power cable), "
                    "but never copy a value that belongs to a single row onto other rows.\n"
                    "7. A short flag shown in its own column or in a distinct colour, such as a red FR or Fire Rated mark, "
                    "applies ONLY to the row it appears on: set fire_rated true for that row. Leave fire_rated false for "
                    "every row that has no such mark; most rows are not fire rated.\n"
                    "8. If a column holds a product, part, catalog, or item code (such as XL-PAXN/1C25OR, B06-AEXX/10H50, "
                    "or LP-XXXA/1C120EA), transcribe it verbatim into the `code` field as well as into its cell. Never "
                    "drop such a code. A route or tag reference such as C1, C12, or MSB-D1 is not a product code; leave it "
                    "in its cell only.\n"
                    "9. Mark a row uncertain when any cell is doubtful or you could not keep its cells aligned to the "
                    "columns. Preserve cable notation such as 4C+E, 1Pr OS, mm2, PVC, Cu, and Al exactly as printed. "
                    "Keep the JSON compact. Do not add explanations, notes, or commentary. Return only the requested JSON."
                ),
            },
            {
                "role": "user",
                "content": [
                    {
                        "type": "text",
                        "text": (
                            "Transcribe this RFQ image. First list the header row verbatim in `columns`, then give one "
                            "row object per body row with `cells` aligned one-to-one to those columns. Transcribe only "
                            "what is printed -- no column, property, or value that the image does not show. Set "
                            "`length`/`unit` only for a real requested cable length, never for a cross-section, conduit "
                            "size, or unit-less Qty number. Set fire_rated true only for rows carrying their own FR or "
                            "Fire Rated mark. Mark a row uncertain when any cell or cell-to-column association is doubtful."
                        ),
                    },
                    {"type": "image_url", "image_url": {"url": image_url}},
                ],
            },
        ],
        "max_tokens": int(_float_env("VISION_OCR_MAX_TOKENS", 9000)),
        "response_format": {
            "type": "json_schema",
            "json_schema": {
                "name": "rfq_image_rows",
                "strict": True,
                "schema": _vision_schema(),
            },
        },
    }
    data, error = _openrouter_chat(payload, timeout=_float_env("VISION_OCR_TIMEOUT_SECONDS", 45))
    if error:
        return VisionOcrResult(False, model, [], "", 0, error=error)

    try:
        choice = data["choices"][0]
        content = choice["message"]["content"]
        if choice.get("finish_reason") == "length":
            return VisionOcrResult(
                False,
                data.get("model") or model,
                [],
                "",
                0,
                error="Vision response was truncated before all rows were returned.",
            )
        parsed = _loads_json_object(content)
        columns = [re.sub(r"\s+", " ", str(header or "")).strip() for header in (parsed.get("columns") or [])]
        rows = [_normalize_row(row, columns) for row in parsed.get("rows", []) if isinstance(row, dict)]
        rows = [row for row in rows if row["description"]]
        confidence = max(0.0, min(1.0, float(parsed.get("overall_confidence") or 0)))
        if not rows:
            return VisionOcrResult(False, data.get("model") or model, [], "", confidence, error="No RFQ rows found.")
        text = "\n".join(_row_text(row) for row in rows)
        return VisionOcrResult(
            True,
            data.get("model") or model,
            rows,
            text,
            confidence,
            columns=columns,
            has_length_source=_table_states_lengths(columns, rows),
        )
    except (KeyError, TypeError, ValueError, json.JSONDecodeError) as exc:
        return VisionOcrResult(
            False,
            model,
            [],
            "",
            0,
            error=f"Could not parse vision response; it may have been truncated or malformed: {exc}",
        )


def public_result(result: VisionOcrResult) -> dict[str, Any]:
    return {
        "ok": result.ok,
        "model": result.model,
        "columns": result.columns,
        "rows": result.rows,
        "text": result.text,
        "confidence": result.confidence,
        "escalated": result.escalated,
        "error": result.error,
    }


def health() -> dict[str, Any]:
    return {
        "enabled": vision_ocr_enabled(),
        "primary_model": model_settings.get("RFQ_VISION_MODEL", DEFAULT_PRIMARY_MODEL),
        "escalation_model": model_settings.get("RFQ_VISION_ESCALATION_MODEL", DEFAULT_ESCALATION_MODEL),
        "escalation_threshold": _float_env("VISION_OCR_ESCALATION_THRESHOLD", 0.82),
        "max_tokens": int(_float_env("VISION_OCR_MAX_TOKENS", 9000)),
        "max_image_mb": _float_env("VISION_OCR_MAX_IMAGE_MB", 8),
    }


def normalize_image_type(image_bytes: bytes, supplied_type: str | None) -> str:
    mime_type = str(supplied_type or "").split(";", 1)[0].strip().lower()
    mime_type = IMAGE_TYPE_ALIASES.get(mime_type, mime_type)
    if mime_type in ALLOWED_IMAGE_TYPES:
        return mime_type
    if image_bytes.startswith(b"\x89PNG\r\n\x1a\n"):
        return "image/png"
    if image_bytes.startswith(b"\xff\xd8\xff"):
        return "image/jpeg"
    if len(image_bytes) >= 12 and image_bytes[:4] == b"RIFF" and image_bytes[8:12] == b"WEBP":
        return "image/webp"
    return mime_type


# Header words that mean a column holds a physical size, not a length to supply:
# "Active (mm2)" is a conductor cross-section and "Conduit Size" is a duct bore.
# Reading either as a requested length invents cable that nobody asked for.
_SIZE_HEADER_RE = re.compile(
    r"\b(?:mm2|mm²|mm|sq\.?\s*mm|csa|cross[-\s]?sections?|size|dia|diameter|conduit|"
    r"active|neutral|earth|cpc|phase|sets?|cores?|awg)\b",
    re.I,
)
# Header words that mean a column holds the quantity of cable requested.
_LENGTH_HEADER_RE = re.compile(
    r"\b(?:length|lgth|lgt|qty|quantity|metres?|meters?|mtrs?|lin\.?\s*m|feet|ft|total)\b|\(\s*m\s*\)",
    re.I,
)
# A number with a real length unit printed against it, inside a cell.
_CELL_LENGTH_UNIT_RE = re.compile(r"\d\s*(?:m|lm|mtrs?|metres?|meters?|ft|feet)\b", re.I)
# A header ending in a parenthesised unit, e.g. "Active (mm2)" or "Length (m)".
_HEADER_UNIT_RE = re.compile(r"^(?P<name>.*?)\s*\(\s*(?P<unit>mm2|mm²|mm|m|metres?|meters?|ft|kv|v|a)\s*\)$", re.I)


def _is_length_header(header: str) -> bool:
    return bool(_LENGTH_HEADER_RE.search(header)) and not _SIZE_HEADER_RE.search(header)


def _label_cell(header: str, value: str) -> str:
    """Render one cell as labelled text, folding a parenthesised header unit onto a
    bare number so "Active (mm2)" + "300" reads as "Active 300mm2" rather than as a
    naked 300 the matcher could mistake for a length."""
    label = header.strip().strip(":")
    if not label:
        return value
    if value.lower() == label.lower():
        return value
    unit_match = _HEADER_UNIT_RE.match(label)
    if unit_match and re.fullmatch(r"\d+(?:\.\d+)?", value):
        return f"{unit_match.group('name').strip()} {value}{unit_match.group('unit').lower()}".strip()
    return f"{label} {value}"


def _description_from_cells(columns: list[str], cells: list[str]) -> str:
    """Build the matcher line from the row's own cells, labelled by their real
    headers. Assembling this here -- instead of asking the model for a prose
    description -- is what keeps a table's actual columns (and only those) in the
    output; the model no longer has a free-text field to fill with a remembered
    cable-schedule layout."""
    parts = [_label_cell(header, cell.strip()) for header, cell in zip(columns, cells) if cell.strip()]
    return ", ".join(part for part in parts if part)


def _normalize_row(row: dict[str, Any], columns: list[str]) -> dict[str, Any]:
    raw_cells = [re.sub(r"\s+", " ", str(cell or "")).strip() for cell in (row.get("cells") or []) if cell is not None]
    uncertain = bool(row.get("uncertain"))
    if columns:
        if len(raw_cells) != len(columns):
            # Misaligned cells mean values have shifted columns; flag for escalation
            # rather than silently pairing the wrong header with the wrong value.
            uncertain = True
        raw_cells = (raw_cells + [""] * len(columns))[: len(columns)]
        description = _description_from_cells(columns, raw_cells)
    else:
        raw_cells = []
        description = ""
    if not description:
        description = re.sub(r"\s+", " ", str(row.get("description") or "")).strip()

    normalized = {
        "section": re.sub(r"\s+", " ", str(row.get("section") or "")).strip(),
        "code": re.sub(r"\s+", " ", str(row.get("code") or "")).strip(),
        "cells": raw_cells,
        "description": description,
        "length": int_if_whole(row.get("length")),
        "unit": _normalize_unit(row.get("unit")),
        "fire_rated": bool(row.get("fire_rated")),
        "confidence": max(0.0, min(1.0, float(row.get("confidence") or 0))),
        "uncertain": uncertain,
        "notes": "",
    }
    _drop_unsupported_length(normalized, columns)
    return normalized


def _drop_unsupported_length(row: dict[str, Any], columns: list[str]) -> None:
    """Clear a length the table does not actually state.

    Vision models like to promote the biggest number on a row to "the length" --
    that is how a 300mm2 active conductor became "300m of cable". Once the header
    row is known this is decidable: keep the length only if some column really is a
    length/quantity column, or some cell prints a length unit next to its number."""
    if row["length"] is None or not columns:
        return
    if _row_states_a_length(columns, row["cells"]):
        return
    row["length"] = None
    row["unit"] = ""


def _row_states_a_length(columns: list[str], cells: list[str]) -> bool:
    if any(_is_length_header(header) for header in columns):
        return True
    return any(_CELL_LENGTH_UNIT_RE.search(cell) for cell in cells)


def _table_states_lengths(columns: list[str], rows: list[dict[str, Any]]) -> bool:
    """Whether this table has anywhere for a requested length to come from. A cable
    schedule that only lists routes and sizes legitimately has none, and must not be
    escalated (or flagged) for "missing" lengths that were never printed."""
    if not columns:
        return True
    return any(_row_states_a_length(columns, row.get("cells") or []) for row in rows)


def _row_text(row: dict[str, Any]) -> str:
    description = row["description"]
    code = row.get("code") or ""
    if code and code.lower() not in description.lower():
        # Keep the product/part code as an exact-match signal at the front of the line.
        description = f"{code} {description}"
    length = row.get("length")
    unit = row.get("unit") or ""
    if length is not None and not _description_has_requested_length(description, length, unit):
        description = f"{description} {length:g}{unit}" if isinstance(length, float) else f"{description} {length}{unit}"
    if row.get("fire_rated") and not re.search(r"\bfire\b", description, re.I):
        # Drive the fire-rated signal from the per-row flag, not from stray "FR" text.
        description = f"{description} Fire Rated"
    section = row["section"]
    if section and section.lower() not in description.lower():
        return f"{description} {section}"
    return description


def _row_missing_required_length(row: dict[str, Any], table_states_lengths: bool = True) -> bool:
    if not table_states_lengths:
        return False  # the table prints no lengths at all; nothing is missing
    return _looks_like_cable_row(row.get("description", "")) and row.get("length") is None


def _looks_like_cable_row(description: str) -> bool:
    return bool(re.search(r"\b(?:\d+(?:\.\d+)?\s*mm2?|\d+\s*C(?:\s*\+\s*E)?|Cu|Al|PVC|XLPE|cable)\b", description, re.I))


def _description_has_requested_length(description: str, length: int | float, unit: str) -> bool:
    number = re.escape(str(length))
    if isinstance(length, float) and length.is_integer():
        number = re.escape(str(int(length)))
    if unit == "m":
        unit_pattern = r"(?:m|meters?|metres?)"
    elif unit == "ft":
        unit_pattern = r"(?:ft|feet|foot)"
    else:
        unit_pattern = re.escape(unit) if unit else r"(?:m|ft|meters?|metres?|feet|foot)"
    return bool(re.search(rf"\b{number}\s*{unit_pattern}\b", description, re.I))


def _normalize_unit(value: Any) -> str:
    text = str(value or "").strip().lower()
    if text in {"m", "meter", "meters", "metre", "metres"}:
        return "m"
    if text in {"ft", "foot", "feet"}:
        return "ft"
    return text


def _vision_schema() -> dict[str, Any]:
    return {
        "type": "object",
        "additionalProperties": False,
        "properties": {
            "overall_confidence": {"type": "number"},
            "columns": {"type": "array", "items": {"type": "string"}},
            "rows": {
                "type": "array",
                "items": {
                    "type": "object",
                    "additionalProperties": False,
                    "properties": {
                        "section": {"type": "string"},
                        "code": {"type": "string"},
                        "cells": {"type": "array", "items": {"type": "string"}},
                        "description": {"type": "string"},
                        "length": {"type": ["number", "null"]},
                        "unit": {"type": ["string", "null"]},
                        "fire_rated": {"type": "boolean"},
                        "confidence": {"type": "number"},
                        "uncertain": {"type": "boolean"},
                    },
                    "required": [
                        "section",
                        "code",
                        "cells",
                        "description",
                        "length",
                        "unit",
                        "fire_rated",
                        "confidence",
                        "uncertain",
                    ],
                },
            },
        },
        "required": ["overall_confidence", "columns", "rows"],
    }
