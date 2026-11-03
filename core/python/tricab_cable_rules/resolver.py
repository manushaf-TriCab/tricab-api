from __future__ import annotations

import re
from dataclasses import replace
from typing import Any

from .accessories import parse_accessory_code
from .accessory_text import read_accessory_line
from .cable_code import ENGINE_VERSION, build_code, describe_code, fmt_csa, parse_code
from .data import Product, ProductCatalog, Style
from .extractor import (
    FIELD_SOURCE_AGENT,
    FIELD_SOURCE_CUSTOMER,
    FIELD_SOURCE_DEFAULT,
    LS_FLEX_STYLE_RE,
    LS_R125_STYLE_RE,
    SINGLE_INSULATED_RE,
    extract_entities,
    apply_instrument_core_rule,
    mentions_high_temp,
)
from .normalize import canonical_number


POWER_ADJACENT = {"Power", "VSD/VFD", "Fire Resistant", "Battery/DC", "Welding", "Submersible"}
# A split-earth (3C+3E-style) cable auto-routes to the VSD/VFD screened family unless
# the customer named one of these genuinely different applications (see match_line).
_NON_VSD_SPLIT_EARTH_HINTS = {"Instrumentation", "Signal", "Solar", "Submersible", "Welding", "Battery/DC"}
# When more than one distinct style survives every gate, the construction pick is
# ambiguous: flag it for the rep and cap confidence at this ceiling.
STYLE_TIE_CONFIDENCE_CAP = 0.8
# Construction attributes that may be defaulted from the style rather than stated
# by the customer; reported as inferred so the rep knows what to double-check.
REPORTED_INFERABLE = ("style", "sheath", "cable_type", "conductor", "colour", "config_core", "armour", "count", "core_type")
STYLE_HINT_REASONS = {
    "FD": "Flat cable requested",
    "CD": "LSZH flexible termite/nylon cable requested",
}
# "radox" is a brand name customers use for the fire-rated range (confirmed by Manusha
# 2026-08-03) -- it reads as a fire signal everywhere this regex is consulted: the
# termite/armoured ladder guards, the FR style boost, and the earth LP->LS promotion.
FIRE_RATED_RE = re.compile(r"\b(?:fire\s*(?:rated|resistant)|fr(?:\s*110)?|radox)\b", re.I)
SOLAR_RE = re.compile(r"\b(?:solar|pv)\b", re.I)

# House defaults for under-specified LV power cables (confirmed with TriCab 2026-06-18).
# When the customer states cores/size/conductor but NOT sheath material or temperature,
# pick the standard fixed-wiring style rather than a special-application variant, and
# report the assumption as inferred so the rep can override.
#   sheath:  PVC by default; LSZH only if stated; fire -> LSZH FR
#   PVC temp: XL (90 deg C) by default, KL (110 deg C) when high-temp/110 is stated
#   LSZH:    CC standard (EH is the SDI variant, only when "single/double insulated"
#            is spelled out -- the bare acronym "SDI" is too generic to route here)
# A special-application style is never auto-preferred unless its feature is requested.
# Maps a special-application style code -> tokens that justify choosing it.
SPECIAL_APPLICATION_STYLES = {
    "KK": ("submersible", "pump", "bore"),
    "KF": ("submersible", "potable"),
    "OD": ("submersible", "pump", "bore"),
    "KN": ("termite", "nylon"),
    "CN": ("termite", "nylon"),
    "XN": ("termite", "nylon"),
    # SDI variants of the nylon/termite family (KD/CD/LN/XD -- the "SDI" siblings of
    # KN/CN/XN). These carry "SDI" in their title, so the word "SDI" in a plain RFQ used
    # to hand LN a free keyword bonus and let it outrank the standard KL/XL fixed-wiring
    # style. Gate them the same way: never auto-pick unless termite/nylon is requested.
    "KD": ("termite", "nylon"),
    "CD": ("termite", "nylon"),
    "LN": ("termite", "nylon"),
    "XD": ("termite", "nylon"),
    # NB: DP is NOT a special-application style. It is the bare screened/VSD default
    # (90C PVC, single earth) when EMC is absent. Explicit EMC follows the
    # XU/KU/EU/EA tree below (TriCab 2026-09-24).
    "EZ": ("heavy duty", "flexing", "trailing", "reeling", "festoon"),
    "KG": ("braid",),
    # EH is the LSZH SDI fixed-wiring style, but the bare acronym "SDI" is a GENERIC
    # construction descriptor (it sits in the title of a dozen styles: EH, LN, KD, V1,
    # DL, ...), not a special-application feature. Keying EH on "sdi" made any RFQ that
    # merely says "SDI" flip _special_application_requested True, which silently disabled
    # the whole XL/KL/CC temperature ladder (e.g. "1C 70mm 110 SDI XLPE/PVC" stopped
    # resolving to KL). Justify EH only by the explicit long-form phrase.
    "EH": ("single double", "double insulat"),
}
# Application ranges added 2026-11-02 from the AU website. Each is a heavy-duty variant
# for one industry: gated like the styles above, and boosted in rank_styles when its
# industry is named, since naming the industry is as good as naming the style.
APPLICATION_RANGE_STYLES = {
    "AR": ("ship loader", "shiploader", "crane", "kevlar", "anti-twist"),
    "SC": ("ship loader", "shiploader", "crane"),
    "E1": ("ship loader", "shiploader", "crane"),
    "T3": ("ship loader", "shiploader", "crane"),
    "T4": ("ship loader", "shiploader", "crane"),
    "F1": ("food", "beverage"),
    "FB": ("food", "beverage"),
    "FV": ("food", "beverage"),
    "OT": ("water treatment", "pilot"),
    "WT": ("water treatment",),
    "H7": ("temporary power", "hi-flex", "hi flex"),
    "P3": ("temporary power",),
    "P4": ("temporary power",),
    "SH": ("shore power", "shorepower"),
}
SPECIAL_APPLICATION_STYLES.update(APPLICATION_RANGE_STYLES)

# The nylon / termite-resistant family. XN/KN/CN are the standard extruded-nylon
# styles -- nylon is part of the style itself, so their codes carry no "T" suffix
# (XN-PAXN/..., not XNT). XD/KD/CD are the "SDI" building-wire siblings, and it is
# THOSE that take the "T" jacket suffix (XDT/KDT/CDT). When termite/nylon resistance
# is requested (extractor sets fields["termite"]), a plain line resolves to XN/KN/CN
# by the same temperature ladder as power (no temp/90C -> XN, 110C -> KN, LSZH -> CN);
# the SDI siblings and off-temperature tiers are de-preferred so the family collapses
# to one answer.
_NYLON_TERMITE_STYLES = {"XN", "KN", "CN", "KD", "CD", "LN", "XD"}


def _special_application_requested(haystack: str) -> bool:
    """True when the RFQ text names any special-application feature, in which case
    the standard fixed-wiring house default must NOT be imposed - the requested
    special construction stands (and stays flagged for the rep to confirm)."""
    return any(
        token in haystack
        for tokens in SPECIAL_APPLICATION_STYLES.values()
        for token in tokens
    )


def house_default_delta(style: Style, fields: dict[str, Any], haystack: str) -> float:
    """Scoring nudge implementing the confirmed house defaults. Applied after the
    base cap so it breaks ties between otherwise-equal styles without overriding
    explicit customer signals (which are handled by gates/bonuses upstream)."""
    # Marine lines are ranked by the marine matrix instead (_marine_target_style_code).
    # The mainstream ladder would actively fight it: every marine style is LSZH-sheathed,
    # so the "PVC is the default" branch below de-prefers the whole range and boosts XL.
    if fields.get("marine"):
        return 0.0
    delta = 0.0

    # 1. Never auto-pick a special-application style the customer didn't ask for.
    justification = SPECIAL_APPLICATION_STYLES.get(style.code)
    if justification and not any(token in haystack for token in justification):
        delta -= 0.08

    if fields.get("earth_only"):
        return delta

    # Termite/nylon requested -> XN/KN/CN temperature ladder (TriCab 2026-07-07),
    # parallel to the XL/KL/CC power ladder. Boost the temperature-right nylon style
    # and de-prefer the sibling nylon/SDI styles so the family collapses to one answer
    # (e.g. XN) instead of tying across XN/XD/KD/LN. Skipped when a style_hint is
    # already locked (e.g. the CD flexible-LSZH-termite combination -> CDT).
    if (
        fields.get("termite")
        and not fields.get("style_hint")
        and not fields.get("armoured")
        and not FIRE_RATED_RE.search(haystack)
    ):
        target = _house_default_style_code(fields, haystack)
        if target and style.code == target:
            delta += 0.10
        elif style.code in _NYLON_TERMITE_STYLES:
            delta -= 0.04
        return delta

    # A requested special application overrides the standard default: don't boost the
    # fixed-wiring styles, let the special construction stand (flagged for the rep).
    if _special_application_requested(haystack):
        return delta

    # Armoured ladder (no temp/90 -> XA, 110 -> KA, >110/LSZH -> CA), parallel to the
    # unarmoured XL/KL/CC bonuses below. The +0.10 matches the fixed-wiring bonus so the
    # temperature-appropriate armoured style wins its tier; sibling armoured tiers are
    # de-preferred. Fire+armour has no target (FR is unarmoured) and stays flagged.
    if fields.get("armoured"):
        target = _house_default_style_code(fields, haystack)
        if target and style.code == target:
            delta += 0.10
        elif style.code in {"XA", "KA", "CA"}:
            delta -= 0.04
        return delta

    is_fire = bool(FIRE_RATED_RE.search(haystack))
    if is_fire or fields.get("lszh"):
        # 2a. LSZH answer: fire -> FR, otherwise the standard CC (not the SDI/termite variants).
        if is_fire and style.code == "FR":
            delta += 0.12
        elif fields.get("lszh") and style.code == "CC":
            delta += 0.08
        if fields.get("lszh") and not is_fire and style.sheath_pvc and not style.sheath_lszh:
            delta -= 0.04
        return delta

    # 2b. PVC answer (sheath unstated, or PVC stated): pick XL (90C) by default, KL when hot.
    if style.sheath_lszh and not style.sheath_pvc and not fields.get("pvc"):
        delta -= 0.06  # PVC is the default; de-prefer LSZH families
    hot = mentions_high_temp(haystack)
    if style.code == ("KL" if hot else "XL"):
        delta += 0.10  # standard PVC fixed-wiring at the right temperature
    elif style.code in {"XL", "KL", "CC"}:
        # De-prefer the wrong-temperature fixed-wiring sibling, mirroring the armoured
        # ladder above. An explicit 110C line must not let the 90C XL tie the 110C KL.
        delta -= 0.04
    return delta


def _house_default_style_code(fields: dict[str, Any], haystack: str) -> str | None:
    """The single style the house default lands on (for the inferred note + lock).

    When armour is requested the ladder shifts to the armoured family (confirmed with
    TriCab 2026-06-29): no temp/90C -> XA, 110C -> KA, >110C/LSZH -> CA. This mirrors
    the unarmoured XL/KL/CC rule below. FR has no armoured variant, so an armoured fire
    request returns None and falls through to flag/clarify rather than forcing FR."""
    # The marine range has its own ladder, with BV where the mainstream one has XL.
    if fields.get("marine"):
        return None
    if fields.get("style_hint"):
        return None
    if fields.get("earth_only"):
        return None
    armoured = bool(fields.get("armoured"))
    is_fire = bool(FIRE_RATED_RE.search(haystack))
    # Termite/nylon requested (TriCab 2026-07-07): the nylon/termite-resistant family
    # has its own temperature ladder -- no temp/90C -> XN, 110C -> KN, >110C/LSZH -> CN
    # -- mirroring XL/KL/CC. Checked before the generic special-application suppression
    # (which would otherwise return None for a termite/nylon line). Only the unarmoured,
    # non-fire tier is defined; armoured or fire termite falls through to the existing
    # ladders / review.
    if fields.get("termite") and not armoured and not is_fire:
        if fields.get("lszh"):
            return "CN"
        if mentions_high_temp(haystack):
            return "KN"
        return "XN"
    if _special_application_requested(haystack):
        return None
    if is_fire:
        return None if armoured else "FR"
    if fields.get("lszh"):
        return "CA" if armoured else "CC"
    if mentions_high_temp(haystack):
        return "KA" if armoured else "KL"
    return "XA" if armoured else "XL"


# --- Marine / offshore ----------------------------------------------------------
# TriCab's marine range (data/catalog/marine_offshore.xlsx) is three families that share
# one construction grammar. Confirmed against the workbook's own application label, and
# cross-checked three ways: cable_code families.json already lists 19 of these codes as
# 4Js family group "M", its mica_fire_rated list matches the fire column exactly, and
# rfq_api.php's calculator already maps shipboard->BV, shipboard-braided->DV, offshore->PJ
# with earth style UP.
#
#   shipboard  IEC 60092, no bedding -- braid (when present) is a screen
#   offshore   braid ARMOUR: a braid over a bedding, so every member is armoured
#   nek        NEK TS606 (Norwegian offshore), the N-series, cable types P1/P8, P5/P12,
#              RU P18, BU P17
#
# Keys are (fire, braid). Offshore and NEK-braided are braid-armoured by construction, so
# where a family has no unbraided or no braided member the same style serves both keys
# rather than leaving the line unresolved.
_MARINE_POWER = {
    "shipboard": {(False, False): "BV", (False, True): "DV", (True, False): "RR", (True, True): "BH"},
    # Offshore power/control is braid-armoured in both keys: PJ and HT have no
    # unbraided sibling, and an offshore line has already asked for that construction.
    "offshore": {(False, False): "PJ", (False, True): "PJ", (True, False): "HT", (True, True): "HT"},
    "nek": {(False, False): "NV", (False, True): "NJ", (True, False): "NR", (True, True): "NT"},
}
# Instrumentation, split by screen type. Collective is the base case; "ind+coll" needs an
# explicit individual-screen request (I/S, IOS, IOAS, "individual"), matching
# _instrument_target_style_code.
_MARINE_INSTRUMENT = {
    "shipboard": {
        "coll": {(False, False): "RC", (False, True): "RS", (True, False): "ES", (True, True): "FJ"},
        "ind+coll": {(False, False): "EW", (False, True): "EG", (True, False): "FC", (True, True): "FL"},
    },
    "offshore": {
        "coll": {(False, False): "RB", (False, True): "RB", (True, False): "FK", (True, True): "FK"},
        "ind+coll": {(False, False): "FZ", (False, True): "FZ", (True, False): "FE", (True, True): "FE"},
    },
    # NEK TS606 has no instrumentation member in this range -- such a line has no target
    # and falls through to be flagged for Technical rather than answered with a guess.
    "nek": {},
}
# VSD/VFD is braid-screened (shipboard) or braid-armoured (NEK); there is no offshore
# member, and no non-braided variant of either.
_MARINE_VSD = {"shipboard": "DF", "nek": "NU"}
# The marine switchboard/panel wire, and the marine earth conductor (confirmed by Manusha
# 2026-08-18: "the default earth cable for marine is UP (UPA)"). Its own label reads
# "Shipboard & Offshore Switchboard/Panel Wire", so it serves every marine family.
MARINE_EARTH_STYLE = "UP"

