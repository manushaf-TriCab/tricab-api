"""Read an accessory from the words a customer typed, not from a code they quoted.

``matcher/accessories.py`` handles the case where the line already carries a full
product code. This module handles the other 91%: ``120M12 Lug``,
``EMC gland brass nickel plated``, ``10mm x 1.5mm2 Black``. It produces a *reading* --
a style, whatever dimensions the line stated, and an honest account of what is still
missing -- and it deliberately stops short of inventing the parts it cannot know.

The design follows the corpus scan in ACCESSORY_AND_AGENT_READINESS_ORDER.md §2.0, and
one finding in it reverses the obvious approach: **accessory lines are self-describing.**
They carry their own dimensions, so a code can be built from the line's own text with no
parent cable and no rules evaluation. The cable -> accessory inference engine is the
fallback for the six "to suit" messages, not the main path (§2.4).

What this module will not do:

- **Invent a request.** Two of the six "to suit" messages in the corpus are the customer
  describing their own quoting ("I will be quoting glands to suit"). A noun-only matcher
  turns those into quote rows.
- **Guess a missing dimension.** Stud size is absent on ~41% of lug lines and form factor
  is unstated even when every dimension is given. Both surface as unresolved segments;
  the accessory panel already renders candidate lists.
- **Emit a code it has not validated.** ``12 x 16mm bi-metallic lug, 10mm`` constructs a
  code that does not exist -- B20 in 16mm is made in 6mm and 8mm stud only. The right
  output is that sentence, not the nearest substitute.
"""

from __future__ import annotations

import json
import math
import re
from collections import Counter, defaultdict
from dataclasses import dataclass, field
from functools import lru_cache
from pathlib import Path
from typing import Any


REGISTER_PATH = Path(__file__).resolve().parent / "data" / "catalog" / "accessory_styles.json"


# --------------------------------------------------------------------------------------
# The register
# --------------------------------------------------------------------------------------


@lru_cache(maxsize=1)
def load_register(path: str | None = None) -> dict[str, dict[str, Any]]:
    """The 722-style register, keyed by style code.

    Rebuilt by ``scripts/build_accessory_register.py``. Cached because every row on a
    schedule asks for it and the PHP bridge spawns a fresh process per request anyway.
    """
    target = Path(path) if path else REGISTER_PATH
    with open(target, encoding="utf-8") as handle:
        return json.load(handle)["styles"]


@lru_cache(maxsize=1)
def quotable_styles() -> dict[str, dict[str, Any]]:
    """The styles that could actually produce a code: the ones with ``accessory_specs`` rows.

    212 of 722. The rest are catalogue entries with no priced geometry behind them, and
    including them in scoring is not a neutral act -- it was the whole of the tie problem.
    ``EMC gland brass nickel plated`` tied four ways across D88, D30, D29 and D27, three of
    which have zero spec rows and cannot be quoted at all; the tie then broke on style code
    order, which is alphabetical accident rather than a decision.
    """
    return {style: row for style, row in load_register().items() if row.get("spec_rows")}


# --------------------------------------------------------------------------------------
# Tokenising
# --------------------------------------------------------------------------------------

# Cable construction vocabulary, excluded from scoring against accessory names. The order
# names the failure this fixes: `1 x brass gland M32 for 4C 16mm2 SWA` picks the Ex-rated
# SWA gland because *SWA* describes the cable the gland is for, not the gland. Every token
# here describes the thing being terminated; none of them names a variant of the fitting.
CABLE_ATTRIBUTE_TOKENS = frozenset(
    {
        "swa", "xlpe", "pvc", "epr", "hf", "lsf", "lszh", "lsoh", "xhf",
        "core", "cores", "c", "conductor", "conductors",
        "mm", "mm2", "sqmm", "sq", "kv", "v", "volt", "volts",
        "armoured", "armored", "unarmoured", "unarmored", "flexible", "flex",
        "cable", "cables", "wire",
    }
)

# Words that carry no discriminating power anywhere: articles, units of sale, filler.
STOPWORD_TOKENS = frozenset(
    {
        "a", "an", "the", "of", "for", "to", "with", "and", "or", "in", "on", "per",
        "off", "each", "ea", "pcs", "pc", "no", "nos", "qty", "quantity", "x",
        "suit", "suits", "suitable", "please", "quote", "supply", "req", "required",
    }
)


def tokenise(text: str) -> list[str]:
    """Lowercased word tokens, with cable attributes and filler removed.

    Bare numbers go too: they are dimensions, handled by the dimension parsers below,
    and as scoring terms they only ever match by coincidence.
    """
    cleaned = re.sub(r"[^a-z0-9]+", " ", str(text or "").lower())
    return [
        token
        for token in cleaned.split()
        if token
        and token not in CABLE_ATTRIBUTE_TOKENS
        and token not in STOPWORD_TOKENS
        and not token.isdigit()
    ]


# --------------------------------------------------------------------------------------
# Scoring: which style is this line describing?
# --------------------------------------------------------------------------------------

# A term used by at most this share of its family names a *variant* ("EMC", "fire rated",
# "stalk"); a term used more widely is an attribute the whole family shares ("brass",
# "gland") and cannot discriminate. The line sits at ~1 in 6 because that is where the
# gland family separates: "emc" is 3 of 20, "nickel" 5 and "plated" 6.
CONSTRAINT_SHARE = 1.0 / 6.0

