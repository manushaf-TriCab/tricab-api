# Generated from the RFQ parser's matcher/table_units.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Header/unit reading for spreadsheet-derived quantity columns.

Split out of ``file_parser`` so the pricing path can use it without importing
that module: ``file_parser`` pulls in pdfplumber and openpyxl (~0.3s of import),
and the PHP bridge pays every import again on every request. Nothing here needs
more than ``re``.

``file_parser`` re-exports these names, so existing
``from rfq_parser.file_parser import ...`` callers are unaffected.
"""

from __future__ import annotations

import re


def normalize_header(header: str) -> str:
    # Trailing punctuation ("Cable No.", "Length:") must not defeat the exact
    # set-membership checks in file_parser; internal periods ("cat. no", "s.n") stay.
    value = re.sub(r"\s+", " ", str(header or "").replace("\n", " ").strip().lower())
    return value.strip(" .:")


def unit_from_header(header: str) -> str:
    value = str(header or "").lower()
    match = re.search(r"\(([^)]+)\)|\[([^\]]+)\]", value)
    if match:
        bracket_unit = (match.group(1) or match.group(2) or "").strip().lower()
        if "metre" in bracket_unit or "meter" in bracket_unit or bracket_unit in {"m", "in m"}:
            return "m"
        if "feet" in bracket_unit or "foot" in bracket_unit or bracket_unit in {"ft", "in ft"}:
            return "ft"
        return bracket_unit
    if "metre" in value or "meter" in value or value.endswith(" m"):
        return "m"
    if "feet" in value or "foot" in value or " ft" in value:
        return "ft"
    return ""


_METRE_VALUE_RE = re.compile(r"^\s*\d+(?:\.\d+)?\s*(?:m|mtrs?|metres?|meters?|lm)\s*$", re.I)
_METRE_HEADER_RE = re.compile(r"\blength\b|\bmetres?\b|\bmeters?\b|\bmtrs?\b|\blm\b", re.I)
_COUNT_HEADER_RE = re.compile(r"\bno\.?\s*of\b|\bnumber\s+of\b|\bqty\b|\bquantity\b|\bcount\b", re.I)


def is_metre_valued(header: str, value: str = "") -> bool:
    """Whether this column carries a length in metres rather than a count.

    A cable schedule that gives both ("Qty 4 | Length 55m") means four cuts of
    55 m. The metre-carrying column is the length; the other number is the count.
    Read from the cell first -- "55m" is unambiguous whatever the header says --
    then fall back to the header ("Length", "Cable Length (m)", "Total Metres").
    """
    if _METRE_VALUE_RE.match(str(value or "")):
        return True
    header = str(header or "")
    norm = normalize_header(header)
    # A header that counts items ("No. of Cables without Est. Length") is a count
    # even when the word "length" appears in it -- don't read it as metres.
    if _COUNT_HEADER_RE.search(norm):
        return False
    if unit_from_header(header) == "m":
        return True
    return bool(_METRE_HEADER_RE.search(norm))