# Marine jacket (zone-1 sheath) compounds, per TriCab's marine jacket table. A is the
# default across the shipboard/offshore range; the NEK N-series is manufactured only in
# C and M. Used for the human-readable jacket name in warnings; the extractor holds the
# matching patterns (MARINE_JACKET_PATTERNS).
MARINE_JACKET_NAMES = {
    "A": "SHF 1",
    "B": "SHF 2",
    "C": "NEK606 SHF-2",
    "D": "SW4",
    "E": "TPE",
    "F": "PVC",
    "M": "NEK606 MUD",
    "P": "Low Friction",
    "T": "Anti Termite / Anti Rodent",
}


def _marine_target_style_code(fields: dict[str, Any], haystack: str) -> str | None:
    """The single marine style a line lands on, or None when the range has no member
    for the requested combination (e.g. NEK instrumentation, offshore VSD).

    Parallel to _house_default_style_code for the mainstream power ladder: BV is the
    marine equivalent of XL, the answer for an under-specified shipboard line
    (confirmed by Manusha 2026-08-18).
    """
    if not fields.get("marine"):
        return None
    family = fields.get("marine_family") or "shipboard"

    # Earth and panel wire are UP for every family, before any other axis.
    if fields.get("earth_only") or fields.get("switchboard"):
        return MARINE_EARTH_STYLE

    fire = bool(fields.get("fire") or FIRE_RATED_RE.search(haystack))
    # An offshore/NEK braid armour IS the armour, so an armour request reads as braid.
    braid = bool(fields.get("braid") or fields.get("armoured"))

    is_instrument = (
        fields.get("cable_type_hint") == "Instrumentation"
        or fields.get("core_type") in {"P", "T", "Q"}
    )
    if is_instrument:
        screen_type = "ind+coll" if fields.get("screen_type") == "ind+coll" else "coll"
        return _MARINE_INSTRUMENT.get(family, {}).get(screen_type, {}).get((fire, braid))
    if fields.get("cable_type_hint") == "VSD/VFD":
        return _MARINE_VSD.get(family)
    return _MARINE_POWER.get(family, {}).get((fire, braid))


_MARINE_REASON = {
    "BV": "marine, shipboard power & control",
    "DV": "marine, shipboard braided power & control",
    "RR": "marine, shipboard fire resistant power & control",
    "BH": "marine, shipboard fire resistant braided power & control",
    "DF": "marine, shipboard braided VSD/VFD",
    "RC": "marine, shipboard collective-screen instrumentation",
    "RS": "marine, shipboard braided collective-screen instrumentation",
    "ES": "marine, shipboard fire resistant collective-screen instrumentation",
    "FJ": "marine, shipboard fire resistant braided collective-screen instrumentation",
    "EW": "marine, shipboard individual & collective screen instrumentation",
    "EG": "marine, shipboard braided individual & collective screen instrumentation",
    "FC": "marine, shipboard fire resistant individual & collective screen instrumentation",
    "FL": "marine, shipboard fire resistant braided individual & collective screen instrumentation",
    "UP": "marine switchboard/panel wire",
    "PJ": "offshore, braid armoured power & control",
    "HT": "offshore, braid armoured fire resistant power & control",
    "RB": "offshore, braid armoured collective-screen instrumentation",
    "FK": "offshore, braid armoured fire resistant collective-screen instrumentation",
    "FZ": "offshore, braid armoured individual & collective screen instrumentation",
    "FE": "offshore, braid armoured fire resistant individual & collective screen instrumentation",
    "NV": "NEK TS606 RU P18 power & control",
    "NR": "NEK TS606 BU P17 fire resistant power & control",
    "NJ": "NEK TS606 P1/P8 braid armoured power & control",
    "NT": "NEK TS606 P5/P12 braid armoured fire resistant power & control",
    "NU": "NEK TS606 P1/P8 braid armoured VSD/VFD",
}


def _vsd_target_style_code(fields: dict[str, Any], haystack: str) -> str:
    """Screened / VSD / VFD family target (confirmed with TriCab 2026-06-30).

    Fire-rated VSD -> FH (the FR style is for non-VSD fire). Otherwise the
    XU/KU/EU/EA family applies to explicit EMC requests regardless of earth count
    (TriCab 2026-09-24), or symmetric multi-/split-earth configs (e.g. 3C+3E):
    XU (90C PVC) -> KU (110C PVC) -> EU (LSZH), and EA when armoured.
    Without EMC, every other core config is DP (90C PVC screened);
    if such a line also asks for LSZH/armour, DP is gated out and it falls to
    clarification (e.g. "4C+E VSD LSZH" is not manufactured -> TSD)."""
    if fields.get("fire"):
        return "FH"
    if re.search(r"\bEMC\b", haystack, re.I) or (fields.get("earth_count") or 0) >= 2:
        if fields.get("armoured"):
            return "EA"
        if fields.get("lszh"):
            return "EU"
        if mentions_high_temp(haystack):
            return "KU"
        return "XU"
    return "DP"


def _instrument_target_style_code(fields: dict[str, Any], catalog: ProductCatalog) -> str | None:
    """Instrumentation family target (confirmed with TriCab 2026-06-30), derived from the
    catalog Style attributes + title rather than a hardcoded matrix.

    Selection is voltage band first (<=110 -> 110V styles, <=750 -> 450/750V, else
    0.6/1kV; no voltage stated defaults to the 110V band -- our decision 2026-06-30),
    then collective vs individual+collective screen (title "Individual & Coll."), sheath
    (PVC default), armour, and braid. WH is the only fire-rated instrument style and wins
    across all bands when fire is requested."""
    if fields.get("fire"):
        return "WH"
    vu = fields.get("voltage_u") or 110
    target_vu = 110 if vu <= 110 else (750 if vu <= 750 else 1000)
    want_indiv = fields.get("screen_type") == "ind+coll"
    want_lszh = bool(fields.get("lszh"))
    want_pvc = bool(fields.get("pvc"))
    want_armour = bool(fields.get("armoured"))
    want_braid = bool(fields.get("braid"))

    best_code, best_score = None, -1e9
    for style in catalog.styles.values():
        if style.cable_type != "Instrumentation" or style.voltage_u != target_vu:
            continue
        title = style.haystack
        if "fire" in title:
            continue  # fire is handled above; never auto-pick WH for a non-fire line
        is_indiv = "individual" in title
        has_braid = bool(style.braiding.strip())
        score = 0
        score += 2 if is_indiv == want_indiv else -2
        score += 2 if style.has_armour == want_armour else -2
        score += 1 if has_braid == want_braid else -1
        if want_lszh:
            score += 2 if style.sheath_lszh else -2
        elif want_pvc:
            score += 2 if style.sheath_pvc else -2
        else:
            score += 1 if style.sheath_pvc else 0  # PVC is the default sheath
        if score > best_score:
            best_score, best_code = score, style.code
    return best_code


def _family_target_style_code(fields: dict[str, Any], haystack: str, catalog: ProductCatalog) -> str | None:
    """Target style for the explicitly-signalled screen families (screened/VSD and
    instrumentation), parallel to _house_default_style_code for the power/armour ladders.
    Returns None for every other line, which keeps the existing power/control behaviour."""
    # Marine first, and exclusively: the marine range answers its own lines, and when it
    # has no member for the combination (NEK instrumentation, offshore VSD) the answer is
    # None -- falling through to a mainstream style would offer a cable with no class
    # society approval, which is worse than flagging the line for Technical.
    if fields.get("marine"):
        return _marine_target_style_code(fields, haystack)
    hint = fields.get("cable_type_hint")
    is_instrument = hint == "Instrumentation" or fields.get("core_type") in {"P", "T", "Q"}
    if is_instrument:
        return _instrument_target_style_code(fields, catalog)
    if hint == "VSD/VFD" or (fields.get("screen") and not is_instrument):
        return _vsd_target_style_code(fields, haystack)
    # Plain-core signal/alarm -> WG, the fire-rated signal cable (confirmed TriCab
    # 2026-06-30). Pairs/triads were already reclassified to Instrumentation upstream.
    if hint == "Signal" and "WG" in catalog.styles:
        return "WG"
    return None


_HOUSE_DEFAULT_REASON = {
    "XL": "PVC, 90C, standard fixed wiring",
    "KL": "PVC, 110C high-temp",
    "CC": "LSZH, standard fixed wiring",
    "FR": "LSZH, fire resistant",
    "XA": "PVC, 90C, armoured",
    "KA": "PVC, 110C, armoured",
    "CA": "LSZH, armoured",
    "XN": "PVC, 90C, nylon termite-resistant",
    "KN": "PVC, 110C, nylon termite-resistant",
    "CN": "LSZH, nylon termite-resistant",
}

# Default construction/class code (config_core) per style when the customer doesn't
# state it. House rule (confirmed with TriCab): for a power-capable style, an
# under-specified line defaults to power -> PA (the standard orange config) rather
# than the control (CA/black) variant. This only resolves a residual power/control
# split for small-section multi-core lines (e.g. 3C 1.5) where both variants exist;
# it is a no-op when PA isn't a surviving candidate (so control-only CA/CB splits are
# untouched), and the collapse below is additionally skipped when the RFQ signals
# control. Styles not listed keep flagging the construction-code choice for the rep.
#   XL -> PA confirmed 2026-06-18; KL/KA/FR/CC/CA extended 2026-06-24 (same rule).
STYLE_DEFAULT_CONFIG = {
    "XL": "PA",
    "KL": "PA",
    "XA": "PA",
    "KA": "PA",
    "FR": "PA",
    "CC": "PA",
    "CA": "PA",
}
# The heavy-duty application ranges stock 1C as PA and 3-5C as PD (and >5C as CA, via
# CONTROL_CORE_MIN), whatever config their design example happens to show.
HEAVY_DUTY_PD_STYLES = {"AR", "FB", "H7", "SC", "SH", "WT"}

# Core-count control boundary (confirmed by Manusha 2026-08-26). The catalog has a hard
# discontinuity at 6 cores: 5C *is* 4C+E, the largest power construction, and above that
# you are numbering cores, which is control. 99% of >=6-core plain-`C` products sit in a
# control (C*) config -- only 6 products in the whole 19k catalog are a power config at
# >=6C -- so a line with more than 5 cores takes the control config even when nothing on
# it says "control", and even on a power style (XL/KL/CC/XA/KA/FR) or a VSD/submersible/
# solar/fire style. Control constructions default to a black outer sheath, so an
# unstated-colour request resolves to the C* configuration with BK.
CONTROL_CORE_MIN = 6

# Small single-insulated and earth conductors use LS by default. Above 10 mm2 the
# ordinary earth/single-insulated default remains LP unless the request explicitly
# names the LS construction (LS flex, LSZH, high temperature, fire or switchboard).
LS_SMALL_CONDUCTOR_MAX_MM2 = 10
_EARTH_CONTEXT_RE = re.compile(r"\b(?:EARTH|ECC|G/Y|GN\s*/\s*YE|GREEN\s*/?\s*YELLOW)\b", re.I)
_MAINS_CONTEXT_RE = re.compile(
    r"\b(?:MAINS?|POWER|XLPE|PVC|LSZH|\d+\s*C(?:ORE)?S?)\b", re.I
)


def is_small_ls_conductor(fields: dict[str, Any]) -> bool:
    try:
        return float(canonical_number(fields.get("cross_section"))) <= LS_SMALL_CONDUCTOR_MAX_MM2
    except (TypeError, ValueError):
        return False


def is_high_core_control(fields: dict[str, Any]) -> bool:
    """True when the core count alone makes the line a control construction (>5 cores).

    Only plain numbered ``C`` cores count: pairs/triads/quads (P/T/Q) are
    instrumentation, which owns its own config ladder, and an earth-only row is XX.
    """
    if fields.get("earth_only"):
        return False
    if (fields.get("core_type") or "C").upper() != "C":
        return False
    try:
        return int(canonical_number(fields.get("count"))) >= CONTROL_CORE_MIN
    except ValueError:
        return False


def _modal(values: list[str], fallback: str) -> str:
    """Most common non-empty value (ties -> first seen); ``fallback`` if none."""
    counts: dict[str, int] = {}
    for v in values:
        v = (v or "").upper()
        if v:
            counts[v] = counts.get(v, 0) + 1
    return max(counts, key=counts.get) if counts else fallback


def _modal_sheath(products: list[Product]) -> str:
    """Most common sheath code, counting the blank (standard) sheath as a value. Through
    ``_modal`` the blanks were skipped, so the one XLT product among 45 XL CA rows made
    every unstocked XL control size XLT (termite) -- "16C+E 2.5 Control" -> XLT-CAXD/17C2.5BK."""
    counts: dict[str, int] = {}
    for p in products:
        v = (p.sheath or "").upper()
        counts[v] = counts.get(v, 0) + 1
    return max(counts, key=counts.get) if counts else ""


def _collapse_to_standard_form(
    selected: Product, catalog: ProductCatalog, want_config: str, want_colour: str, want_style: str | None = None
) -> tuple[Product, bool]:
    """Express ``selected`` in a style's standard form (optionally re-homing it to
    ``want_style``). Prefers a stocked product with the wanted style/config_core/colour at
    the same geometry; if none is catalogued (our products CSV is a known-incomplete
    snapshot), synthesises the canonical code and returns it flagged. config_core variants
    like DA<->DE are a numbering convention on the same physical cable, and the style/colour
    are the confirmed standard for the request, so the rewrite is safe. When re-homing to a
    different style, the armour/conductor/sheath codes are taken from that style's standard
    (modal) products rather than the source. Returns ``(product, synthesised)``."""
    want_config = (want_config or "").upper()
    want_colour = (want_colour or "").upper()
    want_style = (want_style or selected.style).upper()
    if (
        selected.style.upper() == want_style
        and (selected.config_core or "").upper() == want_config
        and (selected.colour or "").upper() == want_colour
    ):
        return selected, False
    # The catalog stores sub-1 sizes inconsistently ("0.5" and ".5"); compare on the
    # canonical code form so a stocked row isn't missed and synthesised needlessly.
    csa = fmt_csa(selected.cross_core)
    pool = catalog.products_by_style.get(want_style, [])
    same_style = want_style == selected.style.upper()
    stocked = [
        p for p in pool
        if p.no_core == selected.no_core
        and p.core == selected.core
        and fmt_csa(p.cross_core) == csa
        and (not same_style or (p.armour or "").upper() == (selected.armour or "").upper())
        and (not same_style or (p.conductor_type or "").upper() == (selected.conductor_type or "").upper())
        and (p.config_core or "").upper() == want_config
        and (p.colour or "").upper() == want_colour
    ]
    if stocked:
        return stocked[0], False
    if same_style:
        armour, conductor, sheath = selected.armour, selected.conductor_type, selected.sheath
    else:
        # Re-homing: use the target style's standard armour/conductor/sheath codes.
        ref = [p for p in pool if (p.config_core or "").upper() == want_config] or pool
        armour = _modal([p.armour for p in ref], selected.armour or "X")
        conductor = _modal([p.conductor_type for p in ref], selected.conductor_type or "N")
        sheath = _modal_sheath(ref) if ref else (selected.sheath or "")
    new_code = (
        f"{want_style}{sheath}-{want_config}{armour}{conductor}"
        f"/{selected.no_core}{selected.core}{csa}{want_colour}"
    )
    return replace(
        selected, style=want_style, sheath=sheath, armour=armour, conductor_type=conductor,
        config_core=want_config, colour=want_colour, product_code=new_code,
    ), True


