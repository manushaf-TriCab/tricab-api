# Generated from the RFQ parser's matcher/normalizer.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Up-front AI normalization layer.

Runs *after* local text extraction/OCR and *before* deterministic matching. It
sends extracted row text (never raw files) to OpenRouter in one batched call and
asks the model to extract structured cable fields (core count, size, conductor,
earth handling, requested construction flags, ...), optionally splitting one row
into several entries.

There is no free-text round trip: the model's fields are merged straight into
the resolver's own fields dict (see :func:`matcher.resolver.match_line`'s
``field_overrides``), the same shape :mod:`matcher.llm_fallback`'s reactive
fallback produces. The model is forbidden from emitting product codes, and the
deterministic resolver remains the single source of truth for product-code
selection; colour, voltage, style hints and comms/accessory/admin routing still
come from regex over the row's own original text.

When enabled (``RFQ_NORMALIZER_ENABLED=true``) this *replaces* the reactive
per-line fallback in :mod:`matcher.llm_fallback`, so a hard line is only sent to
the AI once. If the batched call fails, the affected rows fall back to their
original extracted text and are matched by the regex parser alone.
"""

from __future__ import annotations

from . import model_settings

import json
import os
from dataclasses import dataclass, field
from typing import Any

from rfq_parser.llm_fallback import (
    DEFAULT_MODEL,
    FIELD_SEMANTICS,
    LlmEntry,
    _entry_from_payload,
    _entry_schema,
    _float_env,
    _loads_json_object,
    _openrouter_chat,
    openrouter_enabled,
)


@dataclass
class NormalizedRow:
    """The model's translation of one extracted row into parser-language entries."""

    entries: list[LlmEntry]
    model: str
    confidence: float
    assumptions: list[str] = field(default_factory=list)
    needs_review: bool = False


def normalizer_enabled() -> bool:
    if not openrouter_enabled():
        return False
    return os.environ.get("RFQ_NORMALIZER_ENABLED", "").strip().lower() in {"1", "true", "yes", "on"}


def normalizer_model() -> str:
    return model_settings.get("RFQ_NORMALIZER_MODEL") or model_settings.get("OPENROUTER_MODEL", DEFAULT_MODEL)


def normalizer_max_items() -> int:
    try:
        return max(1, int(os.environ.get("RFQ_NORMALIZER_MAX_ITEMS", "40")))
    except ValueError:
        return 40


def normalizer_max_tokens() -> int:
    try:
        return max(500, int(os.environ.get("RFQ_NORMALIZER_MAX_TOKENS", "8000")))
    except ValueError:
        return 8000


def normalizer_config() -> dict[str, Any]:
    """Health-endpoint view of the normalizer configuration."""
    return {
        "enabled": normalizer_enabled(),
        "model": normalizer_model(),
        "max_items": normalizer_max_items(),
        "max_tokens": normalizer_max_tokens(),
        "timeout_seconds": _float_env("RFQ_NORMALIZER_TIMEOUT_SECONDS", 30),
    }


def _full_entry(**overrides: Any) -> dict[str, Any]:
    """A complete _entry_schema()-conformant entry dict with sensible nulls, used to
    build the worked few-shot example below -- filling every required key so the
    example is schema-valid and needs no post-processing before rendering."""
    base: dict[str, Any] = {
        "reasoning": "", "description": "", "quantity": None, "number_of_lengths": None,
        "length_each": None, "unit": None, "circuit_label": None, "drum_mark": None,
        "customer_note": None, "core_count": None,
        "core_type": None, "cross_section_mm2": None, "conductor": None,
        "has_earth_core": None, "earth_multiplicity": None, "is_earth_only": False,
        "cable_type_hint": None, "screen_requested": None, "armoured_requested": None,
        "fire_rated_requested": None, "recognized_standard": None, "standard_sheath": None,
        "style_series_code": None, "confidence": 0.8, "notes": "",
    }
    base.update(overrides)
    return base


