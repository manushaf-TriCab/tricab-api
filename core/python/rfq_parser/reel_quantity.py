# Generated from the RFQ parser's matcher/reel_quantity.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
from __future__ import annotations

import re
from typing import Any


# "400m/reel", "400 per drum" -- an explicit separator, so the metre unit is optional.
# "roll" and "coil" join reel/spool/drum: a stock order writes the same thing as
# "100m ROLL" in a UOM column (Dulan's September flex order).
PER_REEL_LENGTH_RE = re.compile(
    r"\b(?P<length>\d+(?:\.\d+)?)\s*(?:m\s*)?(?:/|per\s+)\s*(?:reels?|spools?|drums?|rolls?|coils?)\b",
    re.I,
)

# The juxtaposed form, with no separator at all ("100m ROLL"). It REQUIRES the metre
# unit -- with the "m" the number is a length per package, without it "3 ROLLS" is a
# count of packages and reading 3 as a length would underquote by the packet size.
#
# It is also **opt-in per call site**, which the metre unit alone does not make safe.
# A description routinely names the package size in passing -- a real PO in the corpus
# reads `23mm Black Split Loom Tube PP (50m roll)` with `100m (2 rolls)` in its own Qty
# column -- and there the quantity beside it is already metres, not a count of rolls.
# Honouring the juxtaposed form there re-read a correct 100 m order as 50 m. So only the
# caller that knows it is looking at a package-size cell (the UOM column) passes
# allow_juxtaposed=True; the description path, which cannot know, does not.
PER_REEL_JUXTAPOSED_RE = re.compile(
    r"\b(?P<length>\d+(?:\.\d+)?)\s*m\s+(?:reels?|spools?|drums?|rolls?|coils?)\b",
    re.I,
)
PIPE_PER_REEL_QUANTITY_RE = re.compile(
    r"^(?P<line>.*\b(?P<length>\d+(?:\.\d+)?)\s*(?:m\s*)?(?:/|per\s+)\s*"
    r"(?:reels?|spools?|drums?)\b[^|]*)\|\s*(?P<count>\d+(?:\.\d+)?)\s*$",
    re.I,
)
NUMBER_RE = re.compile(r"-?\d+(?:\.\d+)?")


def number_from_text(value: Any) -> int | float | None:
    match = NUMBER_RE.search(str(value or "").replace(",", ""))
    if not match:
        return None
    number = float(match.group(0))
    return int(number) if number.is_integer() else number


def per_reel_length(value: Any, *, allow_juxtaposed: bool = False) -> int | float | None:
    """The metres one package holds. ``allow_juxtaposed`` opts in to the separator-less
    ``100m ROLL`` spelling -- see PER_REEL_JUXTAPOSED_RE for why that is not the default."""
    text = str(value or "")
    matches = list(PER_REEL_LENGTH_RE.finditer(text))
    if allow_juxtaposed:
        matches += list(PER_REEL_JUXTAPOSED_RE.finditer(text))
    if not matches:
        return None
    return number_from_text(max(matches, key=lambda m: m.start()).group("length"))


def per_reel_metadata(
    description: Any,
    reel_count: Any,
    *,
    quantity_header: str = "per reel",
    quantity_raw: str | None = None,
    allow_juxtaposed: bool = False,
) -> dict[str, Any]:
    length = per_reel_length(description, allow_juxtaposed=allow_juxtaposed)
    count = number_from_text(reel_count)
    if length is None or count is None:
        return {}
    try:
        if float(length) <= 0 or float(count) <= 0:
            return {}
    except (TypeError, ValueError):
        return {}
    return {
        "table_quantity": length,
        "table_quantity_raw": quantity_raw or str(length),
        "table_quantity_header": quantity_header,
        "table_unit": "m",
        "number_of_lengths": count,
        "number_of_lengths_raw": str(reel_count),
        "quantity_basis": "per_reel",
    }


def extract_pipe_per_reel_quantity(
    raw_text: Any,
    metadata: dict[str, Any] | None,
) -> tuple[str, dict[str, Any] | None]:
    match = PIPE_PER_REEL_QUANTITY_RE.match(str(raw_text or ""))
    if not match:
        return raw_text, metadata
    reel_meta = per_reel_metadata(
        match.group("line"),
        match.group("count"),
        quantity_header="per reel",
        quantity_raw=f"{match.group('length')}/reel",
    )
    if not reel_meta:
        return raw_text, metadata
    new_meta = dict(metadata or {})
    new_meta.update(reel_meta)
    return match.group("line").rstrip(), new_meta