# Below this the winner is not clear of the runner-up and both are offered to the human.
# The accessory panel already renders candidate lists (§2.3), so a tie is a supported
# outcome rather than a failure -- and two of the residual ambiguities (stud size, form
# factor) have no answer in the data at all.
DECISIVE_MARGIN = 0.15


@lru_cache(maxsize=1)
def _family_weights() -> dict[str, dict[str, float]]:
    """Per-family inverse document frequency over the register's keywords.

    Family is the series letter, which is the codebase's existing convention --
    ``accessories.SERIES_UNIT_LABELS`` already reads B as lug, D as gland, W as tie.

    Weighting has to be family-local, not global. Globally ``gland`` and ``emc`` are
    comparably rare (idf 2.14 against 3.97) and a query naming both scores a plain brass
    gland above the EMC one, because the plain gland's ERP name happens to contain the
    customer's generic adjectives -- "Cable Gland, Brass Nickel Plated" -- while the EMC
    gland's does not, though it is equally brass and equally nickel plated. Within the
    gland family ``gland`` appears in every style and its weight falls to zero, which is
    the correct reading: once the noun is known it carries no further information.
    """
    families: dict[str, dict[str, float]] = {}
    grouped: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for style, row in quotable_styles().items():
        grouped[style[:1]].append(row)

    for letter, rows in grouped.items():
        frequency: Counter[str] = Counter()
        for row in rows:
            frequency.update(set(row.get("keywords") or []))
        size = len(rows)
        families[letter] = {
            term: max(math.log(size / (1 + count)), 0.0) for term, count in frequency.items()
        }
    return families


@lru_cache(maxsize=1)
def _global_weights() -> dict[str, float]:
    """Inverse document frequency across every quotable style, used to pick the family.

    Family-local weights cannot do this job: they are not comparable across families, by
    construction. ``lug`` is universal among lugs and so weighs nothing there, while it is
    rare among plugs and sockets -- three of forty-eight, all "Lug Converter" parts -- and
    so weighs a great deal. Ranking one pooled list of family-local scores therefore sends
    ``120M12 Lug`` to J59, a plug converter, and ``brass gland`` to the conduit glands in
    F rather than the cable glands in D. Both are the same bug.
    """
    frequency: Counter[str] = Counter()
    for row in quotable_styles().values():
        frequency.update(set(row.get("keywords") or []))
    size = len(quotable_styles())
    return {term: max(math.log(size / (1 + count)), 0.0) for term, count in frequency.items()}


@lru_cache(maxsize=1)
def _family_shares() -> dict[str, dict[str, float]]:
    """Per family, the share of its styles carrying each term.

    Share is the opposite measure to discrimination and answers the opposite question. A
    term on nearly every style in a family *names* that family -- ``lug`` on 23 of the 23
    lug styles -- which is exactly what identifies where a line belongs, and exactly what
    is useless for choosing between siblings once you are there.
    """
    grouped: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for style, row in quotable_styles().items():
        grouped[style[:1]].append(row)

    shares: dict[str, dict[str, float]] = {}
    for letter, rows in grouped.items():
        frequency: Counter[str] = Counter()
        for row in rows:
            frequency.update(set(row.get("keywords") or []))
        shares[letter] = {term: count / len(rows) for term, count in frequency.items()}
    return shares


def rank_families(text: str) -> list[tuple[str, float]]:
    """Which accessory families this line could be naming, best first.

    A family scores on how strongly the line's words identify it: the share of the family
    carrying each term, weighted by how rare that term is overall. A noun that names one
    family and appears incidentally in another separates the two cleanly -- ``lug`` scores
    2.18 for B and 0.14 for J.
    """
    query = set(tokenise(text))
    if not query:
        return []

    weights = _global_weights()
    shares = _family_shares()
    scored: list[tuple[str, float]] = []
    for letter, family_shares in shares.items():
        score = sum(family_shares.get(term, 0.0) * weights.get(term, 0.0) for term in query)
        if score > 0:
            scored.append((letter, score))
    scored.sort(key=lambda item: (-item[1], item[0]))
    return scored


@lru_cache(maxsize=1)
def _family_constraints() -> dict[str, frozenset[str]]:
    """Per family, the terms rare enough to act as filters rather than scores.

    A constraint is a claim about *which* variant, so a style that lacks it is not a
    weaker answer -- it is the wrong answer. Scoring alone cannot express that: it lets
    three generic adjectives outvote one discriminator, which is exactly how
    ``EMC gland brass nickel plated`` lands on the plain brass gland.
    """
    constraints: dict[str, frozenset[str]] = {}
    grouped: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for style, row in quotable_styles().items():
        grouped[style[:1]].append(row)

    for letter, rows in grouped.items():
        frequency: Counter[str] = Counter()
        for row in rows:
            frequency.update(set(row.get("keywords") or []))
        ceiling = max(len(rows) * CONSTRAINT_SHARE, 1.0)
        constraints[letter] = frozenset(
            term for term, count in frequency.items() if count <= ceiling
        )
    return constraints


@dataclass
class StyleCandidate:
    """One style the line might be describing, with the evidence for it."""

    style: str
    score: float
    matched: tuple[str, ...]
    label: str
    category: str | None
    spec_rows: int
    # Read from the register's curated `label` alone, never from the display `label`
    # above -- that one falls back to the raw ERP name, where "Standard Nylon Gland"
    # (D21) says nothing about which variant of the nylon subgroup it is.
    base_variant: bool = False

    def as_dict(self) -> dict[str, Any]:
        return {
            "style": self.style,
            "score": round(self.score, 3),
            "matched": list(self.matched),
            "label": self.label,
            "category": self.category,
        }