# Worked few-shot examples, grouped by scenario. Each group renders as one complete
# worked batch call (input rows, then the exact output), teaching the
# envelope/index-mapping while demonstrating the recurring house rules. Rows are
# sourced from existing, passing tests or the cable-style-audit corpus rather than
# invented, and tests/test_normalizer.py::FewShotExamplesTests proves each row's
# "expect" resolution through match_line -- executable documentation, not just
# plausible-looking examples.
#
# Per-row keys: text / current_section (model-facing input), entries (the ideal
# model output), plus test-only proof metadata never rendered into the prompt:
#   expect  -- list aligned with entries; each element is a (status, product_code)
#              pair proven by the tests, or None to skip resolution proof for that
#              entry (e.g. heading-only sparse rows with no deterministic product).
#   cite    -- where the row/expectation comes from.
#   prove_with_context -- pass current_section as match_line context in the proof
#              (only where the resolution genuinely depends on the sheet heading).
_FEW_SHOT_GROUPS: list[dict[str, Any]] = [
    {
        "title": "core & earth notation, temperature/sheath defaults",
        "rows": [
            {
                "text": "4C+E 120mm Cu",
                "entries": [_full_entry(
                    reasoning="4C+E = 4 mains + 1 earth -> core_count 5; 120mm2 Cu; no temp/sheath/screen/armour stated; plain power.",
                    description="4C+E 120mm2 Cu power cable",
                    core_count=5, core_type="C", cross_section_mm2=120, conductor="Cu",
                    has_earth_core=True, cable_type_hint="Power", confidence=0.85,
                )],
                "expect": [("matched", "XL-PAXN/5C120OR")],
                "cite": "test_resolver.HouseDefaultStyleTests.test_full_default_chain_resolves_to_single_pushable_product",
            },
            {
                "text": "4C+E 120mm Cu 110 deg",
                "entries": [_full_entry(
                    reasoning="'110 deg' is a temperature (spaced number + deg), never a core count; cores 4+1E=5.",
                    description="4C+E 120mm2 Cu 110C power cable",
                    core_count=5, core_type="C", cross_section_mm2=120, conductor="Cu",
                    has_earth_core=True, cable_type_hint="Power", confidence=0.85,
                )],
                "expect": [("matched", "KL-PAXN/5C120OR")],
                "cite": "test_resolver.HouseDefaultStyleTests.test_high_temp_deg_without_trailing_c_defaults_to_kl",
            },
            {
                "text": "4C+E 120mm Cu LSZH",
                "entries": [_full_entry(
                    reasoning="4+1E=5 cores; LSZH is stated in the row's own text (no standard involved, so standard_sheath stays null).",
                    description="4C+E 120mm2 Cu LSZH power cable",
                    core_count=5, core_type="C", cross_section_mm2=120, conductor="Cu",
                    has_earth_core=True, cable_type_hint="Power", confidence=0.85,
                )],
                "expect": [("matched", "CC-PAXN/5C120OR")],
                "cite": "test_resolver.HouseDefaultStyleTests.test_lszh_defaults_to_cc",
            },
            {
                "text": "CABLE 3C 120mm2 + 3E 16mm2 VSD / EMC FLEXIBLE DRIVE CABLE",
                "entries": [_full_entry(
                    reasoning="3 mains + 3 SPLIT earths: split earths collapse to ONE earth core -> core_count 4, earth_multiplicity 3; VSD/EMC -> screened drive family.",
                    description="3C+3E 120mm2/16mm2 VSD/EMC flexible drive cable",
                    core_count=4, core_type="C", cross_section_mm2=120,
                    has_earth_core=True, earth_multiplicity=3,
                    cable_type_hint="VSD/VFD", screen_requested=True, confidence=0.85,
                )],
                "expect": [("matched", "XU-PAZN/4C120BK")],
                "cite": "test_normalizer.test_split_earths_collapse_to_single_earth_core",
            },
            {
                "text": "XLPE CU EARTH 1C 25mm2",
                "entries": [_full_entry(
                    reasoning="Single-core earth-only conductor: is_earth_only true, and the earth core IS the one core.",
                    description="1C 25mm2 Cu earth cable",
                    core_count=1, core_type="C", cross_section_mm2=25, conductor="Cu",
                    has_earth_core=True, is_earth_only=True, confidence=0.85,
                )],
                "expect": [("matched", "LP-XXXN/1C25EA")],
                "cite": "test_resolver.FireRatedEarthTests.test_ordinary_earth_cable_uses_lp_series",
            },
            {
                "text": "FR 110° CU EARTH 1C 25mm2",
                "entries": [_full_entry(
                    reasoning="Earth-only conductor with explicit FR -> fire_rated_requested true (FR = fire rated, stated in the text).",
                    description="Fire-rated 1C 25mm2 Cu earth cable",
                    core_count=1, core_type="C", cross_section_mm2=25, conductor="Cu",
                    has_earth_core=True, is_earth_only=True, fire_rated_requested=True,
                )],
                "expect": [("matched", "LS-XXXN/1C25EA")],
                "cite": "test_resolver.FireRatedEarthTests.test_explicit_fr_110_earth_cable_uses_ls_series",
            },
        ],
    },
    {
        "title": "armoured cables",
        "rows": [
            {
                "text": "1C 25mm2 SWA PVC copper",
                "entries": [_full_entry(
                    reasoning="Single core 25mm2 Cu; SWA stated -> armoured_requested; the single-core armour TYPE (AWA vs SWA) is the engine's call, not mine.",
                    description="1C 25mm2 armoured PVC Cu cable",
                    core_count=1, core_type="C", cross_section_mm2=25, conductor="Cu",
                    armoured_requested=True, confidence=0.85,
                )],
                "expect": [("matched", "XA-PAJN/1C25OR")],
                "cite": "test_resolver.ConstructionFamilyConsistencyTests.test_single_core_armour_uses_awa_and_defaults_to_xa",
            },
            {
                "text": "4C 2.5MM2 (SWA) PVC SHEATHED (BK)-SWA-PVC INSULATED",
                "entries": [_full_entry(
                    reasoning="4 cores 2.5mm2, SWA -> armoured; no control/instrument wording, so cable_type_hint stays null (power is the engine's default); colour is read from the text by the engine.",
                    description="4C 2.5mm2 SWA PVC black cable",
                    core_count=4, core_type="C", cross_section_mm2=2.5,
                    armoured_requested=True, confidence=0.85,
                )],
                "expect": [("matched", "XA-PASN/4C2.5BK")],
                "cite": "test_resolver.ConstructionFamilyConsistencyTests.test_swa_pvc_black_smallcore_defaults_to_armoured_power",
            },
            {
                "text": "4C+E 35mm2 Cu high temp SWA",
                "entries": [_full_entry(
                    reasoning="4+1E=5 cores; SWA -> armoured; 'high temp' is temperature wording for the engine's ladder.",
                    description="4C+E 35mm2 Cu high-temp SWA cable",
                    core_count=5, core_type="C", cross_section_mm2=35, conductor="Cu",
                    has_earth_core=True, armoured_requested=True,
                    cable_type_hint="Power", confidence=0.85,
                )],
                "expect": [("matched", "KA-PASN/5C35BK")],
                "cite": "test_resolver.ConstructionFamilyConsistencyTests.test_armoured_high_temp_selects_ka",
            },
            {
                "text": "4C+E 35mm2 Cu XLPE LSZH SWA",
                "entries": [_full_entry(
                    reasoning="4+1E=5 cores; SWA -> armoured; LSZH sheath stated in the text.",
                    description="4C+E 35mm2 Cu LSZH SWA cable",
                    core_count=5, core_type="C", cross_section_mm2=35, conductor="Cu",
                    has_earth_core=True, armoured_requested=True,
                    cable_type_hint="Power", confidence=0.85,
                )],
                "expect": [("matched", "CA-PASN/5C35BK")],
                "cite": "test_resolver.ConstructionFamilyConsistencyTests.test_armoured_lszh_selects_ca",
            },
        ],
    },
    {
        "title": "screened / VSD / EMC family",
        "rows": [
            {
                "text": "VSD 3C 4mm + E 0.6/1kV",
                "entries": [_full_entry(
                    reasoning="3 mains + ONE earth (single, not split) -> core_count 4, no earth_multiplicity; VSD -> screened drive family.",
                    description="VSD 3C+E 4mm2 screened drive cable",
                    core_count=4, core_type="C", cross_section_mm2=4,
                    has_earth_core=True, cable_type_hint="VSD/VFD",
                    screen_requested=True, confidence=0.85,
                )],
                "expect": [("matched", "DP-PAZN/4C4OR")],
                "cite": "test_resolver.ScreenedVsdFamilyTests.test_single_earth_config_defaults_to_dp",
            },
            {
                "text": "VSD 3C 4mm + 3E 0.6/1kV",
                "entries": [_full_entry(
                    reasoning="3 mains + 3 split earths -> core_count 4, earth_multiplicity 3; VSD screened.",
                    description="VSD 3C+3E 4mm2 screened drive cable",
                    core_count=4, core_type="C", cross_section_mm2=4,
                    has_earth_core=True, earth_multiplicity=3,
                    cable_type_hint="VSD/VFD", screen_requested=True, confidence=0.85,
                )],
                "expect": [("matched", "XU-PAZN/4C4BK")],
                "cite": "test_resolver.ScreenedVsdFamilyTests.test_split_earths_select_xu_as_a_four_core",
            },
            {
                "text": "LSZH VSD 3C 4mm + 3E 0.6/1kV",
                "entries": [_full_entry(
                    reasoning="Same 3C+3E split-earth VSD, LSZH sheath stated.",
                    description="LSZH VSD 3C+3E 4mm2 screened drive cable",
                    core_count=4, core_type="C", cross_section_mm2=4,
                    has_earth_core=True, earth_multiplicity=3,
                    cable_type_hint="VSD/VFD", screen_requested=True, confidence=0.85,
                )],
                "expect": [("matched", "EU-PAZN/4C4OR")],
                "cite": "test_resolver.ScreenedVsdFamilyTests.test_split_earth_lszh_selects_eu_in_pazn_form",
            },
            {
                "text": "VSD 3C 4mm + 3E SWA 0.6/1kV",
                "entries": [_full_entry(
                    reasoning="3C+3E split-earth VSD with SWA -> armoured_requested too.",
                    description="VSD 3C+3E 4mm2 SWA screened drive cable",
                    core_count=4, core_type="C", cross_section_mm2=4,
                    has_earth_core=True, earth_multiplicity=3,
                    cable_type_hint="VSD/VFD", screen_requested=True,
                    armoured_requested=True, confidence=0.85,
                )],
                "expect": [("matched", "EA-PASN/4C4BK")],
                "cite": "test_resolver.ScreenedVsdFamilyTests.test_split_earth_armour_selects_ea_in_pasn_form",
            },
            {
                "text": "VSD 3C 4mm + 3E FR 0.6/1kV",
                "entries": [_full_entry(
                    reasoning="3C+3E split-earth VSD with FR -> fire_rated_requested.",
                    description="Fire-rated VSD 3C+3E 4mm2 screened drive cable",
                    core_count=4, core_type="C", cross_section_mm2=4,
                    has_earth_core=True, earth_multiplicity=3,
                    cable_type_hint="VSD/VFD", screen_requested=True,
                    fire_rated_requested=True, confidence=0.85,
                )],
                "expect": [("matched", "FH-PATN/4C4RE")],
                "cite": "test_resolver.ScreenedVsdFamilyTests.test_fire_vsd_selects_fh",
            },
            {
                "text": "2.5 mm2 Circ, 4C+E VSD LSZH Screened",
                "entries": [_full_entry(
                    reasoning="Extract faithfully even when the combination is unusual: 4C+E (single earth) VSD LSZH screened; whether a product exists is the engine's call.",
                    description="4C+E 2.5mm2 VSD LSZH screened cable",
                    core_count=5, core_type="C", cross_section_mm2=2.5,
                    has_earth_core=True, cable_type_hint="VSD/VFD",
                    screen_requested=True,
                )],
                # No stocked catalog product, but the engine's constructed code is
                # surfaced as a generated code; Cost API decides stock/make/unavailable.
                "expect": [("matched", "EU-PAZN/5C2.5BK")],
                "cite": "test_resolver.ScreenedVsdFamilyTests.test_non_3c3e_lszh_vsd_surfaces_constructed_make_code",
            },
            {
                "text": "4C+E 120mm Cu EMC",
                "entries": [_full_entry(
                    reasoning="EMC implies the VSD/VFD screened family (every VSD cable is an EMC cable); 4+1E=5 cores.",
                    description="4C+E 120mm2 Cu EMC screened cable",
                    core_count=5, core_type="C", cross_section_mm2=120, conductor="Cu",
                    has_earth_core=True, cable_type_hint="VSD/VFD",
                    screen_requested=True, confidence=0.85,
                )],
                "expect": [("matched", "XU-PAZN/5C120BK")],
                "cite": "test_resolver.ScreenedVsdFamilyTests.test_emc_routes_to_vsd_family",
            },
            {
                "text": "CABLE 1C 400mm2 FLEXIBLE OAS EMC POWER CABLE 90Deg.C 0.6/1KV",
                "entries": [_full_entry(
                    reasoning="OAS = overall armoured screen -> screen_requested AND armoured_requested (never any other expansion); EMC -> VSD/VFD family.",
                    description="1C 400mm2 flexible OAS EMC power cable",
                    core_count=1, core_type="C", cross_section_mm2=400,
                    screen_requested=True, armoured_requested=True,
                    cable_type_hint="VSD/VFD",
                )],
                # No stocked catalog product; the engine's constructed code is surfaced
                # as a generated code; Cost API decides stock/make/unavailable.
                "expect": [("matched", "EA-PASN/1C400BK")],
                "cite": "test_normalizer.test_oas_grounds_both_screen_and_armour",
            },
        ],
    },
    {
        "title": "instrumentation (pairs, voltage bands, fire)",
        "rows": [
            {
                "text": "Instrument 4 pair 1.5mm",
                "entries": [_full_entry(
                    reasoning="'4 pair' -> core_type P, core_count 4; pairs always mean instrumentation; no voltage stated (the engine defaults the band).",
                    description="Instrument cable 4P 1.5mm2",
                    core_count=4, core_type="P", cross_section_mm2=1.5,
                    cable_type_hint="Instrumentation",
                )],
                "expect": [("matched", "R1-DEXN/4P1.5BK")],
                "cite": "test_resolver.InstrumentationFamilyTests.test_no_voltage_defaults_to_110v_r1",
            },
            {
                "text": "Instrument 4 pair 1.5mm 450/750V",
                "entries": [_full_entry(
                    reasoning="4 pairs, instrumentation; 450/750V is a voltage class the engine bands.",
                    description="Instrument cable 4P 1.5mm2 450/750V",
                    core_count=4, core_type="P", cross_section_mm2=1.5,
                    cable_type_hint="Instrumentation", confidence=0.85,
                )],
                "expect": [("matched", "RE-DEXN/4P1.5BK")],
                "cite": "test_resolver.InstrumentationFamilyTests.test_mid_voltage_band_selects_re",
            },
            {
                "text": "Instrument 4 pair 1.5mm 0.6/1kV",
                "entries": [_full_entry(
                    reasoning="4 pairs, instrumentation, 0.6/1kV class.",
                    description="Instrument cable 4P 1.5mm2 0.6/1kV",
                    core_count=4, core_type="P", cross_section_mm2=1.5,
                    cable_type_hint="Instrumentation", confidence=0.85,
                )],
                "expect": [("matched", "RF-DEXN/4P1.5BK")],
                "cite": "test_resolver.InstrumentationFamilyTests.test_high_voltage_band_selects_rf",
            },
            {
                "text": "Instrument 4 pair 1.5mm individual and overall screen 450/750V",
                "entries": [_full_entry(
                    reasoning="4 pairs instrumentation; individual + overall screen stated -> screen_requested.",
                    description="Instrument cable 4P 1.5mm2 IOS 450/750V",
                    core_count=4, core_type="P", cross_section_mm2=1.5,
                    cable_type_hint="Instrumentation", screen_requested=True,
                    confidence=0.85,
                )],
                "expect": [("matched", "RH-DEXN/4P1.5BK")],
                "cite": "test_resolver.InstrumentationFamilyTests.test_individual_and_overall_screen_selects_rh",
            },
            {
                "text": "Instrument 6 triad 1.5mm IOS 450/750V",
                "entries": [_full_entry(
                    reasoning="'6 triad' -> core_type T, core_count 6 (GROUPS, never 18 conductors); IOS = individual + overall screen -> screen_requested.",
                    description="Instrument cable 6T 1.5mm2 IOS 450/750V",
                    core_count=6, core_type="T", cross_section_mm2=1.5,
                    cable_type_hint="Instrumentation", screen_requested=True,
                    confidence=0.85,
                )],
                "expect": [("matched", "RH-DEXN/6T1.5BK")],
                "cite": "triad group-count + IOS glossary; resolution verified 2026-07-02",
            },
            {
                "text": "1.5mm 12pr Ctrl LSZH IOAS FR",
                "entries": [_full_entry(
                    reasoning="'12pr' -> 12 pairs -> instrumentation despite the 'Ctrl' word; IOAS = individual + overall armoured screen -> screen AND armour; FR -> fire.",
                    description="Fire-rated 12P 1.5mm2 LSZH IOAS instrument cable",
                    core_count=12, core_type="P", cross_section_mm2=1.5,
                    cable_type_hint="Instrumentation", screen_requested=True,
                    armoured_requested=True, fire_rated_requested=True,
                )],
                "expect": [("matched", "WH-DEXN/12P1.5RE")],
                "cite": "test_resolver.InstrumentationFamilyTests.test_fire_armoured_instrument_still_selects_wh",
            },
            {
                "text": "2pr 0.75mm LSZH fire instrument coll screen",
                "entries": [_full_entry(
                    reasoning="2 pairs, fire instrument, collective (overall) screen -> screen_requested.",
                    description="Fire instrument cable 2P 0.75mm2 LSZH OS",
                    core_count=2, core_type="P", cross_section_mm2=0.75,
                    cable_type_hint="Instrumentation", screen_requested=True,
                    fire_rated_requested=True,
                )],
                "expect": [("matched", "WH-DEXN/2P.75RE")],
                "cite": "test_resolver.InstrumentationFamilyTests.test_wh_code_prefers_stocked_de_re_row_when_available",
            },
            {
                "text": "6 pair signal cable 0.5mm 110V",
                "entries": [_full_entry(
                    reasoning="Pairs override the 'signal' wording -> instrumentation.",
                    description="Signal/instrument cable 6P 0.5mm2 110V",
                    core_count=6, core_type="P", cross_section_mm2=0.5,
                    cable_type_hint="Instrumentation", confidence=0.85,
                )],
                "expect": [("matched", "R1-DEXN/6P.5BK")],
                "cite": "test_resolver.ControlSignalFamilyTests.test_signal_with_pairs_routes_to_instrumentation",
            },
        ],
    },
    {
        "title": "control & signal",
        "rows": [
            {
                "text": "Control cable 4C 1.5mm Cu PVC",
                "entries": [_full_entry(
                    reasoning="Plain-core control, 4C 1.5mm2 Cu PVC.",
                    description="Control cable 4C 1.5mm2 Cu PVC",
                    core_count=4, core_type="C", cross_section_mm2=1.5, conductor="Cu",
                    cable_type_hint="Control", confidence=0.85,
                )],
                "expect": [("matched", "XL-CAXN/4C1.5BK")],
                "cite": "test_resolver.ControlSignalFamilyTests.test_small_core_control_hard_defaults_to_xl_ca_black",
            },
            {
                "text": "7C 2.5mm control cable",
                "entries": [_full_entry(
                    reasoning="7 cores 2.5mm2, control.",
                    description="Control cable 7C 2.5mm2",
                    core_count=7, core_type="C", cross_section_mm2=2.5,
                    cable_type_hint="Control", confidence=0.85,
                )],
                "expect": [("matched", "XL-CAXN/7C2.5BK")],
                "cite": "test_resolver.ConfigCodeDefaultTests.test_multicore_control_defaults_to_xl_ca",
            },
            {
                "text": "7C 2.5mm 110C control cable",
                "entries": [_full_entry(
                    reasoning="7 cores 2.5mm2 control; '110C' here is a temperature (adjacent to a plausible core token, but the row already has its own 7C count).",
                    description="Control cable 7C 2.5mm2 110C",
                    core_count=7, core_type="C", cross_section_mm2=2.5,
                    cable_type_hint="Control", confidence=0.85,
                )],
                "expect": [("matched", "KL-CAXN/7C2.5BK")],
                "cite": "test_resolver.ConfigCodeDefaultTests.test_multicore_control_110c_uses_kl_ca",
            },
            {
                "text": "Control 3C 1.5mm flexible",
                "entries": [_full_entry(
                    reasoning="Explicitly FLEXIBLE control -> the engine keeps the flexible series; my job is only the fields.",
                    description="Flexible control cable 3C 1.5mm2",
                    core_count=3, core_type="C", cross_section_mm2=1.5,
                    cable_type_hint="Control", confidence=0.85,
                )],
                "expect": [("matched", "C1-CAXN/3C1.5BK")],
                "cite": "test_resolver.ControlSignalFamilyTests.test_flexible_control_stays_c_series",
            },
            {
                "text": "Signal cable 4C 1.0mm",
                "entries": [_full_entry(
                    reasoning="Plain cores (no pairs) with 'signal': NOT instrumentation; leave cable_type_hint null and let the engine's own signal routing decide.",
                    description="Signal cable 4C 1.0mm2",
                    core_count=4, core_type="C", cross_section_mm2=1.0,
                )],
                "expect": [("matched", "WG-CAXD/4C1BK")],
                "cite": "test_resolver.ControlSignalFamilyTests.test_plain_core_signal_routes_to_wg",
            },
            {
                "text": "Control cables all black sheath 0.75mm2 Cu 4C+E PVC 260m",
                "entries": [_full_entry(
                    reasoning="Control 4C+E -> 5 cores, 0.75mm2 Cu PVC; 260m is one total length with no cut count -> length_each 260.",
                    description="Control cable 4C+E 0.75mm2 Cu PVC black, 260m",
                    core_count=5, core_type="C", cross_section_mm2=0.75, conductor="Cu",
                    has_earth_core=True, cable_type_hint="Control",
                    length_each=260, unit="M", confidence=0.85,
                )],
                "expect": [("matched", "XL-CAXN/5C.75BK")],
                "cite": "test_resolver.ConstructionFamilyConsistencyTests.test_colour_fallback_when_no_black_product_exists",
            },
            {
                "text": "MCC-01 to FE-101: 7C 2.5mm2 control cable 150m",
                "entries": [_full_entry(
                    reasoning="'MCC-01 to FE-101' is a FROM/TO circuit label stated in the row itself; 150m total, no cut count -> length_each 150.",
                    description="Control cable 7C 2.5mm2, 150m, circuit MCC-01 to FE-101",
                    core_count=7, core_type="C", cross_section_mm2=2.5,
                    cable_type_hint="Control", length_each=150, unit="M",
                    circuit_label="MCC-01 to FE-101", confidence=0.85,
                )],
                "expect": [("matched", "XL-CAXN/7C2.5BK")],
                "cite": "verified against test_resolver.ConfigCodeDefaultTests.test_multicore_control_defaults_to_xl_ca",
            },
        ],
    },
    {
        "title": "special constructions & MV",
        "rows": [
            {
                "text": "2C 2.5mm flat cable 100m",
                "entries": [_full_entry(
                    reasoning="2 cores 2.5mm2; 'flat' is a construction keyword the engine routes; 100m total -> length_each 100.",
                    description="2C 2.5mm2 flat cable, 100m",
                    core_count=2, core_type="C", cross_section_mm2=2.5,
                    length_each=100, unit="M", confidence=0.85,
                )],
                "expect": [("matched", "FD-PAXN/2C2.5OR")],
                "cite": "test_resolver.ConstructionFamilyConsistencyTests.test_flat_request_selects_fd_style",
            },
            {
                "text": "SOLAR AL 1C 25mm2",
                "entries": [_full_entry(
                    reasoning="Explicit SOLAR -> cable_type_hint Solar; aluminium conductor; 1C 25mm2.",
                    description="Solar Al 1C 25mm2 cable",
                    core_count=1, core_type="C", cross_section_mm2=25, conductor="Al",
                    cable_type_hint="Solar",
                )],
                "expect": [("matched", "SK-PAXA/1C25BK")],
                "cite": "test_resolver.SolarSelectionTests.test_explicit_solar_cable_can_select_solar_series",
            },
            {
                "text": "3C 35mm2 SWA 11kV copper",
                "entries": [_full_entry(
                    reasoning="3C 35mm2 Cu, SWA -> armoured; 11kV is a voltage class the engine gates on.",
                    description="3C 35mm2 SWA 11kV Cu cable",
                    core_count=3, core_type="C", cross_section_mm2=35, conductor="Cu",
                    armoured_requested=True, confidence=0.85,
                )],
                "expect": [("matched", "S4-PASN/3C35OR")],
                "cite": "test_resolver.ConstructionGateTests.test_s4_selected_when_11kv_is_stated",
            },
            {
                "text": "XLPE/PVC HF110 LSZH FLEXIBLE CLASS 5 TERMITE NYLON 1C 185mm",
                "entries": [_full_entry(
                    reasoning="Special construction keywords (flexible, class 5, termite, nylon) are the engine's job; extract only the numeric core/size facts.",
                    description="LSZH flexible termite/nylon 1C 185mm2 cable",
                    core_count=1, core_type="C", cross_section_mm2=185,
                )],
                "expect": [("matched", "CDT-PAXN/1C185OR")],
                "cite": "test_resolver.ConstructionFamilyConsistencyTests.test_lszh_flexible_class_5_termite_nylon_selects_cdt",
            },
        ],
    },
    {
        "title": "parallel single-core / trefoil splits & style series codes",
        "rows": [
            {
                "text": "8x 185mm + 1x 185mm earth",
                "current_section": "Aluminium KL-XH-F 110C AL",
                # The 110C section makes this the SPECIAL LS earth, not the LP default,
                # so the earth's expectation is only correct with the section in context.
                "prove_with_context": True,
                "entries": [
                    _full_entry(
                        reasoning="'8x 185mm + 1x 185mm earth' = 8 parallel single-core PHASE conductors plus a separate earth -> split into two entries, NOT a multicore, NOT earth-only; the section heading names the KL series and Aluminium.",
                        description="8x single-core 185mm2 Aluminium phase conductors, KL series",
                        core_count=1, core_type="C", cross_section_mm2=185, conductor="Al",
                        is_earth_only=False, number_of_lengths=8, style_series_code="KL",
                        confidence=0.75,
                    ),
                    _full_entry(
                        reasoning="The '1x 185mm earth' half: one single-core earth conductor; conductor MUST match the phase entry (Aluminium), and it resolves as an earth (XX/EA), not the phase style. The 110C section makes it the special LS earth (the default earth is LP).",
                        description="1x single-core 185mm2 Aluminium earth conductor",
                        core_count=1, core_type="C", cross_section_mm2=185, conductor="Al",
                        has_earth_core=True, is_earth_only=True, number_of_lengths=1,
                        confidence=0.75,
                    ),
                ],
                "expect": [("matched", "KL-PAXA/1C185BK"), ("matched", "LS-XXXA/1C185EA")],
                "cite": "test_resolver.ParallelSingleCoreEscalationTests + StyleSeriesHintFromLlmTests",
            },
            {
                "text": "6x 185mm + 70mm Earth",
                "entries": [
                    _full_entry(
                        reasoning="6 parallel single-core phase conductors 185mm2; the 70mm is a separate REDUCED earth -> two entries.",
                        description="6x single-core 185mm2 phase conductors",
                        core_count=1, core_type="C", cross_section_mm2=185,
                        is_earth_only=False, number_of_lengths=6, confidence=0.75,
                    ),
                    _full_entry(
                        reasoning="Earth half: no count stated before the earth size -> number_of_lengths 1; conductor matches the phase entry (unstated here, so null on both).",
                        description="1x single-core 70mm2 earth conductor",
                        core_count=1, core_type="C", cross_section_mm2=70,
                        has_earth_core=True, is_earth_only=True, number_of_lengths=1,
                        confidence=0.75,
                    ),
                ],
                "expect": [("matched", "XL-PAXN/1C185OR"), ("matched", "LP-XXXN/1C70EA")],
                "cite": "FIELD_SEMANTICS parallel single-core rule; resolutions verified 2026-07-02",
            },
            {
                "text": "3 x 1 core 240mm2 Cu Trefoil + 1 x 120mm2 earth",
                "entries": [
                    _full_entry(
                        reasoning="'3 x 1 core Trefoil' = 3 single-core conductors in trefoil formation, never a multicore -- the trailing '1 core' is trefoil wording, not the cable's core count.",
                        description="3x single-core 240mm2 Cu phase conductors (trefoil)",
                        core_count=1, core_type="C", cross_section_mm2=240, conductor="Cu",
                        is_earth_only=False, number_of_lengths=3, confidence=0.75,
                    ),
                    _full_entry(
                        reasoning="Separately-sized earth: one single-core 120mm2, conductor matching the phase (Cu).",
                        description="1x single-core 120mm2 Cu earth conductor",
                        core_count=1, core_type="C", cross_section_mm2=120, conductor="Cu",
                        has_earth_core=True, is_earth_only=True, number_of_lengths=1,
                        confidence=0.75,
                    ),
                ],
                "expect": [("matched", "XL-PAXN/1C240OR"), ("matched", "LP-XXXN/1C120EA")],
                "cite": "FIELD_SEMANTICS trefoil rule; resolutions verified 2026-07-02",
            },
            {
                "text": "XU Series 4C 6mm cable",
                "entries": [_full_entry(
                    reasoning="'XU Series' names the house style inline -> style_series_code XU (the token immediately before 'Series').",
                    description="XU series 4C 6mm2 cable",
                    core_count=4, core_type="C", cross_section_mm2=6,
                    style_series_code="XU", confidence=0.85,
                )],
                "expect": [("matched", "XU-PAZN/4C6BK")],
                "cite": "test_resolver.StyleSeriesHintFromLlmTests.test_regex_series_wins_over_llm_supplied_style",
            },
            {
                "text": "KL Series X-HF-110/PVC 110 C 4C 25mm2",
                "entries": [_full_entry(
                    reasoning="'KL Series' -> style_series_code KL; '110 C' (spaced) is a temperature, never 110 cores; 'X-HF-110/PVC' means PVC sheath (the code AFTER the slash is the sheath).",
                    description="KL series PVC 110C 4C 25mm2 cable",
                    core_count=4, core_type="C", cross_section_mm2=25,
                    style_series_code="KL", confidence=0.85,
                )],
                "expect": [("matched", "KL-PAXN/4C25OR")],
                "cite": "cable-style-defaults 2026-07-01 rules; resolution verified 2026-07-02",
            },
        ],
    },
    {
        "title": "sparse rows, headings, standards, logistics, non-cable rows",
        "rows": [
            {
                "text": "1.5mm2",
                "current_section": "CONTROLS Cable Types",
                "entries": [_full_entry(
                    reasoning="Row text is size-only; the current_section heading supplies the cable type (Control) -- never a count or size.",
                    description="1.5mm2 (control, from section heading)",
                    cross_section_mm2=1.5, cable_type_hint="Control",
                    confidence=0.6, notes="Cable type inferred from the CONTROLS section heading; row itself is size-only.",
                )],
                "expect": [None],
                "cite": "context-aware classification 2026-07-01",
            },
            {
                "text": "AL 1C 25mm2",
                "current_section": "Solar cables",
                "entries": [_full_entry(
                    reasoning="Row's own text has no cable type; the 'Solar cables' section heading supplies cable_type_hint Solar.",
                    description="Al 1C 25mm2 (solar, from section heading)",
                    core_count=1, core_type="C", cross_section_mm2=25, conductor="Al",
                    cable_type_hint="Solar", confidence=0.7,
                    notes="Cable type inferred from the 'Solar cables' section heading.",
                )],
                "expect": [("matched", "SK-PAXA/1C25BK")],
                "prove_with_context": True,
                "cite": "test_resolver.SolarSelectionTests.test_solar_context_can_select_solar_series",
            },
            {
                "text": "95mm x 525mtr",
                "current_section": "Al/XLPE cables",
                "prove_with_context": True,
                "entries": [_full_entry(
                    reasoning="Row text is size + length only; the 'Al/XLPE cables' heading supplies the conductor (Al) for every row under it -- the row's own text is silent on material. No core count stated, so core_count stays null (the engine defaults it); 525mtr is one length of 525 m.",
                    description="95mm2 Al cable, 525m (conductor from section heading)",
                    cross_section_mm2=95, conductor="Al", cable_type_hint="Power",
                    number_of_lengths=1, length_each=525, unit="M", confidence=0.7,
                    notes="Conductor Al inherited from the 'Al/XLPE cables' section heading.",
                )],
                "expect": [("matched", "XL-PAXA/1C95BK")],
                "cite": "Bambach email 2026-08-19; heading construction inheritance",
            },
            {
                "text": "16mm x 175mtr",
                "current_section": "Cu/PVC earth",
                "prove_with_context": True,
                "entries": [_full_entry(
                    reasoning="The 'Cu/PVC earth' heading makes every row under it a standalone Cu earth conductor; the row's own text is size + length only -> is_earth_only true, conductor Cu, one length of 175 m.",
                    description="16mm2 Cu earth conductor, 175m (earth from section heading)",
                    cross_section_mm2=16, conductor="Cu",
                    has_earth_core=True, is_earth_only=True,
                    number_of_lengths=1, length_each=175, unit="M", confidence=0.7,
                    notes="Earth-only and conductor Cu inherited from the 'Cu/PVC earth' section heading.",
                )],
                "expect": [("matched", "LP-XXXN/1C16EA")],
                "cite": "Bambach email 2026-08-19; heading construction inheritance",
            },
            {
                "text": "5x10mm2 MCMK",
                "entries": [_full_entry(
                    reasoning="MCMK is a recognized construction standard (PVC-sheathed Cu power cable); in 'NxSIZE STANDARD' the N is the CORE count, not a cut count -> 5 cores 10mm2.",
                    description="MCMK 5C 10mm2 Cu power cable",
                    core_count=5, core_type="C", cross_section_mm2=10, conductor="Cu",
                    cable_type_hint="Power", recognized_standard="MCMK",
                    standard_sheath="PVC",
                )],
                "expect": [("matched", "XL-PAXN/5C10OR")],
                "cite": "needs_ai_normalization docstring example; resolution verified 2026-07-02",
            },
            {
                "text": "4C+E 16mm2 Cu XLPE 3 x 250m drums",
                "entries": [_full_entry(
                    reasoning="'3 x 250m drums' is logistics: number_of_lengths 3, length_each 250, unit M; cores 4+1E=5.",
                    description="4C+E 16mm2 Cu, 3 cuts of 250m",
                    core_count=5, core_type="C", cross_section_mm2=16, conductor="Cu",
                    has_earth_core=True, cable_type_hint="Power",
                    number_of_lengths=3, length_each=250, unit="M", confidence=0.85,
                )],
                "expect": [("matched", "XL-PAXN/5C16OR")],
                "cite": "quantity/logistics fields; resolution verified 2026-07-02",
            },
            {
                # Three things at once, all of which were being lost. The triple is
                # completed by division (a total plus a cut count and no per-cut length
                # left the quote with no cut breakdown to print); the drum mark has a
                # field instead of being narrated into notes and dropped; and the two
                # comment parts stay separate -- a drum mark is not a circuit label.
                "text": "Circuit 3: 4C 16mm2 Cu XLPE 210m in 6 cuts, drum mark DR-7781",
                "entries": [_full_entry(
                    reasoning=(
                        "'210m in 6 cuts' gives two of the three numbers, so the third is "
                        "division: 210/6 = 35 per cut. 'Circuit 3' is the circuit_label. "
                        "'drum mark DR-7781' is a drum identifier, NOT a circuit label -- it "
                        "goes in drum_mark and affects no cable fact. Cores 4C, 16mm2, Cu."
                    ),
                    description="4C 16mm2 Cu XLPE, 6 cuts of 35m, drum mark DR-7781",
                    core_count=4, core_type="C", cross_section_mm2=16, conductor="Cu",
                    cable_type_hint="Power",
                    quantity=210, number_of_lengths=6, length_each=35, unit="M",
                    circuit_label="Circuit 3", drum_mark="DR-7781", confidence=0.85,
                )],
                "expect": [("matched", "XL-PAXN/4C16OR")],
                "cite": "drum_mark + complete cut triple; resolution verified 2026-09-10",
            },
            {
                # A lead time belongs on the quote (Manusha 2026-09-10: sales add it to
                # the comment by hand today), and it is transcribed, not summarized --
                # `_label_grounded` drops a customer_note that is not in the source text.
                # The row also shows the two note destinations staying separate: the
                # customer's words in customer_note, nothing of the model's own.
                "text": "KL-PAXA/1C500BK 1 x 500 m | Lead Time: 6 drums: 4 weeks ex Melbourne; remaining 42: 9 weeks from order",
                "entries": [_full_entry(
                    reasoning=(
                        "One drum of 500m: quantity 500, number_of_lengths 1, length_each 500. "
                        "The lead-time text is the customer's own and belongs beside the line, so "
                        "it is copied verbatim into customer_note -- not summarized, and not put "
                        "in notes, which never reaches the quote."
                    ),
                    description="1C 500mm2 Al 110C black, 1 x 500m",
                    core_count=1, core_type="C", cross_section_mm2=500, conductor="Al",
                    quantity=500, number_of_lengths=1, length_each=500, unit="M",
                    customer_note="6 drums: 4 weeks ex Melbourne; remaining 42: 9 weeks from order",
                    confidence=0.9,
                )],
                # The product code is the customer's own, quoted on the row; the resolver
                # still decides, so the proof pins whatever it decides from the fields.
                "expect": [None],
                "cite": "customer_note lead time, from Firmus_Q-134882_Individual_Drums.xlsx",
            },
            {
                # The earth length is the length of ONE run, so it reveals the cut count
                # the mains row never states. Derived, not stated, so it carries its own
                # note asking for confirmation.
                "text": "140m of 4C 185mm2 Cu XLPE mains and 35m of 1C 95mm2 earth",
                "entries": [
                    _full_entry(
                        reasoning=(
                            "Two cables. The earth's 35m is one run of the route, and the mains "
                            "total divides by it exactly (140/35 = 4), so the mains is 4 cuts of "
                            "35m rather than one 140m length."
                        ),
                        description="4C 185mm2 Cu XLPE mains, 4 cuts of 35m",
                        core_count=4, core_type="C", cross_section_mm2=185, conductor="Cu",
                        cable_type_hint="Power",
                        quantity=140, number_of_lengths=4, length_each=35, unit="M",
                        confidence=0.8,
                        notes="Mains cut count derived by dividing the 140m mains total by the 35m earth run length; confirm.",
                    ),
                    _full_entry(
                        reasoning="The separate 1C 95mm2 earth runs the route once: 1 x 35m.",
                        description="1C 95mm2 Cu earth, 1 x 35m",
                        core_count=1, core_type="C", cross_section_mm2=95, conductor="Cu",
                        is_earth_only=True, has_earth_core=True,
                        quantity=35, number_of_lengths=1, length_each=35, unit="M",
                        confidence=0.85,
                    ),
                ],
                "expect": [("matched", "XL-PAXN/4C185OR"), ("matched", "LP-XXXN/1C95EA")],
                "cite": "mains cut count from the earth run length; resolution verified 2026-09-10",
            },
            {
                "text": "Payment terms: 30 days from date of invoice.",
                "entries": [],
                "expect": [],
                "cite": "boilerplate rule in FIELD_SEMANTICS",
            },
            {
                "text": "Delivery: 6-8 weeks ex works.",
                "entries": [],
                "expect": [],
                "cite": "boilerplate rule in FIELD_SEMANTICS",
            },
        ],
    },
]