def _standard_product_from_fields(
    fields: dict[str, Any],
    catalog: ProductCatalog,
    want_style: str,
    want_config: str,
    want_colour: str,
) -> Product | None:
    if not fields.get("count") or not fields.get("cross_section"):
        return None
    pool = catalog.products_by_style.get(want_style, [])
    ref = [p for p in pool if (p.config_core or "").upper() == want_config] or pool
    sheath = _modal_sheath(ref)
    armour = _modal([p.armour for p in ref], "X")
    conductor = str(fields.get("conductor") or _modal([p.conductor_type for p in ref], "N")).upper()
    core_type = str(fields.get("core_type") or "C").upper()
    product_fields = {
        **fields,
        "style": want_style,
        "sheath": sheath,
        "config_core": want_config,
        "armour": armour,
        "conductor": conductor,
        "core_type": core_type,
        "colour": want_colour,
    }
    try:
        product_code = build_code(product_fields)
    except ValueError:
        return None
    return Product(
        product_code=product_code,
        style=want_style,
        sheath=sheath,
        config_core=want_config,
        armour=armour,
        conductor_type=conductor,
        no_core=str(fields["count"]),
        core=core_type,
        cross_core=fmt_csa(fields["cross_section"]),
        colour=want_colour,
    )


def _product_from_code(code: str, catalog: ProductCatalog, desc2: str = "") -> Product | None:
    parsed = parse_code(code)
    if not parsed.get("valid"):
        return None
    style_obj = catalog.styles.get(parsed.get("style", ""))
    description = desc2
    if not description:
        style_meta = {"title": style_obj.title, "voltage": style_obj.voltage} if style_obj else None
        description = describe_code(parsed, style_meta)
    return Product(
        product_code=parsed["code"],
        style=parsed["style"],
        sheath=parsed["sheath"],
        config_core=parsed["config_core"],
        armour=parsed["armour"],
        conductor_type=parsed["conductor"],
        no_core=str(parsed["count"]),
        core=parsed["core_type"],
        cross_core=fmt_csa(parsed["cross_section"]),
        colour=parsed["colour"],
        desc2=description,
    )


# AI-sourced field overrides that match_line will merge into the regex-extracted
# fields dict. Deliberately narrow: only the "hard extraction" facts (core count,
# size, conductor, earth handling, requested construction flags) that a model
# reads directly out of messy phrasing. Colour, voltage, style_hint and the
# armour-ladder/LSZH-vs-PVC precedence rules stay on the regex/house-default path
# below, since that logic is under active, careful tuning independent of this.
# explicit_style_hint specifically is never in this whitelist even though the LLM
# can now supply one (see _validated_style_code below) -- it requires catalog
# cross-referencing and a regex-wins precedence rule, not a straight dict overwrite.
LLM_OVERRIDE_KEYS = frozenset(
    {
        "count", "core_type", "cross_section", "conductor",
        "earth", "earth_count", "earth_only",
        "cable_type_hint", "screen", "armoured", "fire",
        "pvc", "lszh",
    }
)


def _validated_style_code(code: Any, catalog: ProductCatalog) -> str | None:
    """Normalize + validate a candidate style/series code against the real catalog
    (catalog.styles, keyed by code). Shared by the regex "X Series" capture
    (extractor.py) and an LLM-reported code (inline or from a section heading) --
    a hallucinated or garbled code from either source is silently dropped here
    rather than corrupting style selection downstream."""
    if not code:
        return None
    normalized = str(code).strip().upper()
    return normalized if normalized in catalog.styles else None


# Single-core building-wire families whose fine conductors are tinned copper.
_TINNED_SMALL_CORE_STYLES = {"LS", "LP", "UP"}


def _enforce_tinned_small_core(result: dict[str, Any], catalog: ProductCatalog) -> None:
    """Strict rule: single-core LS/LP/UP building wire in a metric size <=6mm2 is
    tinned copper -- conductor code 'D', never plain annealed copper 'N'. Fine copper
    conductors are tinned for corrosion resistance/solderability; larger sizes stay
    plain, so the rule stops at 6mm2. This overrides even a customer-supplied code:
    ``LS-XXXN/1C.75OR`` resolves to ``LS-XXXD/1C.75OR``.

    Applied as a post-processor so it catches every resolution path (the exact-code
    shortcut sets style/size inside the code string; the normal path picks the style
    only after ranking). Rewrites to the D-twin and re-selects the priced catalog
    product. When no stocked D-twin exists the 'N' code is kept, since forcing a
    made-to-order D would drop the price (confirmed choice, 2026-07-16)."""
    code = str(result.get("selected_product_code") or "").upper()
    if "/" not in code:
        return
    parsed = parse_code(code)
    if not parsed.get("valid") or parsed["style"] not in _TINNED_SMALL_CORE_STYLES:
        return
    # Metric single cores only: AWG-coded conductors (csamtt 'imp') are out of scope,
    # and the rule targets plain copper ('N') -- aluminium ('A') is never tinned.
    if parsed["core_type"] != "C" or parsed["csamtt"] != "mks" or parsed["conductor"] != "N":
        return
    try:
        if float(parsed["csa"]) > 6:
            return
    except (TypeError, ValueError):
        return

    # concoata is the single char immediately before the '/': swap N -> D, nothing else.
    slash = code.index("/")
    d_code = code[: slash - 1] + "D" + code[slash:]
    d_product = catalog.products_by_code.get(d_code)
    if d_product is None:
        return  # no stocked tinned twin -> keep the 'N' code (see docstring)

    result["selected_product_code"] = d_product.product_code
    result["selected_product"] = d_product.public()
    result["candidates"] = [d_product.public()]
    fields = result.setdefault("fields", {})
    fields["conductor"] = "D"
    if fields.get("canonical_code"):
        fields["canonical_code"] = d_product.product_code
    # The rewrite target came out of products_by_code, so the code on this row is now
    # a stocked one whatever the pre-rewrite code was. Left stale, a perfectly ordinary
    # LP-XXXD/1C4EA still read as canonical_in_catalog=False -- which is what
    # pipeline._unpriceable_note consults to decide whether a line that cannot be
    # priced is a phantom the resolver invented or a real product the Cost API is
    # simply silent about. Two different problems, two different next moves.
    fields["canonical_in_catalog"] = True
    if d_product.desc2:
        fields["description"] = d_product.desc2
    inferred = result.setdefault("inferred_fields", [])
    if "conductor" not in inferred:
        inferred.append("conductor")
    note = f"Tinned copper enforced for {parsed['style']} ≤6mm² core (conductor N→D)."
    warnings = result.setdefault("warnings", [])
    if note not in warnings:
        warnings.append(note)


# Aluminium multicore earths are full size, never reduced -- that is the P9 config.
# P9 exists only at 3 cores and up (a 2C is phase+neutral with no earth core to size),
# so the rule starts there.
_ALUMINIUM_P9_MIN_CORES = 3


def _enforce_aluminium_multicore_p9(result: dict[str, Any], catalog: ProductCatalog) -> None:
    """All aluminium multicores take the P9 config (confirmed by Manusha 2026-08-03).

    ``PA`` is the reduced-earth config -- and for an aluminium conductor the 4Js earth
    map reduces it further still (``concoata == 'A'`` -> szp ``'Q'``, see
    :func:`matcher.cable_code.classify_earth`). Aluminium multicores are built with a
    full-size earth instead, which is exactly what ``P9`` encodes, so ``XL-PAXA/4C25BK``
    resolves to ``XL-P9XA/4C25BK``. This holds whether or not the customer wrote the
    earth separately: an aluminium ``4C+E`` is P9 too, so rule 3 overrides the
    ``nC+E -> PA`` default for aluminium.

    Applied as a post-processor so it catches every resolution path, mirroring
    :func:`_enforce_tinned_small_core`. Two deliberate limits:

    * Only a config the matcher itself inferred is rewritten -- a config the customer
      stated (or supplied inside a full product code) is left alone.
    * When no stocked P9 twin exists the PA code is kept, since forcing a made-to-order
      P9 would drop the price (confirmed choice, 2026-08-03 -- the same call made for
      the tinned-conductor rule). 98 of the 240 aluminium multicore products have no P9
      twin: the whole XN/KN nylon and XU/KU/EA screened families, plus edge sizes.
    """
    if "config_core" not in (result.get("inferred_fields") or []):
        return  # customer stated the config -- theirs wins
    code = str(result.get("selected_product_code") or "").upper()
    if "-" not in code or "/" not in code:
        return
    parsed = parse_code(code)
    if not parsed.get("valid") or parsed.get("config_core") != "PA":
        return
    if parsed["conductor"] != "A" or parsed["core_type"] != "C":
        return
    try:
        if int(parsed["count"]) < _ALUMINIUM_P9_MIN_CORES:
            return
    except (TypeError, ValueError):
        return

    # config_core is the two chars immediately after the '-': swap PA -> P9.
    dash = code.index("-")
    p9_code = code[: dash + 1] + "P9" + code[dash + 3 :]
    p9_product = catalog.products_by_code.get(p9_code)
    if p9_product is None:
        return  # no stocked P9 twin -> keep the PA code (see docstring)

    result["selected_product_code"] = p9_product.product_code
    result["selected_product"] = p9_product.public()
    result["candidates"] = [p9_product.public()]
    fields = result.setdefault("fields", {})
    fields["config_core"] = "P9"
    if fields.get("canonical_code"):
        fields["canonical_code"] = p9_product.product_code
    # Same reasoning as the tinned rewrite above: p9_product came from the catalog.
    fields["canonical_in_catalog"] = True
    if p9_product.desc2:
        fields["description"] = p9_product.desc2
    note = "Aluminium multicore takes the full-size earth config (PA→P9)."
    warnings = result.setdefault("warnings", [])
    if note not in warnings:
        warnings.append(note)


def match_line(
    raw_text: str,
    catalog: ProductCatalog,
    source: dict[str, Any] | None = None,
    context: list[str] | None = None,
    field_overrides: dict[str, Any] | None = None,
    row_interpreted: bool | None = None,
) -> dict[str, Any]:
    """Match one line of already-extracted text against the catalog, then apply the
    strict post-processing rules: small-core tinned copper
    (:func:`_enforce_tinned_small_core`) and the aluminium multicore full-size earth
    config (:func:`_enforce_aluminium_multicore_p9`). The two are disjoint -- tinning
    is single-core copper, P9 is aluminium at 3 cores and up."""
    result = _resolve_line(
        raw_text,
        catalog,
        source=source,
        context=context,
        field_overrides=field_overrides,
        row_interpreted=row_interpreted,
    )
    _enforce_tinned_small_core(result, catalog)
    _enforce_aluminium_multicore_p9(result, catalog)
    return result


