"""Domain vocabularies.

Ported from the legacy Cable Calculator's form and `suggest_cable_style()`,
with the defects listed in PLAN.md §6 corrected rather than carried over.
Every correction is marked `FIX:` and names the finding it resolves.
"""

from __future__ import annotations

from typing import Final

# ─────────────────────────────────────────────────────────────────────────────
# Installation methods
# ─────────────────────────────────────────────────────────────────────────────
# In `tc.rating` each installation method is a separate COLUMN, not a row. The
# legacy code interpolated the method name straight into the SQL string. We keep
# the column-per-method shape (that is how the table is built) but resolve the
# name through this whitelist so nothing from user input reaches the query.

INSTALL_METHODS: Final[dict[int, dict[str, object]]] = {
    1: {"column": "spaced", "label": "Unenclosed spaced", "buried": False},
    2: {"column": "touch", "label": "Unenclosed touching", "buried": False},
    3: {"column": "buriedconduit", "label": "Buried in spaced conduit", "buried": True},
    4: {"column": "buried", "label": "Buried spaced", "buried": True},
    5: {"column": "conduit", "label": "Conduit in air", "buried": False},
    6: {"column": "sun", "label": "Exposed to sun", "buried": False},
    7: {"column": "buriedconduit", "label": "Buried in touching conduit", "buried": True},
    8: {"column": "iec", "label": "IEC", "buried": False},
    9: {"column": "buried", "label": "Buried touching", "buried": True},
}

RATING_COLUMNS: Final[frozenset[str]] = frozenset(
    str(m["column"]) for m in INSTALL_METHODS.values()
)


def rating_column(install: int) -> str:
    """Resolve an installation method to its ampacity column, safely."""
    method = INSTALL_METHODS.get(install)
    if method is None:
        raise ValueError(f"Unknown installation method: {install!r}")
    column = str(method["column"])
    if column not in RATING_COLUMNS:  # defensive; unreachable by construction
        raise ValueError(f"Illegal rating column: {column!r}")
    return column


# ─────────────────────────────────────────────────────────────────────────────
# Cable types
# ─────────────────────────────────────────────────────────────────────────────

CABLE_TYPES: Final[dict[int, str]] = {
    0: "Switchboard",
    1: "SDI XLPE/EPR",
    2: "SDI LSZH",
    3: "SDI FIRE",
    4: "Multi XLPE/EPR",
    5: "Multi LSZH",
    6: "Multi FIRE",
    7: "Multi Pump Unbraided",
    8: "Multi Pump Braided",
    9: "VFD/VSD",
    10: "SDI Shipboard",
    11: "SDI Shipboard Braided",
    12: "SDI Offshore",
    13: "Multi Shipboard",
    14: "Multi Shipboard Braided",
    15: "Multi Offshore",
}

PHASES: Final[dict[int, str]] = {3: "Three phase", 4: "Single phase", 5: "DC"}

# Wording that puts a cable type back into the vocabulary the RFQ parser's
# extractor reads. Used to turn dropdown selections into a line the matcher can
# rank, so the parameter path and the text path go through the same rules.
TYPE_DESCRIPTIONS: Final[dict[int, str]] = {
    0: "switchboard panel wire",
    1: "XLPE single core",
    2: "LSZH single core",
    3: "fire rated single core",
    4: "XLPE multicore",
    5: "LSZH multicore",
    6: "fire rated multicore",
    7: "pump cable unbraided",
    8: "pump cable braided",
    9: "VSD VFD drive cable screened",
    10: "shipboard marine power",
    11: "shipboard marine braided",
    12: "offshore marine",
    13: "shipboard marine power multicore",
    14: "shipboard marine braided multicore",
    15: "offshore marine multicore",
}

VOLTAGES: Final[tuple[int, ...]] = (1000, 400, 240, 48, 24)

CONDUCTOR_SIZES_MM2: Final[tuple[float, ...]] = (
    0.5, 1, 1.5, 2.5, 4, 6, 10, 16, 25, 35, 50, 70, 95,
    120, 150, 185, 240, 300, 400, 500, 630, 800,
)

MAX_RUNS: Final[int] = 100


# Single-core construction -> its multicore equivalent. A three-phase feeder
# described only by application ("fire rated cable, 150 A") should be multicore;
# the wording patterns cannot tell the two apart on their own.
SDI_TO_MULTI: Final[dict[int, int]] = {
    1: 4,    # SDI XLPE/EPR   -> Multi XLPE/EPR
    2: 5,    # SDI LSZH       -> Multi LSZH
    3: 6,    # SDI FIRE       -> Multi FIRE
    10: 13,  # SDI Shipboard  -> Multi Shipboard
    11: 14,  # SDI Shipboard Braided
    12: 15,  # SDI Offshore   -> Multi Offshore
}


