# Generated from the RFQ parser's matcher/splitter.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
from __future__ import annotations

import re
from dataclasses import dataclass

from tricab_cable_rules.normalize import normalize_text


# Unit spellings kept in step with matcher.extractor.SQ_MM -- a size the splitter
# cannot see is a line it will not split (docs/EMAIL_CORPUS_AUDIT.md 4.5).
_SQ_MM = r"(?:sq\.?\s*mm|mm2|mm\b)"
SIZE_RE = re.compile(r"\b\d+(?:\.\d+)?\s*" + _SQ_MM, re.I)
CORE_RE = re.compile(r"\b\d+\s*C\b", re.I)
LEADING_QTY_RE = re.compile(r"^\s*\d+\s*x\s+(?=\d+\s*C\b|\d+(?:\.\d+)?\s*" + _SQ_MM + r")", re.I)
EARTH_RE = re.compile(r"\b(?:earth|ecc|g/y|gn\s*/\s*ye|green\s*/?\s*yellow)\b", re.I)
MATERIAL_RE = re.compile(r"\b(?:cu|copper|al|alu|aluminium|aluminum)\b", re.I)
CABLE_HINT_RE = re.compile(r"\b(?:fire|rated|xlpe|pvc|flex|vsd|vfd|swa|awa|screen|sdi|x-?hf|x-?90|lszh)\b", re.I)
# An earth quoted as its own line item: it carries its own conductor size, or is spelled
# "Earth" with a quantity/size. A bare "E"/"3E" attached to a core count (4C+E, 3C+3E) is
# the cable's own earth and is deliberately NOT matched here.
SEPARATE_EARTH_RE = re.compile(
    r"\b\d+\s*C\s*\d+(?:\.\d+)?\s*(?:mm2|mm)\s*(?:cu\s+)?(?:e\b|earth\b)"  # 1C 10mm2 [Cu] E/Earth
    r"|\b\d+(?:\.\d+)?\s*(?:mm2|mm)\s*(?:cu\s+)?earth\b"                   # 10mm2 [Cu] Earth
    r"|\b\d+\s+earth\b",                                                  # 1 Earth
    re.I,
)


# What can join two cables on one row. "+" is the shorthand form ("4C 16mm2 + 1C 10mm2 E");
# "and"/"&" is the prose form ("4 drums of 400mm XLPE and 1 drum of 120mm earth"), which
# arrives whenever someone types their RFQ as a sentence instead of a table.
#
# "and" is far weaker evidence than "+" -- it joins adjectives ("XLPE and PVC sheathed") as
# happily as it joins cables -- so it is not trusted on its own. Every separator, whichever
# it is, must still convince should_split() that BOTH sides independently describe a cable.
SEPARATOR_RE = re.compile(r"\+|\s+and\s+|\s+with\s+|\s*&\s*", re.I)


@dataclass(frozen=True)
class SplitEntry:
    text: str
    entry_index: int
    split_reason: str = ""


def split_compound_line(raw_text: str) -> list[SplitEntry]:
    """Split one customer RFQ row into quoteable entries when it contains multiple cables.

    This deliberately avoids splitting core expressions such as 3C+E, 4C + E, and
    3C+3E. A separator only counts when the sides either side of it each look like an
    independent cable requirement.
    """

    text = normalize_text(raw_text)
    parts, kinds = split_on_independent_separator(text)
    if len(parts) <= 1:
        return [SplitEntry(text=text, entry_index=1)]

    reason = _split_reason(kinds)
    return [
        SplitEntry(text=part.strip(), entry_index=index + 1, split_reason=reason)
        for index, part in enumerate(parts)
        if part.strip()
    ]


def split_on_independent_separator(text: str) -> tuple[list[str], set[str]]:
    """The independent cable requirements in ``text``, plus the separator kinds that split
    them. A separator the guard rejects is left in place, as part of the surrounding text."""
    parts: list[str] = []
    kinds: set[str] = set()
    start = 0

    for match in SEPARATOR_RE.finditer(text):
        left = text[start : match.start()].strip()
        right = text[match.end() :].strip()
        if should_split(left, right):
            parts.append(left)
            kinds.add(_separator_kind(match.group()))
            start = match.end()

    parts.append(text[start:].strip())
    return [part for part in parts if part], kinds


def _separator_kind(separator: str) -> str:
    value = separator.strip().lower()
    if value == "+":
        return "plus"
    return "with" if value == "with" else "and"


def _split_reason(kinds: set[str]) -> str:
    if kinds == {"plus"}:
        return "compound_plus"
    if kinds == {"and"}:
        return "compound_and"
    if kinds == {"with"}:
        return "compound_with"
    return "compound_mixed"


def should_split(left: str, right: str) -> bool:
    right_head = right[:80]

    # An earth with its own size or spelled "Earth" is a separate cable from the main run.
    if is_separate_earth(right_head):
        return has_cable_facts(left)

    left_has_cable = has_cable_facts(left)
    right_has_cable = has_cable_facts(right_head)

    if not left_has_cable or not right_has_cable:
        return False

    # 4C + E and 3C + 3E are one cable construction, not two quote lines.
    if re.search(r"\b\d+\s*C\s*$", left, re.I) and re.search(r"^\s*\d*\s*E\b", right, re.I):
        return False

    return True


def is_separate_earth(text: str) -> bool:
    return bool(SEPARATE_EARTH_RE.search(text))


def has_cable_facts(text: str) -> bool:
    if not text:
        return False
    score = 0
    if SIZE_RE.search(text):
        score += 2
    if CORE_RE.search(text):
        score += 1
    if LEADING_QTY_RE.search(text):
        score += 1
    if EARTH_RE.search(text):
        score += 1
    if MATERIAL_RE.search(text):
        score += 1
    if CABLE_HINT_RE.search(text):
        score += 1
    return score >= 2