# A family scoring within this fraction of the best is kept in contention. Sibling
# families overlap honestly -- D and F are both glands, B holds both lugs and links -- and
# a line that names the noun without naming the family should offer both rather than pick.
FAMILY_MARGIN = 0.5


def score_styles(text: str, limit: int = 5) -> list[StyleCandidate]:
    """Rank the quotable styles against a line of customer text.

    Three stages, in this order, because each depends on the one before:

    1. **Which family**, on global evidence -- is this a lug, a gland, a tie?
    2. **Constraints**, family-local. Any variant term the line states must be present on
       the style, or the style is out. This is what stops three generic adjectives
       outvoting one discriminator: ``EMC gland brass nickel plated`` is a claim about
       *which* gland, and a style that is not EMC is wrong rather than merely weaker.
    3. **Score** whatever else matched, at its family-local weight, so a line that states
       no constraint still ranks by ordinary term overlap.
    """
    query = set(tokenise(text))
    if not query:
        return []

    families = rank_families(text)
    if not families:
        return []
    ceiling = families[0][1]
    in_contention = {letter for letter, score in families if score >= ceiling * FAMILY_MARGIN}

    weights = _family_weights()
    constraints = _family_constraints()
    candidates: list[StyleCandidate] = []

    for style, row in quotable_styles().items():
        letter = style[:1]
        if letter not in in_contention:
            continue
        keywords = set(row.get("keywords") or [])
        matched = query & keywords
        if not matched:
            continue

        # Every constraint the line states and this family recognises must be satisfied.
        stated = query & constraints.get(letter, frozenset())
        if stated - keywords:
            continue

        family_weights = weights.get(letter, {})
        score = sum(family_weights.get(term, 0.0) for term in matched)
        if score <= 0:
            # Matched only terms shared by the whole family, e.g. the bare noun "lug".
            # That is a family identification, not a style one; keep it rankable but
            # below anything carrying real evidence.
            score = 0.0

        candidates.append(
            StyleCandidate(
                style=style,
                score=score,
                matched=tuple(sorted(matched)),
                label=str(row.get("label") or row.get("erp_name") or ""),
                category=row.get("category"),
                spec_rows=int(row.get("spec_rows") or 0),
                base_variant=is_base_variant(row.get("label")),
            )
        )

    # Ties break on the base variant first, then on style code ascending.
    #
    # Ascending code alone was the old rule, on the inferred convention that TriCab
    # numbers the base variant lowest -- D01 plain brass, D06 long thread, D11 EMC. That
    # holds across the brass subgroup and fails across the nylon one: D03 is Nylon Long
    # Thread and D08 is Nylon Standard, so "20mm Nylon Gland" got the long-thread variant
    # from a line that said nothing about thread length.
    #
    # The register does not need a convention inferred from it -- it says so outright.
    # Ascending code stays as the fallback for the styles it cannot separate.
    candidates.sort(
        key=lambda candidate: (-candidate.score, not candidate.base_variant, candidate.style)
    )
    return candidates[:limit]


# The register labels a subgroup's plain product "Standard" -- "Brass Standard",
# "SS Standard", "Nylon Standard" -- and every other member carries the name of what makes
# it different: "Long Thread", "EMC", "Ex-rated", "Premium Nylon". A line that names the
# group and states no variant is asking for the standard one.
BASE_VARIANT_TERM = "standard"


def is_base_variant(label: Any) -> bool:
    """Whether this style is the plain product of its subgroup.

    Takes the register's **curated** ``label``, never the ``erp_name`` the display label
    falls back to. D21 has no curated label and its ERP name is "Standard Nylon Gland",
    which is raw catalogue prose rather than a placement in the nylon subgroup -- reading
    it as one puts two base variants in the same tie and the tie stops resolving. A style
    the register has not placed is not asserted to be a base; it is simply not known to be.
    """
    return BASE_VARIANT_TERM in str(label or "").strip().lower().split()


def tied_with_best(candidates: list[StyleCandidate]) -> list[StyleCandidate]:
    """The candidates the words rank equal-best, which is the set existence may narrow.

    Everything outside it was ranked lower on evidence, and the catalogue is not entitled
    to overturn that -- see the shortlist in :func:`read_accessory_line`.
    """
    if not candidates:
        return []
    best = candidates[0].score
    return [candidate for candidate in candidates if candidate.score >= best - 1e-9]


def is_decisive(candidates: list[StyleCandidate]) -> bool:
    """True when the top candidate is clear enough of the runner-up to stand alone."""
    if not candidates:
        return False
    if candidates[0].score <= 0:
        return False
    if len(candidates) == 1:
        return True
    if (candidates[0].score - candidates[1].score) >= DECISIVE_MARGIN * candidates[0].score:
        return True
    # A tie the register itself resolves. "20mm Nylon Gland" ties D08 (Nylon Standard)
    # with D03 (Nylon Long Thread) and D07 (Premium Nylon) on words alone; the line named
    # no variant, so the standard one is the answer rather than a three-way question. Only
    # when exactly one of the tied styles is the base -- two of them is a real tie.
    tied = tied_with_best(candidates)
    bases = [candidate for candidate in tied if candidate.base_variant]
    return len(bases) == 1 and bases[0] is candidates[0]


# --------------------------------------------------------------------------------------
# Is this line a request at all?
# --------------------------------------------------------------------------------------

