"""TriCab cable product-code engine.

Deterministic parser/builder for TriCab product codes, ported from the 4Js
authority module ``M092cd.4gl`` ("code (ida) structure service"). The 4Js system
is the source of truth used internally to turn a cable description into a product
code; this module reproduces its grammar and lookup tables so the RFQ parser can
build and validate codes offline.

Code grammar (catalog dialect)::

    <style><sheath>-<ccolcfg><screen><coating>/<set-count><set-code><csa><colour>
    e.g.  FR-PAXN/1C500RE      C1-CAXD/100C.5BK

The lookup tables live in ``matcher/data/cable_rules/*.json`` and are transcribed
verbatim from the cited 4Js functions. See those files for re-port guidance.
"""

from __future__ import annotations

import json
import re
from functools import lru_cache
from pathlib import Path
from typing import Any

# Bump when the ported rules/tables change so processed rows can record which
# engine produced their code (see TODO.md Priority 3 version tracking).
ENGINE_VERSION = "cable_code-1.0 (4Js M092cd v351.2)"

_RULES_DIR = Path(__file__).resolve().parent / "data" / "cable_rules"

# zone1-zone2/zone3 split: <zone1>-<zone2>/<zone3>
_ZONES_RE = re.compile(r"^([^-/]*)-([^/]*)/(.*)$")
# zone3 head: <count><set-code> ... remainder parsed positionally (see _parse_szcol)
_SZCOL_HEAD_RE = re.compile(r"^(\d+)([CPTQA])(.*)$")
_CSA_MM_RE = re.compile(r"^(\.\d+|\d+(?:\.\d+)?)")
_CSA_AWG_RE = re.compile(r"^(\d+(?:/\d+)?)")


@lru_cache(maxsize=1)
def _families() -> dict[str, Any]:
    return json.loads((_RULES_DIR / "families.json").read_text(encoding="utf-8"))


@lru_cache(maxsize=1)
def _earth() -> dict[str, Any]:
    return json.loads((_RULES_DIR / "earth_maps.json").read_text(encoding="utf-8"))


# Reduced families whose earth treatment is modified by the core-colour-config
# code (FR group); EM/SE map straight to reduced. See _get_szmap_szp.
_REDUCED_MODIFIABLE = {"FR", "CC", "CA", "XA", "XL", "KL", "RL", "KN", "KA", "DA", "CN", "XN", "DP"}


def fmt_csa(value: Any) -> str:
    """Format a cross-section as it appears in a code (drops the leading zero of
    sub-1 metric sizes: ``0.5`` -> ``.5``). AWG sizes (``4/0``) pass through."""
    s = str(value if value is not None else "").strip().upper()
    if not s:
        return ""
    if "/" in s:  # AWG (e.g. 1/0, 4/0)
        return s
    try:
        number = float(s)
    except ValueError:
        return s
    if number.is_integer():
        return str(int(number))
    out = f"{number:f}".rstrip("0").rstrip(".")
    if out.startswith("0."):
        out = out[1:]
    return out


def _csamtt(set_code: str, csa: str) -> str:
    """Metric system of the CSA: 'imp' (AWG/MCM) or 'mks' (metric).

    Mirrors _par_cd_struc_szcol_tail (M092cd.4gl:1050): set-code 'A' is AWG, and
    an instrument set (P/T/Q) with csa > 6 is assumed AWG."""
    if set_code == "A":
        return "imp"
    if set_code in ("P", "T", "Q"):
        try:
            if float(csa) > 6:
                return "imp"
        except ValueError:
            pass
    return "mks"


def _mta(csa: str, mtt: str) -> str:
    """Unit code for a CSA. Mirrors _dtm_csa_mta (M092cd.4gl:1160)."""
    if mtt == "imp":
        if len(csa) > 2 and csa.isdigit():
            return "mcm"
        return "awg"
    return "mm2"