def _render_examples() -> str:
    parts = []
    for number, group in enumerate(_FEW_SHOT_GROUPS, start=1):
        inputs = []
        outputs = []
        for index, row in enumerate(group["rows"]):
            section = row.get("current_section")
            inputs.append(
                {
                    "index": index,
                    "text": row["text"],
                    "current_section": section,
                    "context": [section] if section else [],
                }
            )
            outputs.append({"index": index, "entries": row["entries"]})
        parts.append(
            "Worked example %d -- %s (input, then output):\n%s\n%s"
            % (
                number,
                group["title"],
                json.dumps({"rows": inputs}, ensure_ascii=True),
                json.dumps({"rows": outputs}, ensure_ascii=True),
            )
        )
    return "\n\n".join(parts)


_SYSTEM_PROMPT = (
    "You read messy customer cable-RFQ rows and extract structured cable "
    "requirements for deterministic catalog matching, zero or more entries per "
    "row. " + FIELD_SEMANTICS + " If a row describes several cable types, "
    "split it into separate entries. Map every output entry back to the index "
    "of the input row it came from.\n\nThe worked examples below are grouped by "
    "scenario; each shows one complete batch call (input rows, then the exact "
    "output).\n\n" + _render_examples()
)


def _request_schema() -> dict[str, Any]:
    return {
        "type": "object",
        "additionalProperties": False,
        "properties": {
            "rows": {
                "type": "array",
                "items": {
                    "type": "object",
                    "additionalProperties": False,
                    "properties": {
                        "index": {"type": "integer"},
                        "entries": {"type": "array", "items": _entry_schema()},
                    },
                    "required": ["index", "entries"],
                },
            }
        },
        "required": ["rows"],
    }