# The customer describing their own quoting, not asking for a price. Six corpus messages
# contain "to suit"; two of them are this -- *"I will be quoting glands to suit"* -- and a
# matcher that reads the noun and stops invents a quote row from each. The subject is the
# discriminator: "I/we will quote" is a statement about the sender's scope of supply.
NOT_A_REQUEST_RE = re.compile(
    r"\b(?:i|we)\s*(?:'?ll|will|am|are|have|shall)?\s*(?:be\s+)?"
    r"(?:quoting|quote|pricing|price|supplying|supply|providing|provide)\b",
    re.I,
)

# Explicitly excluded from the enquiry rather than asked for.
EXCLUDED_RE = re.compile(
    r"\b(?:excluding|excludes|not\s+(?:required|needed|included)|by\s+others|"
    r"n/?a|omit(?:ted)?)\b",
    re.I,
)


def is_accessory_request(text: str) -> bool:
    """False when the line mentions an accessory but is not asking to be quoted one."""
    raw = str(text or "")
    if not raw.strip():
        return False
    return not (NOT_A_REQUEST_RE.search(raw) or EXCLUDED_RE.search(raw))


# A style code written on the line itself: "G03 Heat Shrink Glue Lined Black". Letter plus
# two digits, and it must be a style the register actually knows -- the shape alone is far
# too common in prose to trust.
#
# The M series is excluded outright. Stud sizes are spelled M10, M12, M16, and a lug line
# carries one far more often than anyone writes an M-series style: reading "120M12 Lug" as
# style M12 would lose both the conductor and the stud. One clamp style is unreachable this
# way, which is the cheaper of the two errors by a wide margin.
STATED_STYLE_RE = re.compile(r"(?<![A-Z0-9])([A-LN-Z]\d{2})(?![A-Z0-9])", re.I)


def stated_style(text: str) -> str | None:
    """The style code written on the line, when the register recognises it."""
    for match in STATED_STYLE_RE.finditer(str(text or "").upper()):
        candidate = match.group(1).upper()
        if candidate in quotable_styles():
            return candidate
    return None


# --------------------------------------------------------------------------------------
# Dimensions
# --------------------------------------------------------------------------------------

# The dimension specifier, segment 6 of the code, names the pair that follows it. Which
# pair a line supplies depends entirely on the noun, so each family parses its own shape.
SPEC_LENGTH_CSA = "B"          # ferrules: 10mm pin, 1.5mm2 conductor
SPEC_LENGTH_DIAMETER = "D"     # heat shrink: a length of tube at a diameter
SPEC_DIAMETER_DIAMETER = "F"   # glands: thread size against cable outside diameter
SPEC_DIAMETER_CSA = "H"        # lugs: stud diameter against conductor CSA
SPEC_CSA_CSA = "J"             # reducing links: conductor to conductor
# "M" is written width first: W01-AMXX/4.8M250 is a 4.8mm wide tie 250mm long, and every
# one of the 93 W rows in the snapshot has x < y. The customer writes the larger number
# first ("250mm x 4.8mm"), so source order cannot be trusted -- size decides which is which
# and `dimension_pair` emits them width-first regardless of how they were written.
SPEC_LENGTH_WIDTH = "M"        # ties: 4.8mm wide, 300mm long

FAMILY_SPECIFIERS = {
    "A": SPEC_LENGTH_CSA,
    "B": SPEC_DIAMETER_CSA,
    "D": SPEC_DIAMETER_DIAMETER,
    "F": SPEC_DIAMETER_DIAMETER,
    "G": SPEC_LENGTH_DIAMETER,
    "W": SPEC_LENGTH_WIDTH,
}

_CSA_RE = re.compile(r"(\d+(?:\.\d+)?)\s*(?:mm2|mm²|sqmm|sq\s*mm)\b", re.I)
_STUD_RE = re.compile(r"\bM\s*(\d+(?:\.\d+)?)\b", re.I)
_MM_RE = re.compile(r"(\d+(?:\.\d+)?)\s*mm\b(?!2|²)", re.I)
# "120M12", "16M10" -- CSA and stud run together with no separator, which is how a
# counter-hand writes a lug. The M is the stud thread, so the split is unambiguous.
_COMPRESSED_LUG_RE = re.compile(r"\b(\d+(?:\.\d+)?)\s*M\s*(\d+(?:\.\d+)?)\b", re.I)
# Competitor part numbers whose digits are the dimensions -- "CAL120-12_CAB" is a 120mm2
# lug with a 12mm stud. 14% of file-corpus lug lines arrive this way.
# The trailing boundary is (?!\d) rather than \b: these part numbers run straight into a
# suffix -- "CAL120-12_CAB" -- and "_" is a word character, so \b never fires there.
_COMPETITOR_LUG_RE = re.compile(r"\b[A-Z]{2,4}(\d{1,3})\s*-\s*(\d{1,2})(?!\d)", re.I)

COLOUR_CODES = {
    "black": "BK", "blue": "BL", "brown": "BN", "green": "GN", "grey": "GY",
    "gray": "GY", "orange": "OR", "pink": "PK", "red": "RE", "violet": "VT",
    "white": "WH", "yellow": "YE",
}


def _numbers(pattern: re.Pattern[str], text: str) -> list[float]:
    return [float(match.group(1)) for match in pattern.finditer(text)]


def extract_colour_code(text: str) -> str:
    """The two-letter colour suffix, where the line names a colour."""
    lowered = str(text or "").lower()
    for name, code in COLOUR_CODES.items():
        if re.search(rf"\b{name}\b", lowered):
            return code
    return ""