def family_group(stylga: str) -> str:
    """Style family -> group: B/M/S/T/U. Mirrors _x_stylga2stylgg."""
    fam = (stylga or "").upper()
    if len(fam) != 2:
        return "U"
    fam_data = _families()
    if fam in fam_data["group_B"]:
        return "B"
    if fam in fam_data["group_M"]:
        return "M"
    if fam in fam_data["group_T"]:
        return "T"
    return "S"


def is_fire_rated(stylga: str) -> bool:
    """True for mica-wrapped (fire-rated) families. Mirrors _is_stylga_mica."""
    return (stylga or "").upper() in set(_families()["mica_fire_rated"])


def set_code_cores(set_code: str) -> int:
    """Cores per set for a set-code (C=1, P=2, T=3, Q=4, A=1). _x_ccfga2cset_ccnt."""
    return int(_families()["set_code_cores"].get((set_code or "").upper(), 1))


def classify_earth(stylga: str, csamtt: str, ccolcfga: str, concoata: str) -> str | None:
    """Return the szmap letter for a style's earth treatment, or ``None`` when the
    family has no special earth handling. Mirrors _get_szmap_szp (M092cd.4gl:1611)."""
    fam = (stylga or "").upper()
    cfg = (ccolcfga or "").upper()
    coat = (concoata or "").upper()
    earth = _earth()
    szp: str | None = None

    if fam in ("EM", "SE"):
        szp = "R"
    elif fam in _REDUCED_MODIFIABLE:
        if cfg in ("PB", "PH"):
            return None  # leave as-is
        if cfg != "P9":  # P9 = full earth, no reduction
            szp = "R"
        if cfg == "MQ":  # half earth
            szp = "A"
        if cfg == "MI":  # two-fifths
            szp = "F"
        if coat == "A" and cfg != "P9":  # aluminium conductor
            szp = "Q"
    elif fam in earth["split_families"]:
        szp = "S"
    elif fam in earth["split2_families"]:
        szp = "D"
    elif fam in earth["split3_families"]:
        szp = "G"
    elif fam in earth["pilot_families"]:
        szp = "P"

    if szp == "P":
        return "P"
    if szp == "S":
        return "C" if csamtt == "imp" else "S"
    if szp == "D":
        return "E" if csamtt == "imp" else "D"

    # half-size earths (P7/PM) only apply to reduced families
    if szp in ("R", "Q", "A", "F", "B") and cfg in ("P7", "PM"):
        szp = "A" if csamtt == "mks" else "B"
    return szp


def resolve_earth(szp: str | None, set_cnt: int, csa: str) -> dict[str, Any]:
    """Compute main/earth conductor counts and earth CSA for a classified style.

    Mirrors _x_szmap_szp (M092cd.4gl:2159). Returns main core count, earth core
    count, earth CSA and its unit."""
    result = {"ccnt_main": set_cnt, "ccnt_earth": 0, "csa_earth": "", "mta_earth": ""}
    if not szp:
        return result
    csa = fmt_csa(csa)

    if szp == "P":  # pilots (metric only)
        try:
            wcsa = float(csa)
        except ValueError:
            wcsa = 0.0
        if set_cnt == 4:
            result["ccnt_earth"] = 2 if wcsa < 10 else 4
            result["csa_earth"] = "1.5"
            result["mta_earth"] = "mm2"
        elif set_cnt == 5:
            if wcsa < 10:
                result["ccnt_earth"] = 0
            else:
                result["ccnt_earth"] = 5
                result["csa_earth"] = "1.5"
                result["mta_earth"] = "mm2"
        return result

    szmap = _earth()["szmaps"].get(szp)
    if not szmap:
        return result

    do_map = False
    if szmap["_kind"] == "reduced" and set_cnt > 2:
        result["ccnt_main"] = set_cnt - 1
        do_map = True
    elif szmap["_kind"] == "split" and set_cnt > 3:
        result["ccnt_main"] = set_cnt - 1
        do_map = True

    if do_map:
        csa_earth = szmap["map"].get(csa)
        if csa_earth:
            result["ccnt_earth"] = szmap["earth_ccnt"]
            result["csa_earth"] = csa_earth
            result["mta_earth"] = _mta(csa_earth, szmap["mtt"])
    return result