def is_sdi(cable_type: int) -> bool:
    """Single-core (SDI) vs multi-core.

    IEC single types 10-12 are single despite their high index; 13-15 are multi.
    """
    if cable_type >= 10:
        return cable_type < 13
    return cable_type <= 3


def is_multi(cable_type: int) -> bool:
    return not is_sdi(cable_type)


# ─────────────────────────────────────────────────────────────────────────────
# Style selection
# ─────────────────────────────────────────────────────────────────────────────
# Ported from `suggest_cable_style()` (inc/include-cable-calc.php:538).
#
# FIX (PLAN §6): the legacy switch has a fall-through at `case 3:` with no
# `break`, so Multi FIRE (6), Multi Pump (7, 8) and VFD (9) never set an earth
# style at all — Multi FIRE reports no earth. Every entry below sets one.
#
# FIX (PLAN §6): the legacy fire pool is ('FR','FH'). FH is a VSD/VFD style in
# the product register, so a plain fire-rated request could return a drive
# cable. FH is removed from the fire pool.
#
# FIX (PLAN §6): the legacy marine cases force `operating = 90`. The register
# rates BV / DV / PJ at -40 to 110 degC. Marine keeps 110.
#
# FIX (PLAN §6): switchboard at 110 maps to LS, whose register title is
# "Switchboard/Panel Wire". LP's title is "Building Wire". LS is correct for
# both temperatures.

_DC = 5  # phase code


def suggest_styles(
    cable_type: int,
    phase: int,
    operating: int,
) -> tuple[tuple[str, ...], str | None, int]:
    """Map (cable type, phase, operating temp) to candidate styles.

    Returns `(styles, earth_style, operating)`. `operating` may be overridden
    where the construction only exists at one temperature.
    """
    t, p, op = cable_type, phase, operating

    # Switchboard / panel wire
    if t == 0:
        # FIX: LS at both temperatures, not LP at 90.
        return ("LS",), "LS", op

    # SDI and Multi XLPE/EPR
    if t in (1, 4):
        if p == _DC:
            if t == 4:
                return ("KB",), "LP", op
            return (("DL",) if op != 90 else ("FP",)), "LP", op
        return (("KL",) if op != 90 else ("XL",)), "LP", op

    # LSZH — only manufactured at 110
    if t in (2, 5):
        if p == _DC:
            return (("SD",) if t == 5 else ("SF",)), "LS", 110
        return ("CC",), "LS", 110

    # Fire rated — FIX: FH excluded, it is a VSD style.
    if t in (3, 6):
        return ("FR",), "LS", 110

    # Pump
    if t == 7:
        return ("KK",), "LS", 110
    if t == 8:
        return ("OD",), "LS", 110

    # VFD / VSD
    if t == 9:
        return (("KU",) if op != 90 else ("XU",)), "LS", op

    # Marine — FIX: keeps its real 110 degC rating.
    if t in (10, 13):
        return ("BV",), "UP", 110
    if t in (11, 14):
        return ("DV",), "UP", 110
    if t in (12, 15):
        return ("PJ",), "UP", 110

    raise ValueError(f"Unknown cable type: {cable_type!r}")


# ─────────────────────────────────────────────────────────────────────────────
# Core counts
# ─────────────────────────────────────────────────────────────────────────────
# Ported from db.php:603-662.
#
# FIX (PLAN §6): the legacy code hardwires three-phase multi-core to 4 active
# cores (`cores = 4`), then queries `cable_specs.nc = cores`. A 3C+E cable can
# therefore never be sized at all. `core_options` returns every valid active-core
# count for the configuration, most common first, and the search tries each.


def core_options(cable_type: int, phase: int) -> tuple[int, ...]:
    """Active-core counts worth searching, in preference order."""
    if is_sdi(cable_type):
        return (1,)

    if cable_type == 9:  # VFD/VSD
        return (3,)
    if cable_type == 7:  # Multi pump unbraided
        return (3,)

    if cable_type >= 10:  # IEC multi
        if phase == _DC:
            return (2,)
        if phase == 4:
            return (3,)
        return (5, 4)

    if phase == _DC:
        return (2,)
    if phase == 4:
        return (2, 3)

    # Three phase. FIX: 3C+E is searched alongside 4C.
    return (4, 3)


def rating_core_count(phase: int) -> int:
    """`rating.corecount` — 3 for three-phase, 2 otherwise."""
    return 3 if phase == 3 else 2


def rating_config(cable_type: int) -> str:
    """`rating.config` — the table stores 'Single' or 'Multi'."""
    return "Single" if is_sdi(cable_type) else "Multi"