def parse_dimensions(text: str, family: str) -> dict[str, Any]:
    """The dimensions a line states, read according to what the noun needs.

    Returns whatever was found; a missing segment is absent rather than defaulted. Stud
    size is the common gap -- absent on ~41% of file-corpus lug lines -- and inventing one
    is how you quote a lug that cannot be crimped onto the customer's busbar.
    """
    raw = str(text or "")
    found: dict[str, Any] = {}

    if family == "B":
        compressed = _COMPRESSED_LUG_RE.search(raw)
        competitor = _COMPETITOR_LUG_RE.search(raw)
        csas = _numbers(_CSA_RE, raw)
        studs = _numbers(_STUD_RE, raw)

        if compressed and not csas:
            # "120M12": the larger number is the conductor, the one after M the stud.
            found["csa"] = float(compressed.group(1))
            found["stud"] = float(compressed.group(2))
        elif competitor and not csas:
            found["csa"] = float(competitor.group(1))
            found["stud"] = float(competitor.group(2))
        else:
            if csas:
                found["csa"] = csas[0]
            if studs:
                found["stud"] = studs[0]
            elif len(csas) < 2:
                # A bare "10mm" beside a stated CSA is the stud, not a second conductor:
                # "12 x 16mm bi-metallic lug, 10mm".
                plain = [value for value in _numbers(_MM_RE, raw) if value not in csas]
                if plain:
                    found["stud"] = plain[0]

        if "csa" not in found:
            # Neither dimension carried the mm2 unit: "12 x 16mm bi-metallic lug, 10mm"
            # writes the conductor as "16mm" and the stud as "10mm". No unit separates
            # them, but the physics does -- a lug's barrel takes a conductor larger than
            # the stud it bolts to, on every row of accessory_specs -- so the larger of
            # the two is the conductor. Only applied when the line gives exactly two, and
            # never over an explicit mm2, which stays authoritative above.
            plain = _numbers(_MM_RE, raw)
            if len(plain) == 2:
                found["csa"] = max(plain)
                found["stud"] = min(plain)
            elif len(plain) == 1 and found.get("stud") is not None and plain[0] != found["stud"]:
                # "Cu Bell Mouth Lug - 300mm - M10". The stud is explicit, so the one
                # remaining dimension is the conductor whatever its magnitude -- no
                # larger-of-the-two reasoning is needed and none is used. Equal values are
                # left alone: "Lug M10, 10mm stud" is one dimension written twice.
                found["csa"] = plain[0]
        if len(csas) >= 2:
            # A reducing link states conductor to conductor and takes the J specifier.
            found["csa"] = csas[0]
            found["csa_to"] = csas[1]
            found.pop("stud", None)

    elif family in {"D", "F"}:
        # A gland is named and ordered by its entry thread: "32mm cable gland" is a 32mm
        # thread, and D01 makes exactly one of those. The cable outside diameter is a
        # property of the gland's clamping range rather than something a customer states
        # bare -- when it is given at all it arrives second ("M32 to suit 25mm OD"). The
        # thread cannot be recovered by size: thread > OD on most rows but not on D15,
        # D22, D24 or D29, so position and the explicit M are the only safe evidence.
        studs = _numbers(_STUD_RE, raw)
        plain = _numbers(_MM_RE, raw)
        if studs:
            found["thread"] = studs[0]
            remaining = [value for value in plain if value != studs[0]]
        else:
            found["thread"] = plain[0] if plain else None
            remaining = plain[1:]
        if found.get("thread") is None:
            found.pop("thread", None)
        if remaining:
            found["outside_diameter"] = remaining[0]

    elif family == "A":
        csas = _numbers(_CSA_RE, raw)
        if csas:
            found["csa"] = csas[0]
        lengths = [value for value in _numbers(_MM_RE, raw) if value not in csas]
        if lengths:
            found["length"] = lengths[0]

    elif family == "G":
        plain = _numbers(_MM_RE, raw)
        if plain:
            found["diameter"] = plain[0]
        if len(plain) >= 2:
            found["length"] = max(plain)
            found["diameter"] = min(plain)

    elif family == "W":
        # "4.8 x 300mm": a tie is written width first and the unit is usually given once,
        # at the end, so the width arrives bare. Take every number on the line rather than
        # only the ones carrying "mm", then let size decide which is which -- a tie is
        # always longer than it is wide.
        # No trailing \b: "300mm" has no boundary between the digits and the unit, so a
        # bounded pattern reads only the bare "4.8" and the pair never forms.
        values = [float(part) for part in re.findall(r"(?<![\d.])(\d+(?:\.\d+)?)", raw)]
        if len(values) >= 2:
            found["width"] = min(values[0], values[1])
            found["length"] = max(values[0], values[1])

    colour = extract_colour_code(raw)
    if colour:
        found["colour"] = colour
    return found


def dimension_pair(dimensions: dict[str, Any], family: str) -> tuple[str, Any, Any] | None:
    """The (specifier, first, second) triple a code needs, or None if a segment is missing."""
    if family == "B":
        if dimensions.get("csa_to") is not None:
            return SPEC_CSA_CSA, dimensions.get("csa"), dimensions.get("csa_to")
        return SPEC_DIAMETER_CSA, dimensions.get("stud"), dimensions.get("csa")
    if family in {"D", "F"}:
        return SPEC_DIAMETER_DIAMETER, dimensions.get("thread"), dimensions.get("outside_diameter")
    if family == "A":
        return SPEC_LENGTH_CSA, dimensions.get("length"), dimensions.get("csa")
    if family == "G":
        return SPEC_LENGTH_DIAMETER, dimensions.get("length"), dimensions.get("diameter")
    if family == "W":
        return SPEC_LENGTH_WIDTH, dimensions.get("width"), dimensions.get("length")
    return None