def _resolve_line(
    raw_text: str,
    catalog: ProductCatalog,
    source: dict[str, Any] | None = None,
    context: list[str] | None = None,
    field_overrides: dict[str, Any] | None = None,
    row_interpreted: bool | None = None,
) -> dict[str, Any]:
    """Match one line of already-extracted text against the catalog.

    ``field_overrides`` (from an AI-normalized row, see
    :func:`matcher.llm_fallback._entry_from_payload`) are facts the model read
    directly out of the customer's own text -- they are merged straight into
    the regex-extracted fields dict, in place of what regex found for the same
    keys, with no intermediate free-text re-parsing step. Everything else
    (colour, voltage, style hints, comms/accessory/admin routing) still comes
    from regex over ``raw_text`` as before.

    ``row_interpreted`` says whether a model has actually interpreted THIS row's
    construction. It defaults to "yes if there are overrides", which is what the
    normalizer means by passing them. A scoped directive (plan §B3) is different:
    it modifies a row without having read its construction, so it passes False
    and the parallel-single-core safety net below stays armed.
    """
    if row_interpreted is None:
        row_interpreted = field_overrides is not None
    source = source or {}
    context = context or []
    extracted = extract_entities(raw_text, set(catalog.products_by_code.keys()))
    fields = extracted["fields"]
    warnings = list(extracted["warnings"])
    # Who set each value. `fields` alone cannot answer that, which is why the snapshot
    # below is no longer `set(fields)` -- see FIELD_SOURCE_* in extractor.py.
    provenance: dict[str, str] = dict(extracted.get("provenance") or {})

    # Regex reads the row's own literal "X Series" text -- strictly more trustworthy
    # than an LLM inference from a heading. Regex wins if both resolve to a real
    # style; the LLM's candidate is used only when regex found none. field_overrides
    # is read-only here (pipeline.py re-reads entry.fields afterward for the audit
    # trail) -- never pop/mutate the caller's dict.
    regex_style_hint = _validated_style_code(fields.pop("explicit_style_hint", None), catalog)
    provenance.pop("explicit_style_hint", None)
    llm_style_hint = _validated_style_code((field_overrides or {}).get("explicit_style_hint"), catalog)
    resolved_style_hint = regex_style_hint or llm_style_hint
    if resolved_style_hint:
        fields["style_hint"] = resolved_style_hint
        # Regex wins where both resolve, so the hint's source is whichever one supplied it.
        provenance["style_hint"] = FIELD_SOURCE_CUSTOMER if regex_style_hint else FIELD_SOURCE_AGENT

    if field_overrides:
        for key, value in field_overrides.items():
            if key in LLM_OVERRIDE_KEYS and value is not None:
                fields[key] = value
                # Overwrites any regex reading of the same key: the agent set the value
                # that survives, so the agent owns it. Grounding does not promote this to
                # `customer` -- see the note on FIELD_SOURCE_AGENT.
                provenance[key] = FIELD_SOURCE_AGENT
        if fields.get("count"):
            warnings = [w for w in warnings if w != "Core count was not found."]
        if fields.get("cross_section"):
            warnings = [w for w in warnings if w != "Conductor cross-section was not found."]

    # Reapply after AI overrides so the instrument label cannot override C+E.
    apply_instrument_core_rule(fields)

    if (
        fields.get("parallel_single_core")
        or (fields.get("trefoil") and not fields.get("schedule_formation"))
    ) and not row_interpreted:
        # Detected but not yet split (field_overrides absent means this is the
        # regex-only trial pass, before the AI has had a chance to split it into
        # separate single-core entries) -- never report a confident single-product
        # match for a row that actually needs its own interpretation (see
        # PARALLEL_SINGLE_CORE_RE / TREFOIL_RE in extractor.py). Once the AI supplies
        # field_overrides for a split entry, this branch is skipped and normal
        # resolution proceeds as usual.
        return build_result(
            raw_text,
            source,
            extracted,
            status="needs_clarification",
            item_type="cable",
            selected=None,
            candidates=[],
            confidence=0.3,
            warnings=warnings,
        )

    if fields.get("exact_product_code"):
        exact_code = str(fields["exact_product_code"]).upper()
        product = catalog.products_by_code.get(exact_code) or _product_from_code(exact_code, catalog)
        if product is None:
            warnings.append("Could not parse the stated product code; verify before quoting.")
            return build_result(
                raw_text,
                source,
                extracted,
                status="needs_clarification",
                item_type="cable",
                selected=None,
                candidates=[],
                confidence=0.45,
                warnings=warnings,
            )
        return build_result(
            raw_text,
            source,
            extracted,
            status="matched",
            item_type="cable",
            selected=product,
            candidates=[product],
            confidence=0.98,
            warnings=warnings,
        )

    if fields.get("exact_accessory_code"):
        exact_code = str(fields["exact_accessory_code"]).upper()
        accessory = parse_accessory_code(exact_code)
        if accessory is None:
            warnings.append("Could not parse the stated accessory code; verify before quoting.")
            return build_result(
                raw_text,
                source,
                extracted,
                status="accessory",
                item_type="accessory",
                selected=None,
                candidates=[],
                confidence=0.6,
                warnings=warnings,
            )
        return enrich_accessory_result(
            build_result(
                raw_text,
                source,
                extracted,
                status="matched",
                item_type="accessory",
                selected=None,
                candidates=[],
                confidence=0.98,
                warnings=warnings,
            ),
            accessory,
        )

    if fields.get("not_sold_hint"):
        # A family TriCab does not sell (NOT_SOLD_PATTERNS). Checked after the exact
        # codes above, so a line naming a real TriCab code is still quoted as written.
        # The row is kept and marked excluded rather than dropped: it stays visible
        # (struck through) so the rep can see what the customer asked for, and it is
        # never synced to 4js (quote_readiness.exclusion_reason).
        label = fields["not_sold_hint"]
        result = build_result(
            raw_text,
            source,
            extracted,
            status="not_available",
            item_type="not_sold",
            selected=None,
            candidates=[],
            confidence=0.95,
            warnings=[],
        )
        result["disposition"] = "excluded"
        result["exclusion_reason"] = f"TriCab does not sell {label} cable"
        return result

    if fields.get("comms_hint"):
        # TriCab does not manufacture comms/data cables (fibre, cat5/6, etc.).
        return build_result(
            raw_text,
            source,
            extracted,
            status="not_available",
            item_type="comms",
            selected=None,
            candidates=[],
            confidence=0.9,
            warnings=["Communications/data cable — TriCab does not manufacture this; handle manually."],
        )

    if fields.get("accessory_hint"):
        # The line names an accessory but carries no product code. Read it from the words:
        # 91% of corpus accessory lines are described rather than coded, and until now all
        # of them landed here with no code, which is why the agent had nothing to hand back
        # and dropped them (ACCESSORY_AND_AGENT_READINESS_ORDER.md §2).
        reading = read_accessory_line(raw_text)

        if reading.status == "not_a_request":
            # "I will be quoting glands to suit" -- the customer describing their own scope
            # of supply. Routed to the admin shape rather than deleted: a vanished line
            # cannot be reviewed, and the note says why it is not a quote row.
            return build_result(
                raw_text,
                source,
                extracted,
                status="not_cable",
                item_type="admin",
                selected=None,
                candidates=[],
                confidence=0.85,
                warnings=[reading.note],
            )

        if reading.status == "matched" and reading.product_code:
            # Validated against accessory_specs, so this is a catalogue code rather than a
            # constructed one. Fed through the same field the exact-code path uses, so the
            # pack size, unit label and 4js unit handling are the ones already in service.
            fields["exact_accessory_code"] = reading.product_code
            result = enrich_accessory_result(
                build_result(
                    raw_text,
                    source,
                    extracted,
                    status="matched",
                    item_type="accessory",
                    selected=None,
                    candidates=[],
                    # Below the 0.98 of a code the customer typed themselves: the dimensions
                    # are read from prose, and the style was inferred from wording.
                    confidence=0.9,
                    warnings=[],
                ),
                parse_accessory_code(reading.product_code),
            )
            result["accessory_reading"] = reading.as_dict()
            return result

        result = enrich_accessory_result(
            build_result(
                raw_text,
                source,
                extracted,
                status="accessory",
                item_type="accessory",
                selected=None,
                candidates=[],
                confidence=0.9,
                warnings=[reading.note] if reading.note else [],
            )
        )
        # Everything learned, including the segments that are missing and the styles still
        # in contention, so the accessory panel can render candidates and a human can close
        # the gap the line left open.
        result["accessory_reading"] = reading.as_dict()
        return result

    if fields.get("conduit_only"):
        # Conduit itself (extractor.is_conduit_only). Not an accessory -- that routing
        # mis-flagged too many cable lines -- and never a cable: its bore is not a CSA.
        return build_result(
            raw_text,
            source,
            extracted,
            status="unresolved",
            item_type="unknown",
            selected=None,
            candidates=[],
            confidence=0.2,
            warnings=["Line asks for conduit, not cable -- no product code; review."],
        )

    if fields.get("admin_hint") and not looks_like_cable(fields):
        return build_result(
            raw_text,
            source,
            extracted,
            status="not_cable",
            item_type="admin",
            selected=None,
            candidates=[],
            confidence=0.8,
            warnings=[],
        )

    if not looks_like_cable(fields):
        return build_result(
            raw_text,
            source,
            extracted,
            status="unresolved",
            item_type="unknown",
            selected=None,
            candidates=[],
            confidence=0.2,
            warnings=warnings or ["Could not identify a cable requirement."],
        )

    # A symmetric split-earth construction (e.g. 3C+3E) is the multi-earth VSD/VFD
    # drive cable itself -- the XU/KU/EU/EA family is specifically this config (see
    # _vsd_target_style_code). Route it to the screened drive family even when the
    # row never spells out "VSD"/"EMC"/"screened". Pairs/triads are excluded by the
    # core_type == "C" guard, and a genuinely different application the customer
    # named (solar/instrument/etc.) is left alone; None/Power/Fire/Control all fall
    # through to VSD, matching how an explicit screen already routes here.
    if (
        (fields.get("earth_count") or 0) >= 2
        and fields.get("core_type") == "C"
        and fields.get("cable_type_hint") not in _NON_VSD_SPLIT_EARTH_HINTS
    ):
        fields["cable_type_hint"] = "VSD/VFD"
        fields["screen"] = True

    # Earth is its own intent, not a generic 1C cable colour. Force the earth code
    # colour to EA before explicit_fields is captured so Sales never sees a power
    # sheath colour on a standalone earth, including aluminium earths.
    if fields.get("earth_only"):
        # EA is the house code for an earth's jacket, derived from the earth intent rather
        # than stated as a colour, so it is a default -- not a colour the customer asked for.
        if provenance.get("colour") != FIELD_SOURCE_CUSTOMER or fields.get("colour") != "EA":
            provenance["colour"] = FIELD_SOURCE_DEFAULT
        fields["colour"] = "EA"
    elif fields.get("switchboard") and canonical_number(fields.get("count")) == "1" and not fields.get("colour"):
        # Switchboard/panel wire defaults to orange only when the row is NOT earth.
        fields["colour"] = "OR"
        provenance["colour"] = FIELD_SOURCE_DEFAULT

    # `switchboard_colour_defaulted` was this idea implemented once, as a local boolean,
    # because `fields` had nowhere to record it. It is now one case of the general rule.
    switchboard_colour_defaulted = bool(
        fields.get("switchboard")
        and fields.get("colour") == "OR"
        and provenance.get("colour") == FIELD_SOURCE_DEFAULT
    )

    # An AGENT proposal is not a hard constraint. Everything else still is.
    #
    # The narrow rule is deliberate. Excluding FIELD_SOURCE_DEFAULT as well -- the literal
    # "only `customer` is hard" -- was tried and measured, and it breaks the switchboard
    # orange default: with colour no longer filtering, `1C 25mm2 switchboard cable` picks a
    # stocked black (LS-XXXN/1C25OR -> LS-XXXN/1C25BK) and `1C 6mm2 switchboard wire` picks
    # the EA earth jacket (LS-XXXD/1C6OR -> LS-XXXD/1C6EA), an earth colour on a switchboard
    # wire. That default is the house's deliberate answer and TriCab makes orange switchboard
    # wire to order, so it must keep both selecting and reaching the synthesis branch below;
    # `switchboard_colour_defaulted` is what labels the resulting made-to-order code.
    #
    # What §1 of the order exists to stop is narrower than that: an AGENT-proposed colour
    # reaching the code synthesis at `resolver.py:1179` and inventing a code for a cable
    # that does not exist. Excluding FIELD_SOURCE_AGENT stops exactly that and nothing else.
    # Today it is a no-op -- no key gated on `explicit_fields` is in LLM_OVERRIDE_KEYS yet --
    # which is the point: it is the precondition that makes §2-§5 safe to add.
    #
    # FIELD_SOURCE_REGEX (a derived field, not a stated one -- case C4) is still counted
    # hard here. Reclassifying it is §2 of CABLE_SELECTION_FIX_ORDER and carries its own
    # corpus delta; the map above records it so that becomes a one-line change per field.
    explicit_fields = {
        key for key in fields
        if provenance.get(key, FIELD_SOURCE_CUSTOMER) != FIELD_SOURCE_AGENT
    }
    # Before any ranking: the band test needs a register-wide verdict the gate itself
    # cannot reach. Set on `fields` so enriched_fields and _rehome_target both see it.
    voltage_gate, voltage_gate_warning = _voltage_gate_set(fields, catalog)
    if voltage_gate is not None:
        fields["voltage_gate_set"] = voltage_gate
    if voltage_gate_warning:
        warnings.append(voltage_gate_warning)

    enriched_fields = dict(fields)
    count_defaulted = False
    if not enriched_fields.get("count"):
        enriched_fields["count"] = 1
        enriched_fields["core_type"] = enriched_fields.get("core_type") or "C"
        count_defaulted = True
        warnings = [warning for warning in warnings if warning != "Core count was not found."]

    style_candidates = rank_styles(extracted["normalized_text"], enriched_fields, catalog, context=context)
    if style_candidates:
        top_style, style_score = style_candidates[0]
        enriched_fields.update(derive_fields_from_style(enriched_fields, top_style))
    else:
        top_style, style_score = None, 0.0

    missing = missing_required_fields(enriched_fields)
    if missing:
        warnings.extend(f"Missing {field}." for field in missing)

    # House-default lock: when the customer didn't state sheath/temperature and the
    # top-ranked style is exactly the confirmed default for this case (XL/KL/CC/FR),
    # lock to it so the under-specified line resolves to that one style instead of
    # flagging a style tie. Reported as inferred below so the rep can override.
    haystack = " ".join([extracted["normalized_text"]] + (context or [])).lower()
    house_default_code = _house_default_style_code(fields, haystack)
    house_default_locked = bool(
        house_default_code
        and looks_like_cable(fields)
        and style_candidates
        and style_candidates[0][0].code == house_default_code
    )
    # The screened/VSD and instrumentation families resolve to one deterministic style;
    # lock to it (like the house default) so the line collapses to a single product and
    # reports the inference, instead of flagging a construction tie among siblings.
    family_target_code = _family_target_style_code(fields, haystack, catalog)
    family_target_locked = bool(
        family_target_code
        and style_candidates
        and style_candidates[0][0].code == family_target_code
    )
    # Control: when no colour is stated, leave the search unlocked so XL/KL-CA can be
    # preferred where stocked, falling back to the flexible C-series for small-core control.
    # When a colour IS stated, keep the established lock (e.g. an explicit black sheath keeps
    # the C-series, whose standard colour is black) so similar rows stay on one construction.
    control_line = fields.get("cable_type_hint") == "Control"
    style_hint_locked = bool(fields.get("style_hint") and style_candidates and style_candidates[0][0].code == fields["style_hint"])
    lock_inferred_style = (
        bool(fields.get("cable_type_hint") and fields.get("colour"))
        or house_default_locked
        or family_target_locked
        or style_hint_locked
    )
    if control_line and not fields.get("colour"):
        lock_inferred_style = False
    product_candidates = find_products(
        enriched_fields,
        catalog,
        style_candidates,
        explicit_fields=explicit_fields,
        lock_inferred_style=lock_inferred_style,
    )

    # Marine jacket fallback. `sheath` is a hard filter in violates_hard_constraints even
    # when the matcher derived it, and a marine style's default comes from its design
    # example -- one exemplar product, not the range default. That is wrong for the NEK
    # N-series in particular: NV/NR/NJ/NT all illustrate jacket C (NEK606 SHF-2) while the
    # catalog stocks mostly M (NEK606 MUD), so an unstated jacket excluded 60-87% of the
    # range and lines like "3C 2.5 NEK 606 RU P18" went unresolved with NVM-PHXD/3C2.5BK
    # sitting in stock. When the customer DID name a jacket it stays hard (it is in
    # explicit_fields); this only relaxes a jacket the matcher itself assumed.
    jacket_relaxed = False
    if not product_candidates and fields.get("marine") and "sheath" not in explicit_fields:
        relaxed_fields = {k: v for k, v in enriched_fields.items() if k != "sheath"}
        relaxed = find_products(
            relaxed_fields,
            catalog,
            style_candidates,
            explicit_fields=explicit_fields,
            lock_inferred_style=lock_inferred_style,
        )
        if relaxed:
            product_candidates = relaxed
            jacket_relaxed = True
            enriched_fields["sheath"] = relaxed[0].sheath

    colour_synthesised = False
    if not product_candidates and "colour" in explicit_fields:
        # A stated colour is a firm customer requirement, not negotiable against stock:
        # the product code MUST carry the requested colour even when nothing is catalogued
        # in it. Find the construction in whatever colour IS stocked, then synthesise the
        # code in the requested colour (the products CSV is a known-incomplete snapshot --
        # see _collapse_to_standard_form). Flagged so the rep confirms the made-to-order line.
        relaxed = find_products(
            enriched_fields,
            catalog,
            style_candidates,
            explicit_fields=explicit_fields - {"colour"},
            lock_inferred_style=lock_inferred_style,
        )
        if relaxed:
            base = relaxed[0]
            recoloured, _ = _collapse_to_standard_form(
                base, catalog, (base.config_core or "").upper(), str(enriched_fields.get("colour") or "").upper()
            )
            product_candidates = [recoloured]
            colour_synthesised = True

    selected = product_candidates[0] if product_candidates else None

    # Control resolves to a single construction. The search is unlocked (so XL/KL-CA can be
    # preferred where stocked, falling back to the flexible C-series for small-core control),
    # but once the best product is chosen we collapse to that one style so the line doesn't
    # flag a construction tie. XL/KL win for default (unspecified-colour) control; an explicit
    # black sheath keeps the C-series (whose standard colour is black).
    if control_line and selected and len(product_candidates) > 1:
        same_style = [p for p in product_candidates if p.style == selected.style]
        if same_style and len(same_style) < len(product_candidates):
            product_candidates = same_style
            selected = product_candidates[0]
            enriched_fields["style"] = selected.style

    # Instrumentation standard form (confirmed with TriCab 2026-06-30: "DE is the default
    # core config for instrumentation"). Instrumentation cables are numbered per pair/triad
    # -> config DE, not the per-conductor DA/DB numbering (same physical cable, different
    # numbering). The jacket is the style standard (WH = red RE, the R-series = black BK).
    # Applies to every Instrumentation style plus WH (the fire instrument, typed Fire
    # Resistant). Prefer a stocked DE row at this size; if none is catalogued, synthesise
    # the canonical code and flag it for TSD.
    synthesised_form = False
    # Earth cables are coded -XXX*/*EA (config XX + colour EA). If the chosen earth
    # style doesn't stock this exact size -- common for the sparse LN nylon-earth range
    # (LN earths exist only at a few sizes) -- synthesise the make-to-order earth code
    # rather than let a non-earth (power) product of the same style stand in.
    if fields.get("earth_only") and selected and (
        (selected.config_core or "").upper() != "XX" or (selected.colour or "").upper() != "EA"
    ):
        selected, syn = _collapse_to_standard_form(selected, catalog, "XX", "EA")
        synthesised_form = synthesised_form or syn
        product_candidates = [selected]
        enriched_fields["style"] = selected.style
        enriched_fields["config_core"] = selected.config_core
        enriched_fields["colour"] = selected.colour
    selected_style_obj = catalog.styles.get(selected.style) if selected else None
    if selected and selected_style_obj and (
        selected_style_obj.cable_type == "Instrumentation" or selected.style == "WH"
    ):
        want_config = "DE" if "config_core" not in explicit_fields else (selected.config_core or "").upper()
        want_colour = (
            (selected_style_obj.default_colour or "").upper()
            if "colour" not in explicit_fields else (selected.colour or "").upper()
        )
        if want_config and want_colour:
            selected, syn = _collapse_to_standard_form(selected, catalog, want_config, want_colour)
            synthesised_form = synthesised_form or syn
            product_candidates = [selected]
            enriched_fields["config_core"] = selected.config_core
            enriched_fields["colour"] = selected.colour
    elif (
        not selected and not missing and family_target_code
        and (fields.get("core_type") in {"P", "T", "Q"} or fields.get("cable_type_hint") == "Instrumentation")
    ):
        # Instrumentation make-to-order: nothing is stocked at this size, so synthesise the
        # canonical DE-config form of the family style (R3/RH/WH/...) and flag it "can be
        # made; confirm with TSD" below, exactly as the control branch does -- rather than
        # dropping the line to unresolved. Without this, an unstocked instrument spec fell
        # through with an un-normalised config and the engine built an invalid code.
        target_style_obj = catalog.styles.get(family_target_code)
        want_colour_inst = (
            (fields.get("colour") or "").upper()
            if "colour" in explicit_fields
            else (target_style_obj.default_colour or "BK").upper() if target_style_obj else "BK"
        )
        made = _standard_product_from_fields(enriched_fields, catalog, family_target_code, "DE", want_colour_inst)
        if made:
            selected = made
            synthesised_form = True
            product_candidates = [selected]
            enriched_fields["style"] = selected.style
            enriched_fields["sheath"] = selected.sheath
            enriched_fields["config_core"] = selected.config_core
            enriched_fields["armour"] = selected.armour
            enriched_fields["conductor"] = selected.conductor_type
            enriched_fields["core_type"] = selected.core
            enriched_fields["colour"] = selected.colour

    # Control / signal standard form (confirmed TriCab 2026-06-30). Standard (non-flexible)
    # control hard-defaults to the temperature-ladder style in the CA (control) config, black:
    # XL-CA (90C) / KL-CA (110C) / CC-CA (LSZH). "It should be XL-CA as default if temp is not
    # mentioned" -- so even where XL/KL stock no product for the size (XL-CA is only made at
    # >=5 cores, and there is no XL row at all at 2C), we RE-HOME the pick to XL-CA and
    # synthesise the code ("these cables can be made") rather than dropping to the flexible
    # C-series or an orange PA power variant. Signal/alarm rides the WG style in the same CA
    # form. An explicitly requested colour is preserved (e.g. purple DALI -> ...PU); otherwise
    # black. Flexible control (flex/rubber/braid/armour/screen) keeps the C-series, untouched.
    flex_control_signal = bool(
        fields.get("armoured")
        or fields.get("screen")
        or re.search(r"\b(?:flex(?:ible)?|rubber|braid|trailing|reeling|festoon|drag)\b", haystack)
    )
    # Pairs/triads/quads are always instrumentation (house rule), so never let the control
    # re-homing below fire on them even if the row/AI labelled it "control" -- they're
    # handled by the instrumentation branch above.
    is_instrument_cores = fields.get("core_type") in {"P", "T", "Q"}
    want_colour = (fields.get("colour") or "BK").upper() if selected else "BK"

    def _rehome_target() -> str | None:
        """The XL/KL/CC style to re-home standard control onto, or None.

        The re-homing synthesises a code directly, so it bypasses every gate the normal
        product search applies -- including the voltage hard gate. XL/KL/CC-CA are the
        600/1000 V control tier; the C-series is 110 V and KC/CF are 450/750 V, so a line
        that STATES 110 V or 450/750 V was having its own voltage silently overwritten
        (`4C 1.5MM2 CONTROL 110V` came back XL-CAXN/4C1.5BK). Re-check the gate here so a
        stated voltage keeps the tier the ranking already chose for it.
        """
        code = _house_default_style_code(fields, haystack)
        if code not in {"XL", "KL", "CC"}:
            return None
        target_style = catalog.styles.get(code)
        if target_style is not None and style_violates_construction(target_style, fields):
            return None
        return code

    if selected and fields.get("cable_type_hint") == "Control" and not flex_control_signal and not is_instrument_cores:
        target = _rehome_target()
        if target:
            selected, syn = _collapse_to_standard_form(selected, catalog, "CA", want_colour, want_style=target)
            synthesised_form = synthesised_form or syn
            product_candidates = [selected]
            enriched_fields["style"] = selected.style
            enriched_fields["config_core"] = selected.config_core
            enriched_fields["colour"] = selected.colour
    elif not selected and fields.get("cable_type_hint") == "Control" and not flex_control_signal and not is_instrument_cores and not missing:
        target = _rehome_target()
        if target:
            selected = _standard_product_from_fields(enriched_fields, catalog, target, "CA", want_colour)
            if selected:
                synthesised_form = True
                product_candidates = [selected]
                enriched_fields["style"] = selected.style
                enriched_fields["sheath"] = selected.sheath
                enriched_fields["config_core"] = selected.config_core
                enriched_fields["armour"] = selected.armour
                enriched_fields["conductor"] = selected.conductor_type
                enriched_fields["core_type"] = selected.core
                enriched_fields["colour"] = selected.colour
    elif selected and fields.get("cable_type_hint") == "Signal" and selected.style == "WG":
        if (selected.config_core or "").upper() != "CA" or (selected.colour or "").upper() != want_colour:
            selected, syn = _collapse_to_standard_form(selected, catalog, "CA", want_colour)
            synthesised_form = synthesised_form or syn
            product_candidates = [selected]
            enriched_fields["config_core"] = selected.config_core
            enriched_fields["colour"] = selected.colour

    # Screen/braid-construction default: when the screen slot wasn't explicitly stated,
    # collapse to the style's canonical code (e.g. the PAZN "Z" copper-laminate screen)
    # so we don't also offer the GSWB (PAGN "G") / TCWB (PATD "T") variants the customer
    # didn't ask for. Runs BEFORE the colour default because the screen construction is
    # more defining than the sheath colour (the canonical PAZN variant may not be the
    # style's default colour). No-op when only one armour code exists at this size, or
    # when no product carries the canonical code (then the available one stands).
    armour_defaulted = False
    if selected and "armour" not in explicit_fields and enriched_fields.get("armour"):
        want = str(enriched_fields["armour"]).upper()
        preferred = [p for p in product_candidates if (p.armour or "").upper() == want]
        if preferred and len(preferred) < len(product_candidates):
            product_candidates = preferred
            selected = product_candidates[0]
            armour_defaulted = True

    # Colour default: when the RFQ doesn't state a cable colour, collapse the
    # candidates to the selected style's standard colour so the line resolves to a
    # single product (otherwise colour variants keep it flagged). Inferred + flagged
    # so the rep confirms. Only runs when the colour was NOT stated (an explicit colour
    # is honoured as-is above, synthesised onto the code if nothing is stocked in it).
    colour_defaulted = False
    colour_default_note = ""
    if selected and "colour" not in explicit_fields:
        style_obj = catalog.styles.get(selected.style)
        default_colour = default_colour_for_fields(enriched_fields, style_obj) if style_obj else ""
        if default_colour:
            standard = [p for p in product_candidates if (p.colour or "").upper() == default_colour.upper()]
            if standard and len(standard) < len(product_candidates):
                product_candidates = standard
                selected = product_candidates[0]
                enriched_fields["colour"] = selected.colour
                colour_defaulted = True
                basis = "aluminium default" if enriched_fields.get("conductor") == "A" else "style standard"
                colour_default_note = f"Colour not stated; defaulted to {selected.colour} ({basis}). Confirm."
        if selected and enriched_fields.get("colour") and selected.colour != enriched_fields.get("colour"):
            enriched_fields["colour"] = selected.colour
            colour_defaulted = True
            colour_default_note = f"Colour not stated; using available catalog match {selected.colour}. Confirm."

    # Construction-code default: when the class code (config_core, e.g. PA vs MQ) was
    # not stated and the style has a confirmed default, collapse to it so the line
    # resolves to one product. Inferred + flagged.
    config_defaulted = False
    if selected and "config_core" not in explicit_fields:
        # Control rides the standard styles in the CA config; power defaults to PA.
        control_signalled = fields.get("cable_type_hint") == "Control"
        default_config = "CA" if control_signalled else STYLE_DEFAULT_CONFIG.get(selected.style)
        if not control_signalled and is_high_core_control(enriched_fields):
            # More than 5 cores is a control construction (see CONTROL_CORE_MIN), so the
            # style's power default no longer applies. Collapse onto the family's own
            # control config as the catalog stocks it -- CA on the mainstream styles,
            # CB/CD/CE on the marine ones -- instead of forcing PA back on.
            default_config = _modal(
                [
                    (p.config_core or "").upper()
                    for p in product_candidates
                    if (p.config_core or "").upper().startswith("C")
                ],
                "",
            ) or None
        if default_config:
            preferred = [p for p in product_candidates if (p.config_core or "").upper() == default_config.upper()]
            if preferred:
                if len(preferred) < len(product_candidates):
                    product_candidates = preferred
                if selected not in product_candidates:
                    selected = product_candidates[0]
                enriched_fields["config_core"] = selected.config_core
                config_defaulted = True

    # Treat the inferred control config and its sheath colour as one decision. If no
    # colour was stated, force BK even when the catalog snapshot only contains an OR
    # twin; _collapse_to_standard_form then produces the made-to-order BK code.
    if (
        selected
        and "colour" not in explicit_fields
        and (fields.get("cable_type_hint") == "Control" or is_high_core_control(enriched_fields))
    ):
        control_config = (selected.config_core or "").upper()
        if is_high_core_control(enriched_fields) and not control_config.startswith("C"):
            control_config = "CA"
        if control_config and (selected.colour or "").upper() != "BK":
            selected, syn = _collapse_to_standard_form(selected, catalog, control_config, "BK")
            synthesised_form = synthesised_form or syn
            product_candidates = [selected]
            enriched_fields["config_core"] = selected.config_core
            enriched_fields["colour"] = selected.colour
        colour_defaulted = True
        colour_default_note = "Colour not stated; defaulted to BK (control standard). Confirm."

    if selected and not missing and len(product_candidates) == 1:
        status = "matched"
        confidence = min(0.95, 0.55 + style_score + product_score(enriched_fields, selected))
    elif selected and not missing:
        status = "multiple_candidates"
        confidence = min(0.86, 0.45 + style_score + product_score(enriched_fields, selected))
        warnings.append("Multiple products match the extracted fields.")
    elif selected:
        status = "needs_clarification"
        confidence = min(0.72, 0.35 + style_score + product_score(enriched_fields, selected))
    else:
        status = "unresolved"
        confidence = min(0.55, 0.25 + style_score)
        if lock_inferred_style and top_style:
            warnings.append(
                f"No product in the inferred {top_style.code} construction family matched the explicit requirements."
            )
        else:
            warnings.append("No product in the dataset matched the extracted fields.")
        # This is a recognisable cable spec with no standard product (e.g. a not-made
        # size/construction). The code engine (apply_code_engine, below) tries to
        # construct a canonical code from the resolved fields and surfaces it as the
        # line's product code when it can -- these are often valid made-to-order
        # (MAKE) items. Only when it can't build one does the rep get pointed to
        # Technical (TSD) for a code; that message now lives in apply_code_engine so
        # it fires on the real "no code at all" case, not on every no-catalog-match.

    if colour_synthesised and selected:
        # The requested colour isn't stocked, so we synthesised the code to honour it
        # (colour is a firm requirement). It's a valid made-to-order code, not a catalog
        # row -- keep the match but flag it for TSD confirmation and cap confidence.
        if switchboard_colour_defaulted:
            warnings.append(
                f"Switchboard colour not stated; defaulted to OR and synthesised {selected.product_code} "
                f"(it can be made). Confirm the code with Technical (TSD)."
            )
        else:
            warnings.append(
                f"requested colour {selected.colour} honoured in generated code {selected.product_code}; "
                f"use the Cost API price/stock result as the authority."
            )
        confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    if (
        enriched_fields.get("screen")
        and not enriched_fields.get("screen_type")
        and enriched_fields.get("armour") in {"R", "1", "H", "L", ""}
    ):
        warnings.append("Screen type may need customer confirmation.")

    selected, product_candidates, status, confidence = apply_code_engine(
        enriched_fields, catalog, selected, product_candidates, status, confidence, warnings
    )

    # Surface a construction ambiguity: when more than one distinct STYLE survives
    # every gate, the pick between constructions is a judgement the rep must confirm
    # (same style with different colours is benign and does not count).
    if selected:
        surviving_styles = []
        for product in product_candidates:
            if product.style not in surviving_styles:
                surviving_styles.append(product.style)
        if len(surviving_styles) >= 2:
            runner_up = next(code for code in surviving_styles if code != selected.style)
            warnings.append(
                f"Construction ambiguous: styles {selected.style} vs {runner_up} both satisfy the stated specs; confirm."
            )
            confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    # When the style came from the house default (sheath/temperature unstated), say so
    # explicitly so the rep confirms the assumption rather than trusting a silent pick --
    # unless the sheath was actually confirmed (e.g. an AI-recognized cable standard like
    # MCMK => pvc=True), in which case this is a confirmed match, not a guess.
    sheath_confirmed = bool(fields.get("pvc") or fields.get("lszh"))
    if house_default_locked and selected and selected.style == house_default_code and not sheath_confirmed:
        warnings.append(
            f"Sheath/temperature not stated; defaulted to {selected.style} "
            f"({_HOUSE_DEFAULT_REASON.get(selected.style, 'house default')}). Confirm."
        )
        confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    # Marine lines get their own note: family_target_code is the marine matrix answer for
    # them, so the generic screen/VSD/instrument wording below would be wrong (and would
    # fire on a plain shipboard power line that has no screen at all).
    if jacket_relaxed and selected:
        style_obj = catalog.styles.get(selected.style)
        default_jacket = (style_obj.default_sheath_code or "") if style_obj else ""
        warnings.append(
            f"Jacket not stated; no {selected.style}{default_jacket or '?'} product at this size, "
            f"so {selected.product_code} ({MARINE_JACKET_NAMES.get((selected.sheath or '').upper(), selected.sheath or 'no jacket')}) "
            "was used. Confirm the jacket compound."
        )
        confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    if fields.get("marine"):
        if selected and family_target_code and selected.style == family_target_code:
            warnings.append(
                f"Marine range resolved to {selected.style} "
                f"({_MARINE_REASON.get(selected.style, 'marine default')}). Confirm."
            )
            confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)
        elif not family_target_code and looks_like_cable(fields):
            # No member of the range covers this combination -- NEK TS606 has no
            # instrumentation cable, and there is no offshore VSD/VFD. Say so rather than
            # letting a de-preferred mainstream style stand in silently.
            family_name = (fields.get("marine_family") or "marine").replace("nek", "NEK TS606")
            warnings.append(
                f"No {family_name} cable in the marine range matches this construction; "
                "confirm with Technical."
            )
            confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)
    elif family_target_locked and selected and selected.style == family_target_code:
        style_obj = catalog.styles.get(selected.style)
        reason = style_obj.title if style_obj else "family default"
        warnings.append(
            f"Screen/VSD/instrument family resolved to {selected.style} ({reason}). Confirm."
        )
        confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    if style_hint_locked and selected and selected.style == fields.get("style_hint"):
        reason = STYLE_HINT_REASONS.get(selected.style, "Construction hint requested")
        warnings.append(f"{reason}; selected {selected.style} construction. Confirm.")
        confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    if colour_defaulted and selected:
        warnings.append(colour_default_note or f"Colour not stated; defaulted to {selected.colour}. Confirm.")
        confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    if config_defaulted and selected:
        warnings.append(f"Construction class not stated; defaulted to {selected.config_core} ({selected.style} standard). Confirm.")
        confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    if synthesised_form and selected:
        warnings.append(
            f"Standard {selected.style} form {selected.product_code} is not stocked at this size in the "
            f"catalog (it can be made); confirm the code with Technical (TSD)."
        )
        confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    if armour_defaulted and selected:
        warnings.append(f"Screen/braid construction not stated; defaulted to the {selected.style} standard ({selected.product_code}). Confirm.")
        confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    if count_defaulted:
        warnings.append("Core count not stated; defaulted to 1C. Confirm.")
        confidence = min(confidence, STYLE_TIE_CONFIDENCE_CAP)

    # Tell the rep which attributes were defaulted from the style vs stated by the
    # customer, so the inferred ones get a second look.
    inferred_fields = [key for key in REPORTED_INFERABLE if key in enriched_fields and key not in explicit_fields]

    # Carry the final provenance onto the result. Anything the resolver added during
    # enrichment (a defaulted count, a style-derived sheath) was not in `fields` when
    # provenance was captured, so it is a house default by construction.
    field_sources = {
        key: provenance.get(key, FIELD_SOURCE_DEFAULT) for key in enriched_fields
    }
    extracted = {**extracted, "fields": enriched_fields, "provenance": field_sources}
    return build_result(
        raw_text,
        source,
        extracted,
        status=status,
        item_type="cable",
        selected=selected,
        candidates=product_candidates[:8],
        confidence=round(confidence, 2),
        warnings=dedupe(warnings),
        style_candidates=style_candidates[:5],
        inferred_fields=inferred_fields,
    )


