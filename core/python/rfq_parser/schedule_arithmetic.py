# Generated from the RFQ parser's matcher/schedule_arithmetic.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""How many cables a schedule row asks for, and which rule says so.

`cable_notation.ENGINEERING_SCHEDULE_NOTATION` states these conventions in prose, for the
models. This is the same knowledge as code, for the deterministic layer that checks what a
model returned. The two must agree, and `tests/test_schedule_arithmetic.py` pins the worked
example that appears in both.

**Every count carries the identifier of the rule that produced it.** That is not decoration.
Phase 0 §2 measured the gap this closes: `validate_items` proves a derivation reproduces the
quantity it claims, and proves nothing at all about the multiplier -- `I3 * 24` and `I3 * 20`
validate identically. A count with no rule behind it is exactly the case that used to pass.

**Nothing here writes a quantity.** It computes what the cells imply and hands it back for
comparison. Rewriting a reader's total from a recomputed multiplier is the 2026-09-09
double-multiplication bug (`1600 -> 6400`), and on the estimating workbook it would be 24x.
`quantity_check` compares; the reader's number stays the reader's.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

# `N SETS OF S`, `N x S`, or a bare `S`. One owner: `file_parser` imports this rather than
# keeping its own copy, so the active, neutral and earth columns are parsed by identical
# code on both the deterministic and the reader paths.
SET_NOTATION_RE = re.compile(
    r"^\s*(?:(\d+)\s*(?:[x×]|sets?\s+of)\s*)?(\d+(?:\.\d+)?)\s*(?:mm2?)?\s*$", re.I
)
SINGLE_CORE_RE = re.compile(r"s\.?\s*d\.?\s*i|single\s*core|(?<![\d.])1\s*c(?:ore)?\b", re.I)
MULTI_CORE_RE = re.compile(
    r"(?<![\d.x×])([2-9]|[1-9]\d)\s*c(?:ore)?s?\b\s*(?:[&+]\s*\d*\s*e\b)?", re.I
)
NO_EARTH_RE = re.compile(r"^\s*(?:no\s*earth|n/?a|none|nil|-+)\s*$", re.I)

# Rule identifiers. These are the strings that appear in `rule_id` and in the assumption
# register, so they are a vocabulary shared with the manifest, not free text.
RULE_MULTICORE = "multicore-one-cable-per-run"
RULE_THREE_ACTIVE_ONE_NEUTRAL = "three-active-one-neutral"
RULE_SEPARATE_EARTH_SETS = "separate-earth-own-set-count"
RULE_UNEQUAL_NEUTRAL = "unequal-neutral-own-item"
RULE_STATED = "stated-count"

# Approved 2026-09-15 and deliberately narrow: it applies only where the sheet gives
# separate active and neutral size columns for single-core (SDI) runs. See
# `memory/tricab-estimating-schedule.md` and Phase 0 §5.3.
PHASES = 3


@dataclass(frozen=True)
class SetNotation:
    """`6 SETS OF 300` -> sets=6, size='300', stated=True. `300` -> sets=1, stated=False."""

    sets: int
    size: str
    stated: bool

    @property
    def size_number(self) -> float | None:
        try:
            return float(self.size)
        except (TypeError, ValueError):
            return None


@dataclass(frozen=True)
class ConductorCount:
    """How many separate cables follow the route, and what says so.

    `count is None` means the cells do not determine it. That is preserved as unknown and
    never defaulted to 1 -- "preserve missing quantities as missing" is a checklist item in
    its own right, and a silent 1 is the shape an under-quote takes.
    """

    count: int | None
    rule: str
    evidence: str
    detail: str

    @property
    def supported(self) -> bool:
        """A multiplier is supported when a cell stated it or a named rule produced it."""
        return self.count is not None and bool(self.rule)


def parse_sets(cell: object) -> SetNotation | None:
    """Parse one size cell. Returns None when the cell is not a size at all."""
    match = SET_NOTATION_RE.match(str(cell or "").strip())
    if not match:
        return None
    raw_sets = match.group(1)
    return SetNotation(
        sets=int(raw_sets) if raw_sets else 1,
        size=_trim_number(match.group(2)),
        stated=bool(raw_sets),
    )