def format_dimension(value: Any) -> str:
    """Code segments carry 120 and 1.5, never 120.0."""
    number = float(value)
    return str(int(number)) if number.is_integer() else f"{number:g}"


def build_accessory_code(
    style: str,
    specifier: str,
    first: Any,
    second: Any,
    *,
    pack_code: str,
    form_factor: str = "X",
    colour: str = "",
) -> str:
    """Assemble a code from the grammar in the work order §2.1.

    ``pack_code`` is not derivable from the customer's words -- it is how the product is
    packed, which lives in ``accessory_specs``. It is a required argument rather than a
    defaulted one so that no caller can accidentally emit a plausible code with an
    invented pack size.
    """
    return (
        f"{style}-A{pack_code}X{form_factor}/"
        f"{format_dimension(first)}{specifier}{format_dimension(second)}{colour}"
    )


# --------------------------------------------------------------------------------------
# Validation against the catalogue
# --------------------------------------------------------------------------------------

SNAPSHOT_PATH = Path(__file__).resolve().parent / "data" / "catalog" / "accessory_engine_snapshot.json"


@lru_cache(maxsize=4)
def load_specs(path: str | None = None) -> tuple[dict[str, Any], ...]:
    """``accessory_specs`` rows, decoded from their product codes.

    Written by ``scripts/export_accessory_engine.py`` where the ERP database is
    reachable. Everything needed here is recoverable from the ``product_code`` column
    alone, via :func:`accessories.parse_accessory_code`, so this does not depend on the
    rest of that table's schema -- which matters, because the snapshot has never been
    taken and the column list is not known here.

    Returns an empty tuple when there is no snapshot. That is a first-class state, not an
    error: it means "cannot validate", and every caller must say so rather than treating
    an unvalidated code as a good one.
    """
    from .accessories import parse_accessory_code

    target = Path(path) if path else SNAPSHOT_PATH
    if not target.exists():
        return ()
    with open(target, encoding="utf-8") as handle:
        payload = json.load(handle)

    decoded: list[dict[str, Any]] = []
    for row in payload.get("accessory_specs") or []:
        code = str(row.get("product_code") or "").strip().upper()
        parsed = parse_accessory_code(code) if code else None
        if parsed:
            decoded.append(parsed)
    return tuple(decoded)


def specs_available(path: str | None = None) -> bool:
    """Whether a catalogue snapshot is present to validate against."""
    return bool(load_specs(path))


def _matches(spec: dict[str, Any], specifier: str, first: Any, second: Any, colour: str) -> bool:
    if spec.get("dimension_code", "").upper() != specifier.upper():
        return False
    if format_dimension(first) != str(spec.get("x_size", "")).upper():
        return False
    if format_dimension(second) != str(spec.get("y_size", "")).upper():
        return False
    if colour and str(spec.get("colour", "")).upper() != colour.upper():
        return False
    return True


def find_catalogue_codes(
    style: str, specifier: str, first: Any, second: Any, colour: str = "", *, path: str | None = None
) -> list[str]:
    """Every real product code matching this style and these dimensions.

    Looking the code up beats constructing one. The pack-quantity letter and the form
    factor are properties of how the product is made and packed, not of anything the
    customer wrote, so a constructed code can only ever guess them -- and a guess that
    parses looks exactly like a fact. More than one result is the form-factor ambiguity
    of §2.3 arriving as data: ``Cu Bell Mouth Lug - 300mm - M10`` is made in two shapes.
    """
    if first is None or second is None:
        return []
    return sorted(
        {
            spec["product_code"]
            for spec in load_specs(path)
            if spec.get("style", "").upper() == style.upper()
            and _matches(spec, specifier, first, second, colour)
        }
    )


def find_codes_by_first_dimension(
    style: str, specifier: str, first: Any, colour: str = "", *, path: str | None = None
) -> list[str]:
    """Every product of this style at this first dimension, whatever its second.

    A gland is chosen by its entry thread; the cable outside diameter that follows is a
    property of the gland, not a second thing the customer picks. "32mm cable gland Brass"
    states everything a counter-hand needs, and D01 is made in exactly one 32mm thread --
    ``D01-AGXX/32F25``. Requiring both segments before consulting the catalogue turned that
    complete line into "the line does not state cable outside diameter".

    Callers must treat more than one result as unresolved. This narrows to what the
    catalogue contains; it never chooses among what it finds, so a style made in several
    outside diameters still goes back as the question it is.
    """
    if first is None:
        return []
    target = format_dimension(first)
    return sorted(
        {
            spec["product_code"]
            for spec in load_specs(path)
            if spec.get("style", "").upper() == style.upper()
            and spec.get("dimension_code", "").upper() == specifier.upper()
            and str(spec.get("x_size", "")).upper() == target
            and (not colour or str(spec.get("colour", "")).upper() == colour.upper())
        }
    )