def _system_message() -> dict[str, Any]:
    """The system message, with prompt caching enabled where the model supports it.

    Anthropic models cache via an explicit cache_control marker with a
    1,024-token floor -- the worked-example library puts this prompt well above
    that, so on an anthropic/* model every batch after the first (within the
    ~5-minute TTL) reads the system prompt at ~10% of the base input price.
    Gemini models ignore the marker path entirely (their caching is implicit
    where available, and their explicit-cache floor of 32,768 tokens is out of
    reach), so they keep the plain string form OpenRouter expects.
    """
    if normalizer_model().startswith("anthropic/"):
        return {
            "role": "system",
            "content": [
                {"type": "text", "text": _SYSTEM_PROMPT, "cache_control": {"type": "ephemeral"}}
            ],
        }
    return {"role": "system", "content": _SYSTEM_PROMPT}


def batch_rows(batch: list[tuple[int, dict]]) -> list[dict[str, Any]]:
    """The row envelope for a normalization call, shared with the reader translator.

    The translator (`reader_normalizer`) sends rows to a different wire but must send them
    in the exact shape this returns: its contract with the resolver is the one that matters,
    and a second serialization drift between the two would read as a model difference when
    it is really a prompt difference. One owner, extracted by index.
    """
    rows = []
    for index, item in batch:
        context = item.get("context") or []
        rows.append(
            {
                "index": index,
                "text": str(item.get("text") or ""),
                "current_section": context[-1] if context else None,
                "context": context,
            }
        )
    return rows