def mm2_to_awg(csa_mm2: Any) -> tuple[str, str] | None:
    """Convert a metric CSA to AWG/MCM, or ``None`` if it cannot map.

    Mirrors x_csamm2_2awg + _bld_csamap (M092cd.4gl:2308). Returns (value, unit)."""
    earth = _earth()
    key = fmt_csa(csa_mm2)
    if key in set(earth["non_mappable_csa"]):
        return None
    entry = earth["csamap"].get(key)
    if not entry:
        return None
    return entry[0], entry[1]


def _parse_szcol(zone3: str) -> tuple[int, str, str, str, str] | None:
    """Split zone3 into (count, set-code, csa, colour, tail).

    Mirrors _par_cd_struc_szcol_tail (M092cd.4gl:1050): read the leading count and
    set-code, then the CSA as a number (AWG may contain '/'), then exactly the next
    two characters as the sheath colour, then the remainder as the tail."""
    head = _SZCOL_HEAD_RE.match(zone3)
    if not head:
        return None
    set_cnt = int(head.group(1))
    set_code = head.group(2)
    rest = head.group(3)

    csa_re = _CSA_AWG_RE if set_code == "A" else _CSA_MM_RE
    csa_match = csa_re.match(rest)
    if not csa_match:
        return None
    csa = csa_match.group(1)
    rest = rest[csa_match.end():]

    colour = rest[:2]
    tail = rest[2:]
    return set_cnt, set_code, csa, colour, tail


def parse_code(code: str, dialect: str = "cat") -> dict[str, Any]:
    """Parse a product code into its elemental fields plus a ``valid`` flag and
    resolved earth. Mirrors par_cd_struc / rtn_cd_struc_fd (M092cd.4gl:596/638)."""
    raw = (code or "").strip().upper()
    out: dict[str, Any] = {"code": raw, "valid": False}
    match = _ZONES_RE.match(raw)
    if not match:
        return out

    zone1, zone2, zone3 = match.group(1), match.group(2), match.group(3)
    stylga = zone1[:2]
    sheath = zone1[2:]
    ccolcfga = zone2[:2]
    scra = zone2[2:3]
    concoata = zone2[3:4]

    szcol = _parse_szcol(zone3)
    if not szcol:
        return out
    set_cnt, set_code, raw_csa, colour, tail = szcol
    csa = fmt_csa(raw_csa)

    # cd.sd_parts: stylga len 2, zone2 len > 3, zone3 len > 2
    valid = len(stylga) == 2 and len(zone2) > 3 and len(zone3) > 2

    csamtt = _csamtt(set_code, csa)
    szp = classify_earth(stylga, csamtt, ccolcfga, concoata)
    earth = resolve_earth(szp, set_cnt, csa)

    out.update(
        {
            "valid": valid,
            "style": stylga,
            "stylga": stylga,
            "stylgg": family_group(stylga),
            "sheath": sheath,
            "config_core": ccolcfga,
            "ccolcfga": ccolcfga,
            "armour": scra,
            "scra": scra,
            "conductor": concoata,
            "concoata": concoata,
            "count": set_cnt,
            "cset_cnt": set_cnt,
            "core_type": set_code,
            "ccfga": set_code,
            "cross_section": csa,
            "csa": csa,
            "csamtt": csamtt,
            "mta": _mta(csa, csamtt),
            "colour": colour,
            "tail": tail,
            "fire_rated": is_fire_rated(stylga),
            "set_cores": set_code_cores(set_code),
            "earth_szp": szp,
            **earth,
        }
    )
    return out


_UNIT_DISPLAY = {"mm2": "mm²", "awg": "AWG", "mcm": "MCM"}
_SET_NOUN = {"P": "pair", "T": "triad", "Q": "quad"}
_CONDUCTOR_MATERIAL = {"N": "Cu", "D": "tinned Cu", "A": "Al"}