def available_dimensions(
    style: str, specifier: str, second: Any, *, path: str | None = None
) -> list[str]:
    """The first-dimension values this style is actually made in, at a given second value.

    Feeds the message that has to replace a substituted code: ``12 x 16mm bi-metallic
    lug, 10mm`` asks for a 10mm stud on a 16mm2 B20, which is not made -- B20 in 16mm2
    comes in 6mm and 8mm only. Naming those two is the useful answer.
    """
    if second is None:
        return []
    target = format_dimension(second)
    return sorted(
        {
            str(spec.get("x_size", ""))
            for spec in load_specs(path)
            if spec.get("style", "").upper() == style.upper()
            and spec.get("dimension_code", "").upper() == specifier.upper()
            and str(spec.get("y_size", "")).upper() == target
        },
        key=lambda value: (len(value), value),
    )


# --------------------------------------------------------------------------------------
# The reading
# --------------------------------------------------------------------------------------


@dataclass
class AccessoryReading:
    """What a line of customer text was understood to be asking for.

    ``status`` is the honest summary:

    - ``not_a_request``  -- an accessory is named but nothing is being asked for.
    - ``no_style``       -- no accessory family recognised; not an accessory line.
    - ``matched``        -- a real catalogue code, validated against accessory_specs.
    - ``ambiguous``      -- more than one style or more than one code fits; ask.
    - ``incomplete``     -- the style is known and a dimension the code needs is not.
    - ``no_such_product``-- everything parsed, and the product does not exist.
    - ``unvalidated``    -- parsed, but there is no catalogue snapshot to check against.
    """

    text: str
    status: str
    style: str | None = None
    family: str | None = None
    candidates: list[StyleCandidate] = field(default_factory=list)
    dimensions: dict[str, Any] = field(default_factory=dict)
    codes: list[str] = field(default_factory=list)
    missing: list[str] = field(default_factory=list)
    note: str = ""

    @property
    def product_code(self) -> str | None:
        return self.codes[0] if len(self.codes) == 1 else None

    def as_dict(self) -> dict[str, Any]:
        return {
            "status": self.status,
            "style": self.style,
            "family": self.family,
            "product_code": self.product_code,
            "codes": list(self.codes),
            "dimensions": dict(self.dimensions),
            "missing": list(self.missing),
            "candidates": [candidate.as_dict() for candidate in self.candidates],
            "note": self.note,
        }


_SEGMENT_LABELS = {
    "csa": "conductor size",
    "csa_to": "the size it reduces to",
    "stud": "stud size",
    "thread": "thread size",
    "outside_diameter": "cable outside diameter",
    "length": "length",
    "width": "width",
    "diameter": "diameter",
}