def _trim_number(text: str) -> str:
    value = str(text or "").strip()
    if value.endswith(".0"):
        return value[:-2]
    return value


def is_multicore(cores_cell: object) -> bool:
    """`4C&E` is one multicore. `S.D.I.`/`1C` is single-core."""
    text = str(cores_cell or "")
    if SINGLE_CORE_RE.search(text):
        return False
    return bool(MULTI_CORE_RE.search(text))


def has_earth(earth_cell: object) -> bool:
    text = str(earth_cell or "").strip()
    return bool(text) and not NO_EARTH_RE.match(text)


def expected_conductors(
    component: str,
    *,
    active: object = None,
    neutral: object = None,
    earth: object = None,
    cores: object = None,
    phases: int = PHASES,
) -> ConductorCount:
    """How many separate cables this component contributes, from the cells alone.

    The reader is not consulted. That is the point: this is the independent recomputation
    Phase 2 compares its claim against, so anything it took from the reader would make the
    comparison circular.
    """
    active_sets = parse_sets(active)
    neutral_sets = parse_sets(neutral)
    earth_sets = parse_sets(earth)

    if component == "multicore":
        # One cable per run, however many cores. Multiplying a multicore route by its core
        # count is a 3x over-quote on a `2c+e` row -- measured, `23-Allison` row 4, +320 m.
        return ConductorCount(1, RULE_MULTICORE, "cores cell", f"{cores!r} is one multicore cable")

    if component == "mains_active_and_neutral":
        if active_sets is None:
            return ConductorCount(None, "", "", "no active size to count from")
        if neutral_sets is None:
            # Actives only: one per phase, per set.
            return ConductorCount(
                phases * active_sets.sets, RULE_THREE_ACTIVE_ONE_NEUTRAL, "active size cell",
                f"{phases} actives x {active_sets.sets} set(s)",
            )
        if neutral_sets.size != active_sets.size:
            # An unequal neutral is a separate product and is NOT counted in with the
            # actives. It becomes its own item -- see `separate_neutral` below.
            return ConductorCount(
                phases * active_sets.sets, RULE_THREE_ACTIVE_ONE_NEUTRAL, "active size cell",
                f"{phases} actives x {active_sets.sets} set(s); "
                f"neutral {neutral_sets.size} differs from active {active_sets.size} and is its own item",
            )
        return ConductorCount(
            phases * active_sets.sets + neutral_sets.sets,
            RULE_THREE_ACTIVE_ONE_NEUTRAL,
            "active and neutral size cells",
            f"{phases} actives x {active_sets.sets} set(s) + {neutral_sets.sets} neutral(s)",
        )

    if component == "mains_active":
        if active_sets is None:
            return ConductorCount(None, "", "", "no active size to count from")
        return ConductorCount(
            phases * active_sets.sets, RULE_THREE_ACTIVE_ONE_NEUTRAL, "active size cell",
            f"{phases} actives x {active_sets.sets} set(s)",
        )

    if component == "separate_neutral":
        if neutral_sets is None:
            return ConductorCount(None, "", "", "no neutral size to count from")
        return ConductorCount(
            neutral_sets.sets, RULE_UNEQUAL_NEUTRAL, "neutral size cell",
            f"{neutral_sets.sets} neutral(s) of {neutral_sets.size}",
        )

    if component in ("separate_earth", "screen"):
        if earth_sets is None:
            return ConductorCount(None, "", "", "no earth size to count from")
        # The earth column carries its OWN set count, independent of the actives:
        # 6 SETS OF 300 active, 4 SETS OF 120 earth, 15 m route => 360 m mains, 60 m earth.
        return ConductorCount(
            earth_sets.sets, RULE_SEPARATE_EARTH_SETS, "earth size cell",
            f"{earth_sets.sets} earth(s) of {earth_sets.size}",
        )

    return ConductorCount(None, "", "", f"no rule for component {component!r}")