def _build_payload(batch: list[tuple[int, dict]]) -> dict[str, Any]:
    rows = batch_rows(batch)
    payload = {
        "model": normalizer_model(),
        "messages": [
            _system_message(),
            {"role": "user", "content": json.dumps({"rows": rows}, ensure_ascii=True)},
        ],
        "max_tokens": normalizer_max_tokens(),
        "response_format": {
            "type": "json_schema",
            "json_schema": {"name": "rfq_normalize", "strict": True, "schema": _request_schema()},
        },
    }
    # Not setting "temperature": newer Claude models (e.g. claude-sonnet-5) don't list
    # it in supported_parameters; with require_parameters below, sending it makes
    # OpenRouter 404 "no endpoints found" on every call instead of just dropping it.
    if os.environ.get("OPENROUTER_REQUIRE_PARAMETERS", "true").lower() in {"1", "true", "yes"}:
        payload["provider"] = {"require_parameters": True}
    return payload


def _row_from_payload(row: dict, model: str) -> NormalizedRow | None:
    entries = [_entry_from_payload(item) for item in row.get("entries") or []]
    entries = [entry for entry in entries if entry.description.strip()]
    if not entries:
        return None
    confidence = min((entry.confidence for entry in entries), default=0.0)
    assumptions = [entry.notes for entry in entries if entry.notes]
    needs_review = bool(assumptions) or confidence < _float_env("OPENROUTER_ASSISTED_THRESHOLD", 0.75)
    return NormalizedRow(
        entries=entries,
        model=model,
        confidence=confidence,
        assumptions=assumptions,
        needs_review=needs_review,
    )