def apply_code_engine(
    fields: dict[str, Any],
    catalog: ProductCatalog,
    selected: Product | None,
    candidates: list[Product],
    status: str,
    confidence: float,
    warnings: list[str],
) -> tuple[Product | None, list[Product], str, float]:
    """Use the ported 4Js cable-code engine as the resolver authority.

    Style selection comes from deterministic resolver/workbook rules, then the
    engine builds the product code from those fields. The product CSV may contain
    the generated row, but it no longer decides whether a generated code can be
    surfaced; the cost API answers price/stock/make/unavailable later.
    """
    fields["code_engine_version"] = ENGINE_VERSION
    try:
        canonical = build_code(fields)
    except ValueError:
        # The engine couldn't construct a code from these fields (e.g. an
        # out-of-range voltage). If this still reads as an unresolved cable, the rep
        # genuinely has no code to work from -- point them at Technical (TSD).
        if selected is None and status == "unresolved" and looks_like_cable(fields):
            warnings.append("Refer to Technical (TSD) for a product code.")
        return selected, candidates, status, confidence

    fields["canonical_code"] = canonical
    parsed = parse_code(canonical)
    if parsed.get("ccnt_earth"):
        fields["earth_cores"] = parsed["ccnt_earth"]
        fields["earth_cross_section"] = parsed["csa_earth"]

    style_obj = catalog.styles.get(parsed.get("style", ""))
    style_meta = {"title": style_obj.title, "voltage": style_obj.voltage} if style_obj else None
    description = describe_code(parsed, style_meta)
    if description:
        fields["description"] = description
        if style_obj:
            fields["style_title"] = style_obj.title
            fields["style_application"] = style_obj.application

    in_catalog = canonical in catalog.products_by_code
    fields["canonical_in_catalog"] = in_catalog

    generated = _product_from_code(canonical, catalog, desc2=description)
    if generated is None:
        if selected is None and status == "unresolved" and looks_like_cable(fields):
            warnings.append("Refer to Technical (TSD) for a product code.")
        return selected, candidates, status, confidence

    previous_code = (selected.product_code or "").upper() if selected else ""
    if previous_code and previous_code != canonical:
        fields["catalog_candidate_code"] = selected.product_code

    fields["code_source"] = "engine_generated" if in_catalog else "engine_generated_uncatalogued"
    fields["code_validated"] = True

    switchboard_default_note = False
    requested_colour_note = False
    cleaned_warnings = []
    for warning in warnings:
        if warning.startswith("Switchboard colour not stated; defaulted to OR and synthesised"):
            switchboard_default_note = True
            continue
        if warning.startswith("Requested colour"):
            requested_colour_note = True
            continue
        if warning.startswith(
            (
                "No product in the dataset matched",
                "No product in the inferred",
                "Multiple products match the extracted fields.",
                "No stocked product in the requested colour; synthesised",
                "Engine-constructed code",
                "Standard ",
            )
        ):
            continue
        cleaned_warnings.append(warning)
    warnings[:] = cleaned_warnings
    if switchboard_default_note:
        warnings.append("Switchboard colour not stated; defaulted to OR. Confirm.")
    if requested_colour_note:
        warnings.append(
            f"requested colour {generated.colour} honoured in generated code {canonical}; "
            f"use the Cost API price/stock result as the authority."
        )

    if not in_catalog:
        warnings.append(
            f"Generated code {canonical} is not in the autocomplete CSV; use the Cost API "
            f"price/stock result as the authority."
        )
        confidence = min(STYLE_TIE_CONFIDENCE_CAP, max(confidence, 0.78))
    else:
        confidence = min(0.97, max(confidence, 0.9))

    if status != "needs_clarification":
        status = "matched"

    return generated, [generated], status, confidence