def _size_phrase(csa: str, unit: str) -> str:
    disp = _UNIT_DISPLAY.get(unit, unit or "mm²")
    return f"{csa}{disp}" if unit == "mm2" else f"{csa} {disp}"


def describe_size(parsed: dict[str, Any]) -> str:
    """One-line conductor description from a parsed code, e.g. ``4C 500mm² + 1C
    120mm² earth`` or ``8 pair 1.5mm²``. Reproduces the size portion that 4Js
    builds in M069cat (_fmt_cata_struc_szds)."""
    if not parsed.get("valid"):
        return ""
    # When an earth is split out, the total core count already includes it, so the
    # main-conductor count is ccnt_main (e.g. a 4C code with a reduced earth is
    # "3C ... + 1C earth", not "4C ... + 1C earth").
    count = parsed["ccnt_main"] if parsed.get("ccnt_earth") else parsed["count"]
    set_code = parsed["core_type"]
    noun = _SET_NOUN.get(set_code)
    if noun:
        core = f"{count} {noun}" + ("s" if count != 1 else "")
    else:
        core = f"{count}C"

    phrase = f"{core} {_size_phrase(parsed['csa'], parsed.get('mta', 'mm2'))}"
    if parsed.get("ccnt_earth"):
        earth = _size_phrase(parsed["csa_earth"], parsed.get("mta_earth", "mm2"))
        phrase += f" + {parsed['ccnt_earth']}C {earth} earth"
    return phrase


def describe_code(parsed: dict[str, Any], style_meta: dict[str, Any] | None = None) -> str:
    """Human-readable description of a parsed code. Combines the style-level
    metadata from the catalog workbook (Title/Application/Voltage) with the
    conductor size/earth structure the engine derives."""
    if not parsed.get("valid"):
        return ""
    size = describe_size(parsed)
    material = _CONDUCTOR_MATERIAL.get((parsed.get("conductor") or "").upper(), "")
    body = " ".join(part for part in (size, material) if part)

    extras = []
    if parsed.get("fire_rated"):
        extras.append("fire-rated")
    if parsed.get("colour"):
        extras.append(f"{parsed['colour']} sheath")
    if extras:
        body = f"{body} ({', '.join(extras)})"

    title = (style_meta or {}).get("title", "").strip()
    voltage = (style_meta or {}).get("voltage", "").strip()
    head = " ".join(part for part in (title, voltage) if part)
    return f"{head}: {body}" if head else body


def build_code(fields: dict[str, Any], dialect: str = "cat") -> str:
    """Build a canonical catalog product code from structured fields.

    Mirrors the catalog assembly in x_stka2cata (M092cd.4gl:427):
    ``stylga + sheath + '-' + (ccolcfg+screen+coating) + '/' + (cnt+set+csa+colour)``.
    Accepts the RFQ resolver's field names (style/config_core/armour/conductor/
    count/core_type/cross_section/colour). Raises ``ValueError`` if required parts
    are missing."""
    style = str(fields.get("style") or fields.get("stylga") or "").upper()
    sheath = str(fields.get("sheath") or "").upper()
    ccolcfga = str(fields.get("config_core") or fields.get("ccolcfga") or "").upper()
    scra = str(fields.get("armour") or fields.get("scra") or "").upper()
    concoata = str(fields.get("conductor") or fields.get("concoata") or "").upper()
    count = fields.get("count") if fields.get("count") is not None else fields.get("cset_cnt")
    set_code = str(fields.get("core_type") or fields.get("ccfga") or "C").upper()
    csa = fmt_csa(fields.get("cross_section") if fields.get("cross_section") is not None else fields.get("csa"))
    colour = str(fields.get("colour") or "").upper()

    if len(style) != 2 or not ccolcfga or count in (None, "") or not csa:
        raise ValueError("insufficient fields to build a product code")

    var = f"{ccolcfga}{scra}{concoata}"
    szcol = f"{count}{set_code}{csa}{colour}"
    return f"{style}{sheath}-{var}/{szcol}"