def _run_batch(
    batch: list[tuple[int, dict]], *, model: str, timeout: float, budget: dict | None
) -> dict[int, NormalizedRow]:
    """Run one OpenRouter call for ``batch`` and parse its response.

    If the response was truncated (hit max_tokens mid-JSON) or otherwise failed
    to parse, and the batch has more than one row, split it in half and retry
    each half. This self-corrects for unusually verbose rows regardless of the
    configured chunk size -- a fixed max_tokens/chunk_size pairing that works
    for most files can still overflow on a file with longer descriptions, and
    silently dropping the *entire* batch to regex (as happened before this)
    means a whole sheet can come back with nothing AI-normalized at all.
    """
    if budget is not None:
        if budget["used"] >= budget["limit"]:
            return {}
        budget["used"] += 1

    data, error = _openrouter_chat(_build_payload(batch), timeout=timeout)
    truncated = bool(data) and data["choices"][0].get("finish_reason") == "length"
    parsed = None
    if data and not truncated:
        try:
            content = data["choices"][0]["message"]["content"]
            parsed = _loads_json_object(content)
        except (KeyError, TypeError, ValueError, json.JSONDecodeError):
            parsed = None

    if error or not data or truncated or parsed is None:
        if len(batch) > 1:
            mid = len(batch) // 2
            normalized = _run_batch(batch[:mid], model=model, timeout=timeout, budget=budget)
            normalized.update(_run_batch(batch[mid:], model=model, timeout=timeout, budget=budget))
            return normalized
        return {}

    response_model = data.get("model") or model
    valid_indexes = {index for index, _ in batch}
    normalized: dict[int, NormalizedRow] = {}
    for row in parsed.get("rows") or []:
        try:
            index = int(row.get("index"))
        except (TypeError, ValueError):
            continue
        if index not in valid_indexes:
            continue
        normalized_row = _row_from_payload(row, response_model)
        if normalized_row is not None:
            normalized[index] = normalized_row
    return normalized


