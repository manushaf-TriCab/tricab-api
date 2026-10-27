"""Item-number parsing (contract 4.7) and the constants the rules share."""

from __future__ import annotations

import re
from typing import NamedTuple

CABLE_POOLS = frozenset({"15_WIR", "20_BUN", "25_FCON", "30_TAPE", "35_WIP", "40_SFG", "50_FMAN", "55_PNP", "60_CAT"})
# Pools whose items with a real order are made items. "?" is the literal ProductionPoolId on 90 in-house items
# missing from manufactured-items (owner decision 2026-10-27, matches the prototype). 10_CBC compounds stay materials.
MADE_POOLS = CABLE_POOLS | frozenset({"?"})
FINISHED_POOLS = frozenset({"50_FMAN", "60_CAT"})
# Conductor families of the conductor module's copper-v2 rule, the plan's purchased N1/N2, and the
# poster's "AL (purchased)" aluminium row. AL is an open point for the owner.
CONDUCTOR_PREFIXES = frozenset({"NX", "DX", "DM", "MNX", "N1", "N2", "AL"})
ALIAS = {"RB2": "RB1", "TN1": "TL1"}  # poster name -> D365 id

_THREE_CHAR = ("XDT", "KDT", "CDT")
_CONSTRUCTION = re.compile(r"^(\d+C\d+(?:\.\d+)?)([A-Z]*)$")


class Parsed(NamedTuple):
    code: str
    construction: str
    colour: str
    parse_ok: bool


def parse_item(item: str) -> Parsed:
    up = item.strip().upper()
    code = up[:3] if up.startswith(_THREE_CHAR) else up[:2]
    parts = up.split("/")
    if len(parts) == 2:
        m = _CONSTRUCTION.match(parts[1])
        if m:
            return Parsed(code, m.group(1), m.group(2), True)
    return Parsed(code, "other", "", False)


def conductor_prefix(item: str) -> bool:
    return item.split("-", 1)[0].upper() in CONDUCTOR_PREFIXES