def read_accessory_line(text: str, *, path: str | None = None) -> AccessoryReading:
    """Read one line of customer text as an accessory request.

    The order of the checks is the design: refuse to invent a request before doing any
    matching, and refuse to emit a code before checking it exists.
    """
    raw = str(text or "")

    if not is_accessory_request(raw):
        return AccessoryReading(
            text=raw,
            status="not_a_request",
            note="The line describes the sender's own scope of supply, not a request to quote.",
        )

    stated = stated_style(raw)
    if stated:
        # 9% of corpus accessory lines name the style outright -- "G03 Heat Shrink Glue
        # Lined Black". Scoring the words against every style ignores the one fact on the
        # line that is not an inference: that line scores G08 on "glue lined" while the
        # customer has already said G03.
        row = quotable_styles()[stated]
        candidates = [
            StyleCandidate(
                style=stated,
                score=float("inf"),
                matched=("stated",),
                label=str(row.get("label") or row.get("erp_name") or ""),
                category=row.get("category"),
                spec_rows=int(row.get("spec_rows") or 0),
                base_variant=is_base_variant(row.get("label")),
            )
        ]
    else:
        # Deep, not five. A bare noun -- "120M12 Lug" -- ties every style in its family at
        # zero, and B06, the style that actually makes this lug, is not among the five
        # lowest-numbered. `reading.candidates` is still sliced to five for display.
        candidates = score_styles(raw, limit=50)
    if not candidates:
        return AccessoryReading(text=raw, status="no_style", note="No accessory family recognised.")

    family = candidates[0].style[:1]
    dimensions = parse_dimensions(raw, family)
    pair = dimension_pair(dimensions, family)

    # The shape of the dimensions is itself evidence about the style, and it settles ties
    # the words cannot. "bi-metallic lug" fits B20 (1-hole), B21 (reducer) and B22 (stalk)
    # equally; a line stating one conductor and a stud is asking for the stud-and-conductor
    # shape, which B21 -- made only conductor-to-conductor -- does not have. Dropping the
    # styles the catalogue never makes in this shape is narrowing on fact rather than on
    # preference. Needs the snapshot, so where there is none the tie stands and is reported.
    #
    # It narrows the equal-best set and nothing below it, for the same reason the shortlist
    # does. Applied to the whole ranking it promoted rather than narrowed: "split loom 20mm
    # 6 ea" scores F20 and F21 -- PP and Nylon Split Loom -- at 1.739, and both are made
    # only in the D shape because loom is sold as a length at a diameter. Filtering on the
    # F shape this family's entry in FAMILY_SPECIFIERS asks for dropped them both and left
    # X04 "Bushings", at 0.357, to be read as the answer. When nothing in the equal-best set
    # has the shape, the shape is evidence that the reading is wrong, not that a worse style
    # is right: the tie stands and the line goes back unresolved.
    if pair is not None and specs_available(path):
        specifier = pair[0]
        tied = tied_with_best(candidates)
        shaped = [
            candidate
            for candidate in tied
            if any(
                spec.get("style", "").upper() == candidate.style.upper()
                and spec.get("dimension_code", "").upper() == specifier.upper()
                for spec in load_specs(path)
            )
        ]
        if shaped:
            candidates = shaped + candidates[len(tied):]
            family = candidates[0].style[:1]

    reading = AccessoryReading(
        text=raw,
        status="incomplete",
        style=candidates[0].style,
        family=family,
        candidates=candidates[:5],
        dimensions=dimensions,
    )

    if pair is None:
        reading.note = f"No dimension shape is defined for the {family} family."
        return reading

    specifier, first, second = pair
    colour = str(dimensions.get("colour") or "")

    # The set the catalogue is allowed to choose within.
    #
    # It was `score_styles(raw, limit=50)` whenever the words were not decisive, which let
    # existence overturn the words rather than narrow them: "12 x 16mm bi-metallic lug,
    # 10mm" scores B20 and B22 at 2.67 and everything else at zero, then reported B20 as
    # the style beside seven codes belonging to B01, B02, B03, B04, B05, B06 and B30. The
    # style and the codes disagreed and a consumer could not tell which half was wrong.
    #
    # Styles the words ranked below the best were ranked below on evidence. Being made in
    # the stated dimensions does not answer the question the words asked, so the shortlist
    # is the equal-best set: existence narrows within it and reports honestly when nothing
    # in it is made. A bare noun ties the whole family at zero, so that case is unchanged.
    shortlist = candidates[:1] if is_decisive(candidates) else tied_with_best(candidates)

    if first is None or second is None:
        # One dimension may be enough. A gland states its thread and nothing else, and if
        # the style is made in exactly one product at that thread there is nothing left to
        # ask -- see `find_codes_by_first_dimension`. Anything less certain than one style
        # and one code falls through to the report below.
        if first is not None and specs_available(path):
            producing = {
                candidate.style: codes
                for candidate in shortlist
                if (
                    codes := find_codes_by_first_dimension(
                        candidate.style, specifier, first, colour, path=path
                    )
                )
            }
            if len(producing) == 1:
                style, codes = next(iter(producing.items()))
                if len(codes) == 1:
                    reading.style = style
                    reading.family = style[:1]
                    reading.codes = codes
                    reading.status = "matched"
                    return reading

        # Report what is missing before worrying about which style: a line with no stud
        # size is unanswerable whether or not the style is settled, and naming the gap is
        # more use to whoever picks the row up than naming three candidate styles.
        missing_keys = [
            key
            for key, value in zip(_segment_keys(family, dimensions), (first, second))
            if value is None
        ]
        reading.missing = missing_keys
        reading.status = "incomplete" if is_decisive(candidates) else "ambiguous"
        reading.note = "The line does not state " + " or ".join(
            _SEGMENT_LABELS.get(key, key) for key in missing_keys
        ) + "."
        return reading

    if not specs_available(path):
        reading.status = "ambiguous" if not is_decisive(candidates) else "unvalidated"
        reading.note = (
            "Parsed as "
            f"{reading.style} {format_dimension(first)}{specifier}{format_dimension(second)}, "
            "but there is no accessory_specs snapshot on this machine to confirm the product "
            "exists or to supply its pack size. Run scripts/export_accessory_engine.py where "
            "the database is reachable."
        )
        return reading

    # With the catalogue in hand, existence narrows the shortlist. Several styles can fit
    # the words equally -- "bi-metallic lug" fits three -- and if only one of them is
    # actually made in the dimensions the customer stated, that is a fact about the range
    # rather than a preference between synonyms, and it decides the row. Where the words
    # were already decisive this changes nothing: the top candidate is the only one asked.
    by_style = {
        candidate.style: find_catalogue_codes(
            candidate.style, specifier, first, second, colour, path=path
        )
        for candidate in shortlist
    }
    producing = {style: codes for style, codes in by_style.items() if codes}

    if len(producing) == 1:
        style, codes = next(iter(producing.items()))
        reading.style = style
        reading.family = style[:1]
        reading.codes = codes
        if len(codes) == 1:
            reading.status = "matched"
            return reading
        reading.status = "ambiguous"
        reading.note = (
            "Every dimension is stated and more than one product still fits -- the form "
            "factor is not decidable from the line: " + ", ".join(codes)
        )
        return reading

    if len(producing) > 1:
        reading.status = "ambiguous"
        reading.codes = sorted(code for codes in producing.values() for code in codes)
        reading.note = (
            "More than one style is made in these dimensions: "
            + ", ".join(f"{style} ({', '.join(codes)})" for style, codes in sorted(producing.items()))
        )
        return reading

    reading.status = "no_such_product"
    alternatives = available_dimensions(reading.style, specifier, second, path=path)
    if alternatives:
        reading.note = (
            f"{reading.style} in {format_dimension(second)} is not made at "
            f"{format_dimension(first)} -- it comes in " + ", ".join(alternatives) + "."
        )
    else:
        reading.note = (
            f"No {reading.style} product matches "
            f"{format_dimension(first)}{specifier}{format_dimension(second)}."
        )
    return reading


def _segment_keys(family: str, dimensions: dict[str, Any]) -> tuple[str, str]:
    """The names of the two segments a family's code needs, for reporting what is missing."""
    if family == "B":
        if dimensions.get("csa_to") is not None:
            return ("csa", "csa_to")
        return ("stud", "csa")
    if family in {"D", "F"}:
        return ("thread", "outside_diameter")
    if family == "A":
        return ("length", "csa")
    if family == "G":
        return ("length", "diameter")
    if family == "W":
        return ("width", "length")
    return ("first", "second")