def _format_band(volts) -> str:
    """`[300, 500]` -> "300/500 V"; `[110]` -> "110 V"."""
    return "/".join(str(v) for v in sorted(set(volts))) + " V"


def _voltage_gate_set(fields: dict[str, Any], catalog: ProductCatalog):
    """What the disjoint-band test in ``style_violates_construction`` should compare on,
    and the warning that goes with it. Returns ``(gate_volts, warning)``.

    ``style_violates_construction`` sees one style and no catalog, so it cannot answer
    "does ANY style in the register match this band?" -- and that is the question the
    fallback below turns on. The verdict is therefore computed once here, where the
    catalog is in hand, and carried to the gate in ``fields["voltage_gate_set"]``. The
    gate's signature and all three of its call sites are left alone.

    Four cases, and each one is load-bearing:

    * **the stated band matches something** -- gate on it, exactly as before. This covers
      ``4C 1.5MM2 CONTROL 110V``: 110 V is a real register band (C1/C2/C3), so XL/KL stay
      gated out and ``_rehome_target`` refuses to re-home. Drop this and the row returns
      to ``XL-CAXN/4C1.5BK``, the bug that guard exists to prevent.
    * **a slash pair that matches nothing** -- substitute the lowest band that meets or
      exceeds it, and say so. ``300/500V`` is a real, common flexible rating and TriCab
      does not make it (Manusha, 2026-08-31), so 450/750 is the right answer rather than a
      fudge. Satisfy-or-exceed, never nearest: 300/500 is equidistant from 150/250 and
      450/750, so "nearest" has no answer and half its answers are under-rated cable.
    * **a bare single AT OR BELOW 1000 V that matches nothing** -- ignore it. ``415V`` is a
      *system* voltage, not a cable rating; the standard cable for a 415 V system is
      600/1000, so satisfy-or-exceed would confidently under-rate it at 450/750. Today it
      gates out the whole catalog and the row lands `unresolved` at 0.25, so ignoring it
      strictly gains. The ceiling is not optional: above 1000 V a bare single IS a rating
      (``8kV``, ``11kV``), and suppressing those made
      ``4C+E 70mm2 Cu XLPE PVC SWA armoured 8kV`` return `matched` for a cable TriCab does
      not make, instead of sending the rep to Technical.
    * **out of range entirely** -- no band qualifies, so keep gating and let the row fail
      loudly rather than inventing a substitute.

    NOTE ON THE ORDER: §5.2 says a bare single "must not drive the disjoint-band test at
    all", and also requires ``4C 1.5MM2 CONTROL 110V`` to stay on the C-series. Those
    contradict -- 110 V *is* a bare single, and it only keeps the C-series because it
    gates. Reproduced before choosing: with the voltage dropped, the row resolves
    ``XL-CAXN/4C1.5BK``. So the suppression is narrowed to a bare single that matches no
    band, which satisfies both of the section's requirements.
    """
    stated = fields.get("voltage_set")
    if not stated:
        return None, None
    stated_set = set(stated)
    bands = {
        tuple(sorted(style.voltages)): style.voltage_u
        for style in catalog.styles.values()
        if style.voltages
    }
    if any(not stated_set.isdisjoint(band) for band in bands):
        return list(stated), None

    if not fields.get("voltage_is_pair"):
        # Satisfy-or-exceed is for slash pairs only. A bare single at or below 1000 V is
        # read as a system voltage and ignored; above that it is a real rating we simply
        # do not make, so it keeps gating and the row fails loudly.
        return ([], None) if max(stated_set) <= 1000 else (list(stated), None)

    required = max(stated_set)
    qualifying = [band for band, voltage_u in bands.items() if voltage_u >= required]
    if not qualifying:
        return list(stated), None

    target = min(qualifying, key=lambda band: (max(band), min(band)))
    # The reasoning, not just the fact: the rep is being asked to accept a rating the
    # customer did not ask for, and "voltage substituted" would make them re-derive why.
    warning = (
        f"Selected a {_format_band(target)} cable: TriCab does not make "
        f"{_format_band(stated)}, and {_format_band(target)} satisfies the stated "
        f"{_format_band(stated)} requirement. Confirm."
    )
    return list(target), warning


def style_violates_construction(style: Style, fields: dict[str, Any]) -> bool:
    """Hard gate: True when a style contradicts an EXPLICIT construction signal
    from the RFQ (voltage, armour, screen, sheath material). These fields are only
    ever set by the extractor — never by style-defaulting — so their presence means
    the customer stated them, and a mismatch is a real contradiction, not a guess.

    This is what stops a wrong-but-catalog-valid style (e.g. PVC KK vs LSZH CC at
    600/1000 V, both 4x70-valid) from being selected on conductor geometry alone."""
    rfq_volts = fields.get("voltage_set")
    # `voltage_gate_set` is the band test's view of the rating: the stated band where the
    # register has one, a satisfy-or-exceed substitute where it does not, or empty for a
    # bare system voltage that must not gate at all. Absent (a hand-built fields dict, or
    # a caller that never went through _resolve_line) falls back to the stated band, so
    # the gate behaves exactly as it always did. See _voltage_gate_set.
    gate_volts = fields.get("voltage_gate_set", rfq_volts)
    if gate_volts and style.voltages and set(gate_volts).isdisjoint(style.voltages):
        return True
    if style.cable_type == "MV Power":
        # Deliberately the STATED voltage, not the substituted one: MV is a customer
        # request, and a substitution must never manufacture one.
        mv_requested = fields.get("cable_type_hint") == "MV Power" or any(v > 1000 for v in (rfq_volts or []))
        if not mv_requested:
            return True
    # Fire instrument cables route to WH, the sole fire-rated instrument style, which is
    # unarmoured -- there is no armoured fire instrument, so an armour request (e.g. IOAS)
    # on a fire instrument line must NOT gate WH out (confirmed with TriCab 2026-06-30:
    # "go with WH because FR is mentioned").
    fire_instrument = fields.get("fire") and (
        fields.get("cable_type_hint") == "Instrumentation"
        or fields.get("core_type") in {"P", "T", "Q"}
    )
    if fields.get("armoured") and not style.has_armour and not (fire_instrument and style.code == "WH"):
        return True
    if fields.get("screen") and not style.has_screen:
        return True
    if fields.get("circular") and "flat" in style.haystack:
        return True
    if fields.get("lszh") and style.sheath_pvc and not style.sheath_lszh:
        return True
    # A stated PVC sheath rules out an LSZH-only style -- UNLESS the line also
    # states LSZH. Consultant descriptions routinely read "XLPE/PVC ... HF110
    # LSZH", where the PVC is the conductor insulation/bedding and LSZH is the
    # deliberate outer-sheath requirement. There, LSZH is the sheath intent and
    # must not be gated out, or a termite/nylon "XLPE/PVC HF110 LSZH" cable loses
    # its CN family and falls through to the generic LS/LP earth style.
    # "Fire rated" takes the same priority (Manusha, 12 October 2026): every fire-rated
    # style is LSZH-sheathed, so "XLPE/PVC fire rated" means FR. Gating FR out left the
    # line with no default bonus at all, and a "submains cable" heading then matched LP's
    # "earthing of mains and sub mains" text and quoted the mains as earth wire.
    if (
        fields.get("pvc")
        and not fields.get("lszh")
        and not fields.get("fire")
        and style.sheath_lszh
        and not style.sheath_pvc
        and fields.get("style_hint") != style.code
    ):
        return True
    return False


def looks_like_cable(fields: dict[str, Any]) -> bool:
    return any(
        fields.get(key)
        for key in [
            "cross_section",
            "count",
            "earth",
            "screen",
            "armoured",
            "cable_type_hint",
            "conductor",
        ]
    )


# Colours that are too common across the catalog to identify a style family. Black is the
# default on 77 of 114 styles; every other colour is on 22 or fewer, so only BK qualifies.
_UNINFORMATIVE_COLOURS = frozenset({"BK"})