def normalize_items(
    items: list[dict], *, budget: dict | None = None, skip_indexes: set[int] | None = None
) -> dict[int, NormalizedRow]:
    """Batch-normalize extracted rows.

    ``items`` are work items shaped like ``{text, context, metadata}``. Returns a
    mapping of original row index -> :class:`NormalizedRow` for every row the model
    successfully translated. Rows that are blank, dropped by the model, or affected
    by a failed/empty AI response (even after the split-retry in :func:`_run_batch`)
    are simply absent from the result, signalling the caller to fall back to the
    original extracted text for those rows. ``skip_indexes`` (from the pipeline's
    exact-product-code pre-filter) are excluded from every batch entirely, not just
    left unnormalized -- a row that already names an explicit, known product code
    should never be re-derived by the AI at all.
    """
    if not normalizer_enabled():
        return {}

    skip_indexes = skip_indexes or set()
    indexed = [
        (index, item)
        for index, item in enumerate(items)
        if index not in skip_indexes and str(item.get("text") or "").strip()
    ]
    if not indexed:
        return {}

    model = normalizer_model()
    timeout = _float_env("RFQ_NORMALIZER_TIMEOUT_SECONDS", 30)
    chunk_size = normalizer_max_items()
    normalized: dict[int, NormalizedRow] = {}

    for start in range(0, len(indexed), chunk_size):
        if budget is not None and budget["used"] >= budget["limit"]:
            break
        batch = indexed[start : start + chunk_size]
        normalized.update(_run_batch(batch, model=model, timeout=timeout, budget=budget))

    return normalized