def _industry_range_bonus(style: Style, requested_type: str | None, haystack: str) -> float:
    """Named industry -> that industry's range, but only its member of the requested
    type: food + instrumentation -> F1, plain food -> FB, never F1/FV unasked. Power and
    Control count as one type here -- the heavy-duty ranges serve both (SC, FB)."""
    tokens = APPLICATION_RANGE_STYLES.get(style.code)
    matches = sum(token in haystack for token in tokens) if tokens else 0
    if not matches:
        return 0.0
    general = {"Power", "Control"}
    if requested_type:
        fits = style.cable_type == requested_type or {style.cable_type, requested_type} <= general
    else:
        fits = style.cable_type not in {"Instrumentation", "VSD/VFD"}
    return 0.6 + 0.1 * (matches - 1) if fits else 0.0


def rank_styles(
    normalized_text: str,
    fields: dict[str, Any],
    catalog: ProductCatalog,
    context: list[str] | None = None,
) -> list[tuple[Style, float]]:
    haystack = " ".join([normalized_text] + (context or [])).lower()
    requested_type = fields.get("cable_type_hint")
    if requested_type == "Control" and fields.get("core_type") == "C" and fields.get("earth"):
        # Once C+E is classified as control, the original instrument label must
        # not boost instrumentation styles through catalogue keyword matching.
        haystack = re.sub(r"\binstrument(?:ation)?\b", "control", haystack)
    solar_requested = bool(SOLAR_RE.search(haystack))
    marine_requested = bool(fields.get("marine"))
    # Standard control = XL/KL in the CA config (Technical default), NOT the dedicated
    # Flexible/Rubber control series (C1-C6, CF, KC, ...); only prefer that series when
    # the RFQ asks for flexibility/rubber/braid/armour/screen.
    control_requested = requested_type == "Control"
    flex_control_signal = bool(
        fields.get("armoured")
        or fields.get("screen")
        or re.search(r"\b(?:flex(?:ible)?|rubber|braid|trailing|reeling|festoon|drag)\b", haystack)
    )
    # Default to POWER unless control/instrumentation/screen is signalled (confirmed with
    # TriCab 2026-06-29). The X/K series serve both power and control; the power vs control
    # split is the config code (PA vs CA), not a different style series, so a bare sheath
    # colour (e.g. black) must not pull a plain power line into the control/VSD families.
    power_default = not requested_type and not fields.get("screen")
    # The temperature-appropriate house-default style (XL/KL/CC/FR) must always be a
    # candidate, even when an under-specified line gives it no other base score. Without
    # this seed it scores 0 and is dropped before house_default_delta can rank it, letting
    # an incidental keyword match on another style win (e.g. plain power -> CA, or a control
    # line going unresolved once the flexible series is de-preferred).
    house_default_seed = _house_default_style_code(fields, haystack)
    # Target style for the screened/VSD and instrumentation families (deterministic once
    # the family is signalled). Boosted below so the exact family member wins and its
    # siblings are de-preferred, the same way the house default ranks the power ladder.
    family_target = _family_target_style_code(fields, haystack, catalog)
    style_hint = fields.get("style_hint")
    scores: list[tuple[Style, float]] = []

    # A standalone earth is a switchboard/panel earth conductor: it must stay in the
    # LS/XX/EA form, including aluminium earths (LS-XXXA/...EA), rather than falling
    # through to a generic 1C power cable. This is intentionally stricter than normal
    # style ranking because "earth" is a Sales-critical intent.
    # A nylon/termite earth is LN, the family's dedicated earth cable (TriCab
    # 2026-07-07: "LN is an earth cable"), coded like every earth as -XXX*/*EA
    # (LN-XXXN/1C95EA). It takes precedence over the LP/LS default when termite is
    # requested, since the nylon requirement is the defining one.
    # Marine earth and panel wire are UP for every marine family (confirmed by Manusha
    # 2026-08-18), ahead of the mainstream LP/LS/LN earth rules -- those styles have no
    # marine approval, so they are not substitutes on a marine line.
    if fields.get("marine") and (
        fields.get("earth_only")
        or (fields.get("switchboard") and canonical_number(fields.get("count")) == "1")
    ):
        marine_earth = catalog.styles.get(MARINE_EARTH_STYLE)
        if marine_earth:
            return [(marine_earth, 0.6)]
    if fields.get("earth_only") and fields.get("termite"):
        ln_earth_style = catalog.styles.get("LN")
        if ln_earth_style:
            return [(ln_earth_style, 0.6)]
    ls_flex_requested = bool(LS_FLEX_STYLE_RE.search(haystack))
    # "R-125" names the LS style outright -- it is LS's own insulation designation and no
    # other style claims it (see LS_R125_STYLE_RE). Read as a temperature marker alone it
    # only says "hotter than 90C", which sends the row down the sheathed XL/KL ladder to
    # KL; consulting it as a style signal here, ahead of that ladder, is the whole fix.
    # Unlike the earth and single-insulated branches below it does NOT lean on
    # is_small_ls_conductor: a stock order of LSFLEX R-125 panel wire runs to 70 mm2, and
    # those sizes are as unsheathed as the 1 mm2 ones.
    # Building wire, written out or as "B/W", including section headings, is the
    # single-insulated LP/LS family chosen by size -- see the building-wire branch below.
    # On an earth it promotes only the small range: a building-wire earth above 10 mm2
    # stays LP. "B/W" is matched here as well as expanded by normalize_text: a section
    # heading arrives in `context` unnormalised.
    building_wire_requested = not style_hint and bool(
        re.search(r"\bBUILDING[\s-]+WIRE\b|\bB\s*/\s*W\b", haystack, re.I)
    )
    ls_style_requested = ls_flex_requested or bool(LS_R125_STYLE_RE.search(haystack))
    if fields.get("earth_only"):
        # Up to 10 mm2 a standalone earth is LS, matching the small switchboard /
        # single-insulated range. A split-off earth beside an ordinary mains cable is
        # the exception and stays LP; pipeline/file_parser put the private PAIRED MAINS
        # CABLE marker in its context so a visually identical standalone earth can be
        # told apart. Explicit LS construction always wins, including fire/high-temp
        # markers inherited from a sibling mains cable. "Building wire" is weaker: it
        # promotes a paired earth in the small range, but a larger earth stays LP.
        explicit_ls = bool(
            fields.get("lszh")
            or fields.get("switchboard")
            or fields.get("single_insulated")
            or fields.get("fire")
            or ls_flex_requested
            or LS_R125_STYLE_RE.search(haystack)
            or (building_wire_requested and is_small_ls_conductor(fields))
            or SINGLE_INSULATED_RE.search(haystack)
            or mentions_high_temp(haystack)
            or FIRE_RATED_RE.search(haystack)
        )
        paired_with_mains = bool(
            re.search(r"\bPAIRED\s+MAINS\s+CABLE\b", haystack, re.I)
            or any(
                _MAINS_CONTEXT_RE.search(str(entry)) and not _EARTH_CONTEXT_RE.search(str(entry))
                for entry in (context or [])
            )
        )
        want_ls = explicit_ls or (is_small_ls_conductor(fields) and not paired_with_mains)
        earth_style = catalog.styles.get("LS" if want_ls else "LP")
        return [(earth_style, 0.6)] if earth_style else []
    if fields.get("switchboard") and canonical_number(fields.get("count")) == "1":
        switchboard_style = catalog.styles.get("LS")
        return [(switchboard_style, 0.6)] if switchboard_style else []
    if ls_style_requested and canonical_number(fields.get("count")) == "1":
        ls_flex_style = catalog.styles.get(MARINE_EARTH_STYLE if marine_requested else "LS")
        return [(ls_flex_style, 0.6)] if ls_flex_style else []
    # Building wire that is not an earth (Manusha, 9 October 2026): LS up to 10 mm2 and LP
    # above, by the same size rule as an earth, and LS at any size when LSZH is asked
    # for. It used to be LS at every size. Marine keeps its own substitute, as everywhere
    # else. A "B/W" with no colour never reaches here -- the extractor made it an earth.
    if building_wire_requested and canonical_number(fields.get("count")) == "1":
        if marine_requested:
            building_wire_code = MARINE_EARTH_STYLE
        elif fields.get("lszh") or is_small_ls_conductor(fields):
            building_wire_code = "LS"
        else:
            building_wire_code = "LP"
        building_wire_style = catalog.styles.get(building_wire_code)
        if building_wire_style:
            return [(building_wire_style, 0.6)]
    # An explicit "single insulated" names the unsheathed building-wire family outright:
    # LP/LS/UP are the ONLY styles in the catalog with no sheath, so the phrase rules out
    # every sheathed style rather than merely favouring these three. Which one follows the
    # insulation the row states, mirroring the earth rule above -- LSZH/high-temp -> LS,
    # marine -> UP, plain PVC/V-90-HT -> LP. Without this the phrase scored nothing and an
    # RFQ heading like "SINGLE INSULATED FLEXIBLE HF-110 600/1000V" was decided by its
    # HF-110 alone, landing on the sheathed KL rung of the XL/KL/CC ladder (and, on the
    # rows that named a black sheath, on KP -- submersible pump cable). Single insulated
    # implies a single core, so a stated multicore count is a contradiction that falls
    # through to normal ranking; an explicit "X Series" also still wins, since that names
    # a style outright and this branch would otherwise discard its +0.65.
    if (
        (fields.get("single_insulated") or SINGLE_INSULATED_RE.search(haystack))
        and not style_hint
        and canonical_number(fields.get("count")) == "1"
    ):
        if marine_requested:
            single_code = MARINE_EARTH_STYLE
        elif (
            fields.get("lszh")
            or fields.get("fire")
            or ls_style_requested
            or mentions_high_temp(haystack)
            or is_small_ls_conductor(fields)
        ):
            single_code = "LS"
        else:
            single_code = "LP"
        single_style = catalog.styles.get(single_code)
        if single_style:
            return [(single_style, 0.6)]

    for style in catalog.styles.values():
        if style.cable_type == "Solar" and not solar_requested:
            continue
        # The marine range is opt-in, exactly like Solar above: 25 styles and ~5,300
        # products that must never compete for an ordinary industrial line. Nothing in
        # the sample corpus or the production review log is marine, so an ungated
        # addition could only cost accuracy -- e.g. BV ("Flexible Control LSZH",
        # 600/1000 V) outranking CC on a plain LSZH control line.
        if style.marine and not marine_requested:
            continue
        # The industry ranges are opt-in the same way: AR's textile braid otherwise
        # reads as a screen and wins a plain "4C+E VSD LSZH screened" line.
        industry = APPLICATION_RANGE_STYLES.get(style.code)
        if (
            industry
            and style_hint != style.code
            and style.code.lower() not in haystack.split()
            and not any(token in haystack for token in industry)
        ):
            continue
        # V1 and V2 share 1.9/3.3 kV: V2 (plain) is the default, V1 only when tinned is
        # stated (Manusha, 2026-11-02).
        if style.code == "V1" and fields.get("conductor") != "D" and style_hint != "V1":
            continue
        if style_violates_construction(style, fields):
            continue

        score = 0.0
        if style.code == "V1":
            score += 0.1
        if style_hint:
            if style.code == style_hint:
                score += 0.65
            else:
                score -= 0.25
        if house_default_seed and style.code == house_default_seed:
            score += 0.05
        if marine_requested:
            # Class society approval is a hard requirement, so a mainstream style is never
            # a substitute on a marine line -- de-prefer the whole non-marine catalog
            # rather than gating it out, so a genuinely non-marine item inside a marine
            # RFQ can still surface (ranked low) instead of going unresolved.
            # `family_target` is the marine matrix answer here (see
            # _family_target_style_code), so the target wins and its siblings step aside.
            if not style.marine:
                score -= 0.30
            elif family_target and style.code == family_target:
                score += 0.45
            elif family_target:
                score -= 0.15
        elif family_target:
            # The screened/VSD and instrumentation families resolve to one deterministic
            # style; force it and de-prefer the rest of those families. The generic
            # requested-type bonus below is skipped (elif) so it cannot lift a sibling
            # over the target -- notably the fire instrument style WH, whose cable_type
            # is "Fire Resistant" while its siblings are "Instrumentation".
            if style.code == family_target:
                score += 0.45
            elif style.cable_type in {"Instrumentation", "VSD/VFD", "Fire Resistant"}:
                score -= 0.15
        elif control_requested:
            # Prefer the seeded house default (XL/KL) for standard control; keep the
            # Flexible/Rubber control series as a lower-ranked fallback (it wins only where
            # XL/KL stock no product for the size, e.g. <=1.5mm small-core control) and
            # promote it when flexibility/rubber/braid/armour/screen is actually requested.
            if style.cable_type == "Control" and flex_control_signal:
                score += 0.35
        elif requested_type and style.cable_type == requested_type:
            score += 0.35
        elif (
            fields.get("termite")
            and style.code in _NYLON_TERMITE_STYLES
            # Same tier the termite house-default ladder covers: unarmoured, non-fire,
            # no locked style_hint. A fire-rated termite line (X-125 FIRE RATED LSZH
            # Termite Nylon) must still land on FR/FRT, not be pulled into the nylon
            # family by this boost.
            and not fields.get("style_hint")
            and not fields.get("armoured")
            and not FIRE_RATED_RE.search(haystack)
        ):
            # An explicit termite/nylon request IS the type signal, so score it as a type
            # match rather than a mismatch. XN/KN/CN are catalogued Control-type, but a
            # line like "500mm2 1C Cu Flexible SDI PVC/XLPE-110/NJ" sets
            # cable_type_hint="Power" off its SDI token -- which used to penalise the very
            # family that was asked for (-0.2), letting the Power sibling KL outrank the
            # correct KN even though the house default had already chosen KN. Mirrors the
            # exception in the power_default branch below, which only ever covered the
            # case where NO type was requested.
            score += 0.35
        elif requested_type:
            score -= 0.2
        elif power_default and style.cable_type in {"Control", "Instrumentation", "VSD/VFD"}:
            # No control/instrument/screen signal: de-prefer the dedicated control/
            # instrument/VSD families so the power styles win the default. Exception: a
            # termite/nylon request IS a signal for the nylon family (XN/KN/CN are
            # Control-type), so don't de-prefer them here -- the termite house-default
            # ladder (house_default_delta) is left to rank them to the temperature tier.
            if not (fields.get("termite") and style.code in _NYLON_TERMITE_STYLES):
                score -= 0.2

        if fields.get("armoured") and style.armour:
            score += 0.18
        if fields.get("screen") and (style.braiding or "screen" in style.haystack):
            score += 0.14
        if fields.get("lszh") and any(token in style.haystack for token in ["lszh", "low smoke", "hffr", "halogen"]):
            score += 0.18
        # PVC drives the sheath score only when LSZH was NOT also stated. In an
        # "XLPE/PVC ... LSZH" line the PVC is insulation and LSZH is the sheath, so
        # letting PVC boost PVC-sheath styles and penalise LSZH ones would pull the
        # cable off its (correct) LSZH family -- LSZH is the deliberate requirement.
        if fields.get("pvc") and not fields.get("lszh"):
            if "pvc" in style.haystack:
                score += 0.12
            elif any(token in style.haystack for token in ["lszh", "hffr", "halogen free", "rubber"]):
                score -= 0.08
        if fields.get("colour"):
            if style.default_colour == fields["colour"]:
                # Black is the house colour of 77 of the 114 styles, so on a power line it
                # identifies no family at all. Paying the full match bonus for it let KP and
                # KB (submersible pump / battery -- both BK) outrank the temperature ladder's
                # own answer on a plain "100 MTR - 35MM BLACK" row, quoting submersible pump
                # cable for building wire. Distinctive colours (EA earth, OR power, RE) still
                # discriminate. This is the mirror of the exemption below, which already
                # holds that a black sheath says nothing about a power style either way.
                if not (power_default and fields["colour"] in _UNINFORMATIVE_COLOURS):
                    score += 0.22
            elif style.default_colour and not (power_default and style.code == house_default_seed):
                # Power styles come in many colours (black is a valid PA-config power
                # sheath), so an off-default colour must not penalise the power house
                # default. Control lines keep the existing colour discrimination.
                score -= 0.25
        if fields.get("conductor") == "A" and style.default_conductor_code == "A":
            score += 0.32
        elif fields.get("conductor") == "A" and "aluminium" in style.haystack:
            score += 0.18
        if fields.get("conductor") == "N" and "copper" in style.haystack:
            score += 0.08

        for keyword in style.keywords:
            if keyword and keyword in haystack:
                score += 0.02

        if style.code.lower() in haystack.split():
            score += 0.25

        industry_bonus = _industry_range_bonus(style, requested_type, haystack)
        score += industry_bonus

        if score > 0:
            capped = min(score, 0.55)
            # Never prefer armour the customer didn't ask for: de-prefer armoured
            # styles after capping so an unarmoured construction wins an otherwise-tied
            # ranking (e.g. unarmoured KL/XL over armoured KA for a plain "LV power").
            if not fields.get("armoured") and style.has_armour:
                capped -= 0.05
            capped += house_default_delta(style, fields, haystack)
            # Added again past the cap so two members of one industry range still
            # separate on how many of their own words were named (kevlar crane -> AR).
            capped += industry_bonus / 2
            scores.append((style, capped))

    scores.sort(key=lambda item: item[1], reverse=True)
    return scores


def derive_fields_from_style(fields: dict[str, Any], style: Style) -> dict[str, Any]:
    derived: dict[str, Any] = {"style": style.code, "cable_type": style.cable_type}

    if not fields.get("sheath"):
        # A fire-rated cable that ALSO needs termite/nylon resistance takes a
        # custom termite jacket, coded as the "T" sheath suffix on the fire style:
        # FR -> FRT. There is no separate fire+termite style and no stocked FRT
        # product (the FR pool is entirely bare-sheath), so FRT is a deliberately
        # generated made-to-order selection rather than a catalog lookup.
        if style.code == "FR" and fields.get("termite"):
            derived["sheath"] = "T"
        else:
            derived["sheath"] = style.default_sheath_code or ""
    if not fields.get("conductor"):
        derived["conductor"] = style.default_conductor_code or "N"
    if not fields.get("colour"):
        derived["colour"] = default_colour_for_fields(fields, style)
    if not fields.get("core_type"):
        derived["core_type"] = "C"
    if not fields.get("config_core"):
        derived["config_core"] = default_config_for_style(fields, style)
    if not fields.get("armour"):
        derived["armour"] = derive_armour_code(style, fields)

    return derived


def default_config_for_style(fields: dict[str, Any], style: Style) -> str:
    if fields.get("earth_only"):
        return "XX"
    if fields.get("cable_type_hint") == "Control" and style.code in {"XL", "KL", "CC"}:
        return "CA"
    config = _stated_default_config(fields, style)
    # More than 5 cores is a control construction whatever the style's power default
    # says (see CONTROL_CORE_MIN). Only a power config is re-homed -- a style that
    # already defaults to a control/marine C* config keeps its own letter.
    if config.startswith("P") and is_high_core_control(fields):
        return "CA"
    return config


def _stated_default_config(fields: dict[str, Any], style: Style) -> str:
    if fields.get("cable_type_hint") == "VSD/VFD" or style.cable_type == "VSD/VFD":
        return "PA"
    if style.cable_type == "MV Power" and fields.get("armoured"):
        return "PA"
    if style.code in STYLE_DEFAULT_CONFIG:
        return STYLE_DEFAULT_CONFIG[style.code]
    if style.code in HEAVY_DUTY_PD_STYLES:
        return "PA" if canonical_number(fields.get("count")) == "1" else "PD"
    if style.default_config:
        return style.default_config
    return derive_colour_config(style.cable_type, fields)


def default_colour_for_fields(fields: dict[str, Any], style: Style) -> str:
    if fields.get("earth_only"):
        return "EA"
    # Every control construction has a black outer sheath. This covers both an
    # explicit Control classification and the >5 plain-core control boundary.
    # Explicit customer colours bypass this default and remain authoritative.
    if fields.get("cable_type_hint") == "Control" or is_high_core_control(fields):
        return "BK"
    if fields.get("switchboard") and canonical_number(fields.get("count")) == "1":
        return "OR"
    if fields.get("conductor") == "A":
        return "BK"
    return style.default_colour or default_colour_for_type(style.cable_type)


def derive_colour_config(cable_type: str, fields: dict[str, Any]) -> str:
    if fields.get("earth_only"):
        return "XX"
    # A control request rides the standard XL/KL styles in the CA (control) config.
    if fields.get("cable_type_hint") == "Control":
        return "CA"
    fixed = {
        "Control": "CA",
        "Instrumentation": "DE",
        "MV Power": "P8",
        "Solar": "P1",
        "Building Wire": "XX",
    }
    if cable_type in fixed:
        return fixed[cable_type]
    if cable_type in POWER_ADJACENT:
        return "PA" if fields.get("earth") or fields.get("count") == 1 else "PB"
    return "PA"


def derive_armour_code(style: Style, fields: dict[str, Any]) -> str:
    armour = style.armour.upper()
    braiding = style.braiding.lower()
    if "SWA/AWA/AWA" in armour:
        return "J" if fields.get("count") == 1 else "S"
    if "SWA" in armour or "AWA" in armour:
        return "S"
    if style.cable_type == "MV Power":
        # V1 is the one MV style built as X (V1-PAXD). P4's example says G, but its stock
        # is all N, so only X is taken from the example.
        return "X" if style.default_armour_code == "X" else "N"
    if "copper laminate" in braiding:
        return "Z"
    if "galvanized steel wire braid" in braiding or "galvanised steel wire braid" in braiding:
        return "G"
    if "textile braid" in braiding:
        # AR's anti-twisting Kevlar braid: every AR product is coded O.
        return "O"
    if "tinned copper wire braid" in braiding:
        return "T"
    # A stated screen is NOT an armour code. The R-series carries its screen in the style
    # itself rather than in the armour/braiding columns, so a screened instrumentation line
    # fell through to an empty armour character and built a structurally invalid 3-character
    # zone 2 (R1-DEN/12P1.5BK). All 2,479 instrumentation products -- and every one of the
    # 57 styles that reach this branch -- carry a 4-character zone 2, so "X" (unarmoured)
    # is the only valid answer here. Screened POWER styles never reach this line: they take
    # the copper-laminate "Z" above.
    return "X"


def default_colour_for_type(cable_type: str) -> str:
    if cable_type == "Fire Resistant":
        return "RE"
    if cable_type in {"Power", "Welding"}:
        return "OR"
    if cable_type == "Building Wire":
        return "EA"
    return "BK"


def missing_required_fields(fields: dict[str, Any]) -> list[str]:
    missing = []
    if not fields.get("count"):
        missing.append("count")
    if not fields.get("cross_section"):
        missing.append("cross_section")
    # Flag an unresolved screen construction only for a bare "screened" line: when the
    # screen type is known (screen_type), or the family fixes it (VSD is collective;
    # instrumentation pairs/triads/quads default to collective), there is nothing missing.
    if (
        fields.get("screen")
        and not fields.get("screen_type")
        and not fields.get("armour")
        and fields.get("cable_type_hint") != "VSD/VFD"
        and fields.get("core_type") not in {"P", "T", "Q"}
    ):
        missing.append("screen_type")
    return missing


def find_products(
    fields: dict[str, Any],
    catalog: ProductCatalog,
    style_candidates: list[tuple[Style, float]],
    explicit_fields: set[str] | None = None,
    lock_inferred_style: bool = False,
) -> list[Product]:
    explicit_fields = explicit_fields or set()
    styles = [style.code for style, _ in style_candidates[:1 if lock_inferred_style else 8]]
    pool = []
    if styles:
        for style in styles:
            pool.extend(catalog.products_by_style.get(style, []))
    else:
        # No confident style. Search only registered-style products: unregistered-style
        # products (e.g. shipping cables not yet added to the style list) can't be
        # construction-gated, so auto-picking them is unsafe. Leaving the line unresolved
        # flags it for Technical (TSD) instead of guessing -- matches the Sales workflow.
        pool = [p for p in catalog.products if p.style in catalog.styles]

    scored: list[tuple[float, Product]] = []
    for product in pool:
        product_style = catalog.styles.get(product.style)
        if product_style and product_style.cable_type == "Solar" and fields.get("cable_type") != "Solar":
            continue
        if product_style and style_violates_construction(product_style, fields):
            continue
        if violates_hard_constraints(fields, product, explicit_fields=explicit_fields):
            continue
        score = product_score(fields, product)
        if score >= 0.44:
            scored.append((score, product))

    scored.sort(key=lambda item: item[0], reverse=True)
    return [product for _, product in scored]


def violates_hard_constraints(
    fields: dict[str, Any],
    product: Product,
    explicit_fields: set[str] | None = None,
) -> bool:
    explicit_fields = explicit_fields or set()
    hard_checks = [
        ("sheath", product.sheath),
        ("conductor", product.conductor_type),
        ("count", product.no_core),
        ("cross_section", product.cross_core),
        ("core_type", product.core),
    ]
    if "armour" in explicit_fields:
        hard_checks.append(("armour", product.armour))
    if "colour" in explicit_fields:
        hard_checks.append(("colour", product.colour))
    for field, product_value in hard_checks:
        if fields.get(field) and canonical_number(fields[field]).upper() != canonical_number(product_value).upper():
            return True
    return False


def product_score(fields: dict[str, Any], product: Product) -> float:
    checks = [
        ("style", product.style),
        ("sheath", product.sheath),
        ("config_core", product.config_core),
        ("armour", product.armour),
        ("conductor", product.conductor_type),
        ("core_type", product.core),
        ("colour", product.colour),
    ]
    score = 0.0
    possible = 0.0

    for field, value in checks:
        if fields.get(field):
            possible += 1
            if str(fields[field]).upper() == str(value).upper():
                score += 1

    if fields.get("count"):
        possible += 1.2
        if canonical_number(fields["count"]) == canonical_number(product.no_core):
            score += 1.2
    if fields.get("cross_section"):
        possible += 1.2
        if canonical_number(fields["cross_section"]) == canonical_number(product.cross_core):
            score += 1.2

    return (score / possible) if possible else 0.0


def enrich_accessory_result(result: dict[str, Any], accessory: dict[str, Any] | None = None) -> dict[str, Any]:
    fields = result.setdefault("fields", {})
    length = result.setdefault("length", {})
    accessory = accessory or {}
    quantity = _accessory_sell_units(fields.get("quantity") or length.get("number_of_lengths") or 1)
    units_per_pack = accessory.get("units_per_pack") or 1

    length["length_each"] = 1
    length["number_of_lengths"] = quantity
    length["total_length"] = quantity
    fields["quantity"] = quantity
    fields["number_of_lengths"] = quantity
    result["line_kind"] = "accessory"
    result["units_per_pack"] = units_per_pack
    result["unit_label"] = accessory.get("unit_label") or "unit"
    result["pack_label"] = accessory.get("pack_label") or "pack"
    if accessory:
        result["accessory"] = accessory

    if result.get("selected_product_code"):
        result.setdefault("final_product_code", result["selected_product_code"])

    return result


def _accessory_sell_units(value):
    try:
        quantity = float(value)
    except (TypeError, ValueError):
        quantity = 1
    if quantity <= 0:
        quantity = 1
    return int(quantity) if quantity.is_integer() else quantity


def _fmt_accessory_num(value) -> str:
    try:
        number = float(value)
    except (TypeError, ValueError):
        return str(value)
    return str(int(number)) if number.is_integer() else f"{number:g}"


def build_result(
    raw_text: str,
    source: dict[str, Any],
    extracted: dict[str, Any],
    status: str,
    item_type: str,
    selected: Product | None,
    candidates: list[Product],
    confidence: float,
    warnings: list[str],
    style_candidates: list[tuple[Style, float]] | None = None,
    inferred_fields: list[str] | None = None,
) -> dict[str, Any]:
    length_each = extracted["fields"].get("length_each_m")
    number_of_lengths = extracted["fields"].get("number_of_lengths") or 1
    total_length = extracted["fields"].get("total_length_m")
    if total_length is None and length_each is not None:
        total_length = float(length_each) * float(number_of_lengths)
        if total_length.is_integer():
            total_length = int(total_length)

    # With no catalog product selected, fall back to the engine-constructed code
    # (set by apply_code_engine) so the rep gets a usable, editable product code --
    # often a valid made-to-order (MAKE) item -- rather than an empty field. Only a
    # real catalog selection sets selected_product (the priced object); a surfaced
    # constructed code deliberately leaves that null and stays flagged for review.
    selected_code = selected.product_code if selected else (
        extracted["fields"].get("exact_accessory_code") or extracted["fields"].get("canonical_code", "")
    )

    return {
        "source": source,
        "raw_text": raw_text,
        "normalized_text": extracted["normalized_text"],
        "item_type": item_type,
        "line_kind": item_type if item_type in {"accessory", "cable"} else item_type,
        "status": status,
        "confidence": confidence,
        "fields": extracted["fields"],
        # Who set each field -- see FIELD_SOURCE_* in extractor.py. Read by the shadow
        # harness to tell an agent-proposed value from a customer-stated one; `fields`
        # alone cannot answer that, which is what made an LLM guess a hard filter.
        "field_sources": extracted.get("provenance") or {},
        "selected_product_code": selected_code,
        "selected_product": selected.public() if selected else None,
        "candidates": [candidate.public() for candidate in candidates],
        "style_candidates": [
            {"style": style.public(), "score": round(score, 2)}
            for style, score in (style_candidates or [])
        ],
        "warnings": warnings,
        "inferred_fields": inferred_fields or [],
        "length": {
            "uom": extracted["fields"].get("uom"),
            "length_each_m": length_each,
            "number_of_lengths": number_of_lengths,
            "total_length_m": total_length,
            "quantity": extracted["fields"].get("quantity"),
            "cuts_explicit": bool(extracted["fields"].get("cuts_explicit")),
        },
    }


def dedupe(values: list[str]) -> list[str]:
    seen = set()
    result = []
    for value in values:
        if value and value not in seen:
            seen.add(value)
            result.append(value)
    return result
