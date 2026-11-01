# Generated from the RFQ parser's tests/test_resolver.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
import unittest

from rfq_parser.pipeline import catalog
from tricab_cable_rules.resolver import match_line, _validated_style_code


class HouseDefaultStyleTests(unittest.TestCase):
    """Confirmed house defaults for under-specified LV power cables (2026-06-18):
    PVC unless fire/LSZH; XL(90C) default, KL(110C) when hot; CC standard LSZH;
    never auto-pick a special-application style unless its feature is requested."""

    def _style(self, text):
        return match_line(text, catalog)["fields"]["style"]

    def test_plain_power_defaults_to_xl(self):
        self.assertEqual(self._style("4C+E 120mm Cu"), "XL")

    def test_high_temp_defaults_to_kl(self):
        self.assertEqual(self._style("4C+E 120mm Cu 110C"), "KL")

    def test_x_hf_110_switchboard_defaults_to_ls_orange(self):
        result = match_line(
            "1 x 200m roll of 1mm2 LSFLEX125 switchboard X-HF - 110 0.6/1KV - Orange",
            catalog,
        )

        self.assertEqual(result["fields"]["style"], "LS")
        self.assertEqual(result["fields"]["config_core"], "XX")
        self.assertEqual(result["fields"]["colour"], "OR")
        # 1mm2 <=6mm2 LS single core is tinned copper (conductor 'D'); see
        # TinnedSmallCoreTests.
        self.assertEqual(result["selected_product_code"], "LS-XXXD/1C1OR")

    def test_10mm_switchboard_request_defaults_to_ls(self):
        result = match_line("1C 10mm2 Cu switchboard wire orange", catalog)
        self.assertEqual(result["selected_product_code"], "LS-XXXN/1C10OR")

    def test_high_temp_deg_without_trailing_c_defaults_to_kl(self):
        # "110 deg" (no trailing C) must still register as high-temp -> KL, not XL.
        self.assertEqual(self._style("4C+E 120mm Cu 110 deg"), "KL")

    def test_lszh_defaults_to_cc(self):
        self.assertEqual(self._style("4C+E 120mm Cu LSZH"), "CC")

    def test_fire_defaults_to_fr(self):
        self.assertEqual(self._style("4C+E 120mm Cu fire rated"), "FR")

    def test_fire_rated_beats_a_stated_pvc_sheath(self):
        # Draft 8d7f76e8 (12 October 2026): "XLPE/PVC fire rated" gated FR out on the PVC
        # sheath, and the email's "submains cable" heading then matched LP's earthing text,
        # so seven mains rows were quoted as LP-XXXN/...EA earth wire.
        for text in (
            "1c 240mm2 Cu XLPE/PVC fire rated",
            "1c 240mm2 Cu XLPE/PVC fire rated submains cable",
            "1c 185mm2 Cu XLPE/PVC fire rated submains cable",
            "1c 95mm2 Cu XLPE/PVC fire rated submains cable",
        ):
            with self.subTest(text=text):
                result = match_line(text, catalog)
                self.assertEqual(result["fields"]["style"], "FR")
                self.assertFalse(result["selected_product_code"].endswith("EA"))

    def test_pvc_without_fire_still_gates_out_lszh(self):
        self.assertEqual(self._style("1c 240mm2 Cu XLPE/PVC submains cable"), "XL")

    def test_requested_special_application_overrides_default(self):
        # Submersible/termite explicitly requested -> do NOT impose the XL default.
        self.assertNotEqual(self._style("4C 70mm Cu submersible pump"), "XL")
        self.assertNotEqual(self._style("4C+E 120mm Cu termite"), "XL")

    def test_full_default_chain_resolves_to_single_pushable_product(self):
        # Style (XL) + colour (style standard) + class code (PA) all default, so an
        # under-specified line collapses to one product and becomes pushable (matched).
        result = match_line("4C+E 120mm Cu", catalog)
        self.assertEqual(result["status"], "matched")
        self.assertEqual(result["selected_product_code"], "XL-PAXN/5C120OR")
        self.assertLessEqual(result["confidence"], 0.8)
        self.assertTrue(any("defaulted to XL" in w for w in result["warnings"]))


class PvcDoesNotOverrideLszhTests(unittest.TestCase):
    """Consultant descriptions read "XLPE/PVC ... HF110 LSZH": the PVC is the
    conductor insulation, LSZH is the deliberate outer sheath. A co-stated PVC
    must NOT gate out or de-rank the LSZH family, or a termite/nylon LSZH cable
    loses its CN style and falls through to the generic LS/LP earth style."""

    def _style(self, text):
        return match_line(text, catalog)["fields"]["style"]

    def test_termite_nylon_lszh_with_pvc_stays_cn(self):
        self.assertEqual(
            self._style("4C 25mm Cu X-HF-110 + E LSZH termite nylon XLPE/PVC"), "CN"
        )

    def test_termite_nylon_lszh_with_pvc_no_earth_stays_cn(self):
        self.assertEqual(self._style("4C 25mm Cu X-HF-110 LSZH termite nylon XLPE/PVC"), "CN")

    def test_termite_nylon_pvc_without_lszh_still_uses_pvc_ladder(self):
        # No LSZH stated -> PVC is the real sheath signal and the 90C nylon XN wins.
        self.assertEqual(self._style("4C 25mm Cu X-90 termite nylon XLPE/PVC"), "XN")

    def test_pvc_lszh_multicore_resolves_instead_of_unresolved(self):
        # "4C+E PVC/PVC ... LSZH" (orange circular) must resolve (CC), not be gated
        # into nothing by the PVC-vs-LSZH construction conflict.
        result = match_line("10mm 4C+E PVC/PVC CIRCULAR LSZH ORANGE", catalog)
        self.assertEqual(result["status"], "matched")
        self.assertEqual(result["fields"]["style"], "CC")


class FireRatedTermiteJacketTests(unittest.TestCase):
    """A fire-rated cable that ALSO needs termite/nylon resistance takes a custom
    termite jacket -- the "T" sheath suffix on the fire style: FR -> FRT. There is
    no stocked FRT product, so it is a deliberately generated made-to-order code."""

    def test_fire_plus_termite_gets_frt_jacket(self):
        result = match_line("1C 95mm Cu X-125 (FIRE RATED) LSZH Termite Nylon", catalog)
        self.assertEqual(result["fields"]["style"], "FR")
        self.assertEqual(result["fields"]["sheath"], "T")
        self.assertEqual(result["selected_product_code"], "FRT-PAXN/1C95RE")

    def test_fire_without_termite_stays_fr(self):
        result = match_line("1C 95mm Cu X-125 (FIRE RATED) LSZH", catalog)
        self.assertEqual(result["fields"]["style"], "FR")
        self.assertNotEqual(result["fields"].get("sheath"), "T")
        self.assertEqual(result["selected_product_code"], "FR-PAXN/1C95RE")


class NylonJacketTemperatureLadderTests(unittest.TestCase):
    """`/NJ` + a 110C designation must land on the 110C nylon series.

    Reported by Neil O'Loughlin 2026-08-14 against draft 727283: line 6 of his schedule
    resolved to XL-PAXN/1C500OR, identical to the XLPE-90 line above it. Two independent
    signals were being dropped -- the `110` (no degree marker, so HIGH_TEMP_RE missed it)
    and `NJ` (the nylon keyword list only had TERMITE/RODENT/NYLON).
    """

    def test_nj_110_resolves_to_the_kn_series(self):
        result = match_line("500mm2 1C Cu Flexible SDI PVC/XLPE-110/NJ", catalog)
        self.assertEqual(result["selected_product_code"], "KN-PAXN/1C500OR")

    def test_the_90_degree_sibling_stays_on_xl(self):
        """The XLPE-90 lines on the same schedule were already correct and must not move."""
        result = match_line("500mm2 1C Cu Flexible SDI PVC/XLPE-90", catalog)
        self.assertEqual(result["selected_product_code"], "XL-PAXN/1C500OR")

    def test_nylon_without_a_temperature_stays_on_the_90_degree_series(self):
        result = match_line("500mm2 1C Cu Flexible SDI PVC/XLPE-90/NJ", catalog)
        self.assertEqual(result["fields"]["style"], "XN")


class ConfigCodeDefaultTests(unittest.TestCase):
    """House rule (confirmed with Technical, 2026-06-24): power defaults to the PA
    config on the standard temperature style; a control request rides the SAME standard
    styles in the CA config — XL-CA (90C) by default, KL-CA (110C) when hot — not the
    flexible/rubber C-series (which is the fallback only where XL/KL stock no product)."""

    def test_plain_power_uses_pa_config(self):
        result = match_line("4C 6mm cable", catalog)
        self.assertEqual(result["status"], "matched")
        self.assertEqual(result["selected_product"]["config_core"], "PA")
        self.assertTrue(result["selected_product_code"].startswith("XL-PA"))

    def test_multicore_control_defaults_to_xl_ca(self):
        # Multi-core control with no temperature -> XL-CA (90C), not the C-series.
        result = match_line("7C 2.5mm control cable", catalog)
        self.assertEqual(result["status"], "matched")
        self.assertEqual(result["selected_product"]["config_core"], "CA")
        self.assertTrue(result["selected_product_code"].startswith("XL-CA"))

    def test_multicore_control_110c_uses_kl_ca(self):
        result = match_line("7C 2.5mm 110C control cable", catalog)
        self.assertEqual(result["selected_product"]["config_core"], "CA")
        self.assertTrue(result["selected_product_code"].startswith("KL-CA"))


class FireRatedEarthTests(unittest.TestCase):
    def test_small_standalone_earth_defaults_to_ls(self):
        result = match_line("1C 10mm2 Cu earth", catalog)

        self.assertEqual(result["fields"]["style"], "LS")
        self.assertEqual(result["selected_product_code"], "LS-XXXN/1C10EA")

    def test_ordinary_earth_cable_uses_lp_series(self):
        # Above the 10mm2 small-conductor range, an ordinary earth remains LP.
        result = match_line("XLPE CU EARTH 1C 25mm2", catalog)

        self.assertEqual(result["fields"]["style"], "LP")
        self.assertTrue(result["selected_product_code"].startswith("LP-"))
        self.assertTrue(result["selected_product_code"].endswith("EA"))

    def test_aluminium_earth_stays_earth_colour(self):
        result = match_line("1C 16mm aluminium earth", catalog)

        self.assertEqual(result["fields"]["style"], "LP")
        self.assertEqual(result["fields"]["conductor"], "A")
        self.assertEqual(result["fields"]["colour"], "EA")
        self.assertEqual(result["selected_product_code"], "LP-XXXA/1C16EA")

    def test_fire_rated_context_forces_earth_cable_to_ls_series(self):
        result = match_line(
            "XLPE CU EARTH 1C 25mm2",
            catalog,
            context=["Copper Fire rated 110° 1 core", "Copper XLPE 1 core EARTH"],
        )

        self.assertEqual(result["fields"]["style"], "LS")
        self.assertTrue(result["selected_product_code"].startswith("LS-"))
        self.assertFalse(any(candidate["style"] == "LP" for candidate in result["candidates"]))

    def test_explicit_fr_110_earth_cable_uses_ls_series(self):
        result = match_line("FR 110° CU EARTH 1C 25mm2", catalog)

        self.assertEqual(result["fields"]["style"], "LS")
        self.assertTrue(result["selected_product_code"].startswith("LS-"))


class CommsAndArmourTests(unittest.TestCase):
    def test_single_mode_fibre_flagged_not_available(self):
        result = match_line("12 core single mode Comms Cable 100m", catalog)
        self.assertEqual(result["item_type"], "comms")
        self.assertEqual(result["status"], "not_available")
        self.assertEqual(result["selected_product_code"], "")

    def test_cat6_data_flagged_not_available(self):
        result = match_line("4 pair cat 6 outdoor rated 1 drum", catalog)
        self.assertEqual(result["item_type"], "comms")
        self.assertEqual(result["status"], "not_available")

    def test_tps_flat_is_excluded_rather_than_matched_to_fd(self):
        # "TPS flat" used to resolve confidently to an FD flat code that Sales deleted by
        # hand on every quote. TriCab does not sell TPS.
        for text in ("2.5mm TPS flat 100m", "2C+E 2.5mm2 tps flat 200m", "2.5mm T.P.S. 100m"):
            result = match_line(text, catalog)
            self.assertEqual(result["status"], "not_available", text)
            self.assertEqual(result["item_type"], "not_sold", text)
            self.assertEqual(result["selected_product_code"], "", text)
            self.assertEqual(result["disposition"], "excluded", text)
            self.assertEqual(result["exclusion_reason"], "TriCab does not sell TPS cable", text)

    def test_tps_rule_leaves_other_lines_alone(self):
        # A real TriCab code on the line is still quoted as written; "flat" without TPS
        # is still FD; TPS inside another word is not TPS.
        self.assertEqual(
            match_line("FD-PAXN/3C2.5OR 100m TPS alternative", catalog)["selected_product_code"],
            "FD-PAXN/3C2.5OR",
        )
        self.assertTrue(
            match_line("2.5mm flat 2C+E 100m", catalog)["selected_product_code"].startswith("FD-")
        )
        self.assertNotIn("disposition", match_line("Steps 4C 16mm XLPE 100m", catalog))

    def test_tps_row_is_excluded_from_the_quote_and_held_from_auto_push(self):
        from rfq_parser.quote_readiness import quote_readiness

        tps = match_line("2.5mm TPS flat 100m", catalog)
        cable = match_line("4C 16mm XLPE 100m", catalog)
        readiness = quote_readiness([tps, cable], {})
        self.assertEqual([row["row_number"] for row in readiness["excluded"]], [1])
        self.assertEqual(readiness["row_states"][0]["severity"], "excluded")

    def test_tps_lines_are_dropped_from_the_quote(self):
        # Sales want TPS lines gone, not struck through. Only the TPS half of a compound
        # line goes; the rest of the quote is untouched.
        from unittest.mock import patch
        from rfq_parser import pipeline

        items = [
            {"text": "2.5mm TPS flat 100m", "source": {"line_number": 1}},
            {"text": "2.5mm TPS flat 100m and 4C 16mm XLPE 100m", "source": {"line_number": 2}},
            {"text": "2.5mm flat 2C+E 100m", "source": {"line_number": 3}},
        ]
        with patch.dict("os.environ", {"RFQ_NORMALIZER_ENABLED": "false", "OPENROUTER_API_KEY": ""}):
            results = pipeline.match_items(items)
        codes = [row["selected_product_code"] for row in results]
        self.assertEqual(len(codes), 2)
        self.assertTrue(codes[0].startswith("XL-"))
        self.assertTrue(codes[1].startswith("FD-"))

    def test_unspecified_armour_prefers_unarmoured_power(self):
        # "LV power, orange" with no armour stated must not pick the armoured KA family.
        # PVC stated, no temperature -> house default is XL (90C); KL is the 110C variant.
        result = match_line("10mm2 Cu 3C+E PVC LV power cable 105m all orange", catalog)
        self.assertEqual(result["fields"]["style"], "XL")
        self.assertTrue(result["selected_product_code"].startswith("XL-"))

    def test_explicit_armour_still_selects_armoured(self):
        result = match_line("10mm2 Cu 3C+E SWA LV power cable 105m orange", catalog)
        self.assertTrue(catalog.styles[result["fields"]["style"]].has_armour)


class HighTempEarthTests(unittest.TestCase):
    """Small standalone earth defaults to LS; paired ordinary mains and standalone
    earth above 10mm2 use LP. Temperature/LSZH/switchboard/fire still force LS, and
    earth identity (EA colour, XX config) never falls through to a power style."""

    def test_high_temp_request_on_earth_line_uses_ls(self):
        result = match_line("1C 25mm Cu high temp EARTH", catalog)
        self.assertEqual(result["fields"]["style"], "LS")

    def test_high_temp_insulation_code_on_earth_uses_ls(self):
        result = match_line("1C 25mm Cu X-HF-110 EARTH", catalog)
        self.assertEqual(result["fields"]["style"], "LS")

    def test_inherited_high_temp_context_uses_ls(self):
        # An explicit high-temperature section/request context applies to the earth itself.
        result = match_line("1C 25mm Cu EARTH", catalog, context=["HIGH TEMP"])
        self.assertEqual(result["fields"]["style"], "LS")

    def test_ordinary_90c_earth_uses_lp(self):
        # 90C is not "hot" (>90) and not LSZH -> the ordinary earth stays LP.
        result = match_line("1C 25mm Cu XLPE90 EARTH", catalog)
        self.assertEqual(result["fields"]["style"], "LP")


class SolarSelectionTests(unittest.TestCase):
    def test_generic_aluminium_cable_does_not_select_solar_series(self):
        result = match_line("Aluminium cable rated 90 degrees 1C 25mm2", catalog)

        self.assertNotEqual(result["fields"]["cable_type"], "Solar")
        self.assertFalse(any(candidate["style"] in {"SJ", "SK", "SX", "SY"} for candidate in result["candidates"]))
        self.assertFalse(result["selected_product_code"].startswith(("SJ-", "SK-", "SX-", "SY-")))

    def test_explicit_solar_cable_can_select_solar_series(self):
        result = match_line("SOLAR AL 1C 25mm2", catalog)

        self.assertEqual(result["fields"]["cable_type"], "Solar")
        self.assertTrue(result["selected_product_code"].startswith(("SJ-", "SK-", "SX-", "SY-")))

    def test_solar_context_can_select_solar_series(self):
        result = match_line("AL 1C 25mm2", catalog, context=["Solar cables"])

        self.assertEqual(result["fields"]["cable_type"], "Solar")
        self.assertTrue(result["selected_product_code"].startswith(("SJ-", "SK-", "SX-", "SY-")))


class LengthDefaultTests(unittest.TestCase):
    def test_cable_without_cuts_defaults_to_one(self):
        result = match_line("XLPE CU 1C 25mm2", catalog)

        self.assertEqual(result["length"]["number_of_lengths"], 1)

    def test_explicit_length_defaults_to_one_cut_and_matching_total(self):
        result = match_line("XLPE CU 1C 25mm2 30m", catalog)

        self.assertEqual(result["length"]["length_each_m"], 30)
        self.assertEqual(result["length"]["number_of_lengths"], 1)
        self.assertEqual(result["length"]["total_length_m"], 30)


class ConstructionFamilyConsistencyTests(unittest.TestCase):
    def test_flat_request_selects_fd_style(self):
        result = match_line("2C 2.5mm flat cable 100m", catalog)

        self.assertEqual(result["fields"]["style_hint"], "FD")
        self.assertEqual(result["fields"]["style"], "FD")
        self.assertEqual(result["fields"]["conductor"], "N")
        self.assertEqual(result["selected_product_code"], "FD-PAXN/2C2.5OR")
        self.assertFalse(any(candidate["style"] != "FD" for candidate in result["candidates"]))

    def test_lszh_flexible_class_5_termite_nylon_selects_cdt(self):
        result = match_line("XLPE/PVC HF110 LSZH FLEXIBLE CLASS 5 TERMITE NYLON 1C 185mm", catalog)

        self.assertEqual(result["fields"]["style_hint"], "CD")
        self.assertEqual(result["fields"]["style"], "CD")
        self.assertEqual(result["fields"]["sheath"], "T")
        self.assertEqual(result["fields"]["conductor"], "N")
        self.assertEqual(result["selected_product_code"], "CDT-PAXN/1C185OR")


class NylonTermiteFamilyTests(unittest.TestCase):
    """Nylon / termite-resistant family (confirmed with TriCab 2026-07-07): a plain
    termite/nylon request selects the standard extruded-nylon styles XN/KN/CN (nylon
    is part of the style code itself -- no "T" suffix) by the same temperature ladder
    as power: no temp/90C -> XN, 110C -> KN, >110C/LSZH -> CN. The "T" jacket suffix
    (XDT/KDT/CDT) belongs to the separate SDI family and is not what a plain nylon
    line resolves to."""

    def test_no_temp_termite_defaults_to_xn(self):
        r = match_line("1c XLPE/PVC 95mm Nylon Jacket sac sheath. Defence grade", catalog)
        self.assertEqual(r["status"], "matched")
        self.assertEqual(r["fields"]["style"], "XN")
        self.assertEqual(r["selected_product_code"], "XN-PAXN/1C95OR")

    def test_high_temp_termite_selects_kn(self):
        r = match_line("4C+E 16mm Cu termite 110C", catalog)
        self.assertEqual(r["fields"]["style"], "KN")
        self.assertTrue(r["selected_product_code"].startswith("KN-"))

    def test_lszh_termite_selects_cn(self):
        r = match_line("4C 6mm Cu nylon LSZH", catalog)
        self.assertEqual(r["fields"]["style"], "CN")
        self.assertTrue(r["selected_product_code"].startswith("CN-"))

    def test_rodent_is_treated_as_termite(self):
        r = match_line("1C 95mm Cu rodent resistant", catalog)
        self.assertEqual(r["fields"]["style"], "XN")

    def test_termite_does_not_select_sdi_suffix_style(self):
        # A plain nylon line must not resolve to the SDI XDT/KDT/CDT variants.
        r = match_line("1c XLPE/PVC 95mm Nylon Jacket", catalog)
        self.assertNotIn(r["fields"]["style"], {"XD", "KD", "CD", "LN"})
        self.assertNotIn("T", r["selected_product_code"].split("-")[0][2:])

    def test_plain_power_without_termite_is_unaffected(self):
        r = match_line("4C+E 120mm Cu", catalog)
        self.assertEqual(r["fields"]["style"], "XL")

    def test_swa_pvc_black_smallcore_defaults_to_armoured_power(self):
        # Confirmed rule (2026-06-29): default to POWER unless control is mentioned. A black
        # SWA PVC cable with no control signal is armoured power -> XA (90C, no temp), PA
        # config, black sheath. The power styles serve both power and control (PA vs CA
        # config); a bare black colour must not pull the line into the control/VSD series.
        result = match_line("4C 2.5MM2 (SWA) PVC SHEATHED (BK)-SWA-PVC INSULATED", catalog)

        self.assertEqual(result["fields"]["style"], "XA")
        self.assertEqual(result["fields"]["armour"], "S")
        self.assertEqual(result["fields"]["config_core"], "PA")
        self.assertEqual(result["selected_product_code"], "XA-PASN/4C2.5BK")

    def test_single_core_armour_uses_awa_and_defaults_to_xa(self):
        # Confirmed rule: single core -> AWA (armour code J); PVC + armour, no temp -> XA
        # (90C armoured), not KA (110C). KA only when 110C is stated.
        result = match_line("1C 25mm2 SWA PVC copper", catalog)

        self.assertNotIn("style_hint", result["fields"])
        self.assertEqual(result["fields"]["style"], "XA")
        self.assertEqual(result["fields"]["armour_type"], "SWA")
        self.assertEqual(result["fields"]["armour"], "J")
        self.assertEqual(result["fields"]["canonical_code"], "XA-PAJN/1C25OR")
        self.assertEqual(result["selected_product_code"], "XA-PAJN/1C25OR")

    def test_armoured_high_temp_selects_ka(self):
        # Confirmed ladder: armour + 110C/high-temp -> KA (110C armoured).
        result = match_line("4C+E 35mm2 Cu high temp SWA", catalog)

        self.assertEqual(result["selected_product"]["style"], "KA")
        self.assertEqual(result["status"], "matched")

    def test_armoured_lszh_selects_ca(self):
        # Confirmed ladder: armour + LSZH (>110C) -> CA (LSZH armoured).
        result = match_line("4C+E 35mm2 Cu XLPE LSZH SWA", catalog)

        self.assertEqual(result["selected_product"]["style"], "CA")
        self.assertEqual(result["status"], "matched")

    def test_similar_black_control_rows_keep_the_same_inferred_style(self):
        first = match_line("Control cables all black sheath 0.75mm2 Cu 4C+E PVC 260m", catalog)
        second = match_line("Control cables all black sheath 1.5mm2 Cu 12C+E PVC 60m", catalog)

        self.assertEqual(first["fields"]["style"], second["fields"]["style"])
        self.assertTrue(all(candidate["style"] == first["fields"]["style"] for candidate in first["candidates"]))
        self.assertTrue(all(candidate["style"] == second["fields"]["style"] for candidate in second["candidates"]))

    def test_explicit_black_sheath_never_returns_orange_candidate(self):
        # When a black product DOES exist for the spec, never pollute with orange.
        result = match_line("Control cables all black sheath 1.5mm2 Cu 4C+E PVC 175m", catalog)

        self.assertFalse(any(candidate["colour"] != "BK" for candidate in result["candidates"]))
        self.assertFalse(result["selected_product_code"].endswith("OR"))

    def test_colour_fallback_when_no_black_product_exists(self):
        # Small-core standard control can be made in the black XL/CA form even when
        # that exact size is absent from the catalog snapshot; do not fall back to
        # an orange power/flexible-control variant. The stated black is honoured
        # (synthesised) rather than relaxed, so this is a matched made-to-order line
        # flagged for TSD confirmation, not a colour-mismatch clarification.
        result = match_line("Control cables all black sheath 0.75mm2 Cu 4C+E PVC 260m", catalog)

        self.assertEqual(result["selected_product_code"], "XL-CAXN/5C.75BK")
        self.assertEqual(result["status"], "matched")
        self.assertTrue(any("Cost API" in w for w in result["warnings"]))

    def test_explicit_colour_is_honoured_even_when_not_stocked(self):
        # A stated colour is a firm requirement: the code must carry it even when no
        # product is catalogued in that colour -> synthesise the coloured code, don't
        # substitute a stocked colour. (XL power is stocked orange, not grey.)
        result = match_line("4C+E 120mm Cu grey", catalog)

        self.assertTrue(result["selected_product_code"].endswith("GY"))
        self.assertEqual(result["selected_product_code"], "XL-PAXN/5C120GY")
        self.assertNotIn("colour", result["inferred_fields"])  # colour was stated, not inferred
        self.assertTrue(any("requested colour" in w for w in result["warnings"]))

    def test_unstated_colour_still_uses_series_default(self):
        # No colour stated -> series standard (orange for XL power), flagged as inferred.
        result = match_line("4C+E 120mm Cu", catalog)

        self.assertTrue(result["selected_product_code"].endswith("OR"))
        self.assertIn("colour", result["inferred_fields"])

    def test_lv_power_is_not_classified_as_building_wire(self):
        result = match_line("LV power cables all orange circ. 25mm2 Cu 2C+E PVC 80m", catalog)

        self.assertEqual(result["fields"]["cable_type"], "Power")

    def test_multicore_flexible_xlpe_prefers_power_xl_over_control_xn(self):
        # "Multicore flexible" XLPE is a power cable (XL), not the nylon termite-resistant
        # control style (XN). Without the Power hint the two are a near-tie.
        result = match_line("Multicore flexible 0.6/1kV 4C + E 10mm2 Cu X-90 XLPE", catalog)

        self.assertEqual(result["fields"]["cable_type_hint"], "Power")
        top_styles = [entry["style"]["code"] for entry in result["style_candidates"]]
        self.assertEqual(top_styles[0], "XL")
        self.assertTrue(result["selected_product_code"].startswith("XL-"))

    def test_control_multicore_flexible_stays_control(self):
        result = match_line("Control cable multicore flexible 4C 2.5mm2 Cu", catalog)

        self.assertEqual(result["fields"]["cable_type_hint"], "Control")

    def test_core_plus_earth_counts_the_earth_as_a_core(self):
        # TriCab counts the earth as a core: "4C + E" is a 5C cable and resolves to a
        # 5C product code (the earth is part of the one cable, not a separate item).
        result = match_line("Control cables all black sheath 1.5mm2 Cu 4C+E PVC 60m", catalog)

        self.assertEqual(result["fields"]["count"], 5)
        self.assertEqual(result["status"], "matched")
        self.assertIn("/5C", result["selected_product_code"])


class ConstructionGateTests(unittest.TestCase):
    """The construction hard-gates: a wrong-but-catalog-valid style must be
    rejected when it contradicts an explicit RFQ signal (voltage/armour/sheath)."""

    def _styles_of(self, result):
        return [catalog.styles[c["style"]] for c in result["candidates"] if c["style"] in catalog.styles]

    def test_voltage_gate_excludes_other_voltage_classes(self):
        result = match_line("4C 70mm2 11kV copper XLPE", catalog)

        self.assertEqual(result["fields"]["voltage_u"], 11000)
        # No surviving candidate may be a different voltage class (e.g. 600/1000 V).
        self.assertTrue(all(s.voltage_u in (0, 11000) for s in self._styles_of(result)))
        self.assertFalse(result["selected_product_code"].startswith(("CC-", "KK-", "KL-", "CN-")))

    def test_mv_styles_require_mv_signal(self):
        result = match_line("3C 35mm2 SWA copper", catalog)

        self.assertFalse(result["selected_product_code"].startswith(("E", "N", "S", "V")))
        self.assertTrue(all(s.cable_type != "MV Power" for s in self._styles_of(result)))

    def test_s4_selected_when_11kv_is_stated(self):
        result = match_line("3C 35mm2 SWA 11kV copper", catalog)

        self.assertEqual(result["fields"]["voltage_u"], 11000)
        self.assertEqual(result["fields"]["style"], "S4")
        self.assertEqual(result["selected_product_code"], "S4-PASN/3C35OR")

    def test_lszh_gate_excludes_pvc_only_styles(self):
        result = match_line("4C 70mm2 0.6/1kV LSZH copper", catalog)

        self.assertTrue(result["fields"]["lszh"])
        self.assertFalse(any(s.sheath_pvc and not s.sheath_lszh for s in self._styles_of(result)))

    def test_pvc_gate_excludes_lszh_only_styles(self):
        result = match_line("4C 70mm2 0.6/1kV PVC copper", catalog)

        self.assertTrue(result["fields"]["pvc"])
        self.assertFalse(any(s.sheath_lszh and not s.sheath_pvc for s in self._styles_of(result)))

    def test_armour_gate_requires_armoured_styles(self):
        result = match_line("4C 70mm2 SWA 0.6/1kV copper", catalog)

        self.assertTrue(result["fields"]["armoured"])
        self.assertTrue(self._styles_of(result))
        self.assertTrue(all(s.has_armour for s in self._styles_of(result)))

    def test_voltage_gate_tolerates_loose_notation(self):
        # "600V", "0.6kV", "1kV" and "0.6/1kV" all denote the 600/1000 V class and
        # must not be gated out of it (match on either U0 or U).
        for text in ("4C 70mm2 600V copper", "4C 70mm2 0.6kV copper", "4C 70mm2 1kV copper"):
            result = match_line(text, catalog)
            styles = self._styles_of(result)
            self.assertTrue(styles, text)
            self.assertTrue(all(1000 in s.voltages for s in styles), text)

    def test_inferred_fields_reports_defaulted_style(self):
        result = match_line("4 core 70mm2 copper", catalog)

        # Style/colour were not stated, so they must be flagged as inferred.
        self.assertIn("style", result["inferred_fields"])
        self.assertIn("colour", result["inferred_fields"])

    def test_unspecified_sheath_applies_house_default_xl(self):
        # No voltage/sheath/temperature stated: rather than flag a style tie, apply the
        # confirmed house default (PVC 90C standard = XL), capped and flagged as inferred.
        result = match_line("4 core 70mm2 copper", catalog)

        self.assertLessEqual(result["confidence"], 0.8)
        self.assertEqual(result["fields"]["style"], "XL")
        self.assertTrue(any("defaulted to XL" in w for w in result["warnings"]))
        self.assertIn("style", result["inferred_fields"])


class ScreenedVsdFamilyTests(unittest.TestCase):
    """Screened / VSD / VFD family (confirmed with TriCab 2026-06-30): the XU/KU/EU/EA
    family is the symmetric split-earth config (3C+3E) -- XU (90C PVC) -> KU (110C PVC)
    -> EU (LSZH), EA when armoured. Every other config is DP (90C PVC). Fire -> FH."""

    def _style(self, text):
        return match_line(text, catalog)["fields"]["style"]

    def test_single_earth_config_defaults_to_dp(self):
        # 4C+E (single earth) is NOT the 3C+3E family -> DP, canonical PAZN form.
        result = match_line("VSD 3C 4mm + E 0.6/1kV", catalog)
        self.assertEqual(result["fields"]["style"], "DP")
        self.assertTrue(result["selected_product_code"].startswith("DP-PAZN"))

    def test_split_earths_select_xu_as_a_four_core(self):
        # 3C+3E (split earths) -> XU, counted as a single earth-core (4C, not 6C).
        result = match_line("VSD 3C 4mm + 3E 0.6/1kV", catalog)
        self.assertEqual(result["fields"]["style"], "XU")
        self.assertEqual(result["status"], "matched")
        self.assertEqual(result["selected_product_code"], "XU-PAZN/4C4BK")

    def test_split_earths_route_to_vsd_without_explicit_keyword(self):
        # A bare 3C+3E is the symmetric split-earth VSD construction itself: it must
        # reach the XU family even when the row never says "VSD"/"EMC"/"screened".
        result = match_line("3C+3E 4mm", catalog)
        self.assertEqual(result["fields"]["style"], "XU")
        self.assertEqual(result["fields"]["cable_type_hint"], "VSD/VFD")
        self.assertEqual(result["selected_product_code"], "XU-PAZN/4C4BK")

    def test_single_split_earth_without_keyword_stays_plain_power(self):
        # One earth (3C+E) is NOT the split-earth family -> stays on the XL power ladder.
        result = match_line("3C+E 4mm", catalog)
        self.assertEqual(result["fields"]["style"], "XL")
        self.assertNotEqual(result["fields"].get("cable_type_hint"), "VSD/VFD")

    def test_split_earth_high_temp_selects_ku(self):
        result = match_line("VSD 3C 4mm + 3E 110C 0.6/1kV", catalog)
        self.assertEqual(result["fields"]["style"], "KU")
        self.assertTrue(result["selected_product_code"].startswith("KU-PAZN"))

    def test_split_earth_lszh_selects_eu_in_pazn_form(self):
        # EU's PAZN variant is orange, not its default black: the screen code (Z) must
        # win over the colour default, so we get EU-PAZN, not the black EU-PATN.
        result = match_line("LSZH VSD 3C 4mm + 3E 0.6/1kV", catalog)
        self.assertEqual(result["fields"]["style"], "EU")
        self.assertEqual(result["status"], "matched")
        self.assertTrue(result["selected_product_code"].startswith("EU-PAZN"))

    def test_split_earth_armour_selects_ea_in_pasn_form(self):
        result = match_line("VSD 3C 4mm + 3E SWA 0.6/1kV", catalog)
        self.assertEqual(result["fields"]["style"], "EA")
        self.assertEqual(result["status"], "matched")
        self.assertTrue(result["selected_product_code"].startswith("EA-PAS"))

    def test_fire_vsd_selects_fh(self):
        # Fire-rated VSD -> FH (the FR style is for non-VSD fire).
        result = match_line("VSD 3C 4mm + 3E FR 0.6/1kV", catalog)
        self.assertEqual(result["fields"]["style"], "FH")
        self.assertEqual(result["status"], "matched")

    def test_non_3c3e_lszh_vsd_surfaces_constructed_make_code(self):
        # 4C+E LSZH VSD (not the 3C+3E config) -> DP, which is PVC -> LSZH-gated out ->
        # no stocked catalog product (TriCab 2026-06-30 confirmed this config isn't a
        # standard line). The resolver still generates a usable code; the Cost API is
        # the authority for whether that generated code is stocked, make, or unavailable.
        result = match_line("2.5 mm2 Circ, 4C+E VSD LSZH Screened", catalog)
        self.assertEqual(result["status"], "matched")
        self.assertEqual(result["selected_product_code"], "EU-PAZN/5C2.5BK")
        self.assertIsNotNone(result["selected_product"])
        self.assertTrue(any("Cost API" in w for w in result["warnings"]))

    def test_emc_routes_to_vsd_family(self):
        # EMC => VSD/VFD family (confirmed 2026-07-02): every VSD/VFD cable is an EMC
        # cable, so an "EMC" mention (no explicit VSD word) routes into the screened-
        # drive family. An explicit EMC request follows the XU/KU/EU ladder.
        style = self._style("4C+E 120mm Cu EMC")
        self.assertEqual(style, "XU")
        self.assertEqual(catalog.styles[style].cable_type, "VSD/VFD")


class InstrumentationFamilyTests(unittest.TestCase):
    """Instrumentation R-series (confirmed with TriCab 2026-06-30): voltage band first
    (<=110 -> R1, 110<V<=750 -> RE, >750 -> RF; no voltage -> 110V band), then screen
    type / sheath / armour / braid. WH is the only fire instrument style, all bands."""

    def _result(self, text):
        return match_line(text, catalog)

    def test_no_voltage_defaults_to_110v_r1(self):
        r = self._result("Instrument 4 pair 1.5mm")
        self.assertEqual(r["fields"]["style"], "R1")
        self.assertEqual(r["status"], "matched")

    def test_unstocked_instrument_is_synthesised_as_make_to_order(self):
        # 0.5mm2 8-triad ind+overall isn't stocked, but it's a valid make cable: the tool
        # must synthesise the canonical R3 DE-config code (not drop it to "Missing code"),
        # flagged "can be made; confirm with TSD". A triad labelled CONTROL is still
        # instrumentation, so it must not be re-homed to a control style.
        r = self._result("0.5mm2, 8TR, Cu/PVC/I&O Scn/PVC CONTROL CABLE")
        self.assertEqual(r["fields"]["style"], "R3")
        self.assertEqual(r["selected_product_code"], "R3-DEXN/8T.5BK")
        self.assertEqual(r["status"], "matched")
        self.assertTrue(any("can be made" in w for w in r["warnings"]))

    def test_mid_voltage_band_selects_re(self):
        self.assertEqual(self._result("Instrument 4 pair 1.5mm 450/750V")["fields"]["style"], "RE")

    def test_high_voltage_band_selects_rf(self):
        self.assertEqual(self._result("Instrument 4 pair 1.5mm 0.6/1kV")["fields"]["style"], "RF")

    def test_individual_and_overall_screen_selects_rh(self):
        # Coll -> RE, Individual+Coll -> RH at 450/750V.
        self.assertEqual(
            self._result("Instrument 4 pair 1.5mm individual and overall screen 450/750V")["fields"]["style"],
            "RH",
        )

    def test_fire_instrument_selects_wh(self):
        r = self._result("Fire instrument 4 pair 1.5mm 450/750V")
        self.assertEqual(r["fields"]["style"], "WH")
        self.assertEqual(r["status"], "matched")

    def test_fire_armoured_instrument_still_selects_wh(self):
        # "IOAS FR" sets armoured=True, but WH (the sole fire instrument style) is
        # unarmoured -- there is no armoured fire instrument, so fire wins and the
        # armour signal must NOT gate WH out (confirmed with TriCab 2026-06-30:
        # "go with WH because FR is mentioned").
        r = self._result("1.5mm 12pr Ctrl LSZH IOAS FR")
        self.assertEqual(r["fields"]["style"], "WH")
        self.assertEqual(r["status"], "matched")

    def test_wh_code_uses_de_numbering_and_red_jacket(self):
        # WH standard form (confirmed 2026-06-30): instrumentation is numbered per
        # pair/triad (config DE, not per-conductor DA/DB) and the jacket is red (RE).
        # 12P1.5 isn't stocked in the DE/RE form, so the canonical code is synthesised
        # and flagged for TSD confirmation. Expected code: WH-DEXN/12P1.5RE.
        r = self._result("1.5mm 12pr Ctrl LSZH IOAS FR")
        self.assertEqual(r["selected_product_code"], "WH-DEXN/12P1.5RE")
        self.assertTrue(any("Technical (TSD)" in w and "not stocked" in w for w in r["warnings"]))

    def test_wh_code_prefers_stocked_de_re_row_when_available(self):
        # At .75mm the DE/RE row IS catalogued, so the real product is selected with no
        # "not stocked" synthesis flag.
        r = self._result("2pr 0.75mm LSZH fire instrument coll screen")
        self.assertEqual(r["selected_product_code"], "WH-DEXN/2P.75RE")
        self.assertEqual(r["status"], "matched")
        self.assertFalse(any("not stocked" in w for w in r["warnings"]))

    def test_r_series_uses_de_pair_numbering(self):
        # DE pair/triad numbering is the default for ALL instrumentation, not just WH
        # (confirmed 2026-06-30). 4P.5 R1 IS stocked in DE, so a real product is picked.
        r = self._result("4 pair signal 0.5mm 110V")
        self.assertEqual(r["fields"]["style"], "R1")
        self.assertEqual(r["selected_product_code"], "R1-DEXN/4P.5BK")


class ControlSignalFamilyTests(unittest.TestCase):
    """Control / signal routing (confirmed with TriCab 2026-06-30): control rides XL/KL/CC
    in the CA config (black); it can always be made in CA, so a <=4-core control line
    hard-defaults to XL-CA (synthesised when not stocked) rather than the orange PA power
    variant. Plain-core signal/alarm -> WG (the fire-rated signal cable); pairs/triads ->
    instrumentation."""

    def _result(self, text):
        return match_line(text, catalog)

    def test_small_core_control_hard_defaults_to_xl_ca_black(self):
        r = self._result("Control cable 4C 1.5mm Cu PVC")
        self.assertEqual(r["selected_product_code"], "XL-CAXN/4C1.5BK")
        self.assertTrue(any("can be made" in w for w in r["warnings"]))

    def test_large_core_control_uses_stocked_xl_ca(self):
        r = self._result("Control cable 7C 1.5mm Cu PVC")
        self.assertEqual(r["selected_product_code"], "XL-CAXN/7C1.5BK")
        self.assertFalse(any("can be made" in w for w in r["warnings"]))

    def test_flexible_control_stays_c_series(self):
        # An explicit "flexible" control request keeps the flexible C-series, untouched.
        self.assertEqual(self._result("Control 3C 1.5mm flexible")["fields"]["style"], "C1")

    def test_plain_core_signal_routes_to_wg(self):
        r = self._result("Signal cable 4C 1.0mm")
        self.assertEqual(r["fields"]["style"], "WG")
        self.assertEqual(r["selected_product"]["config_core"], "CA")

    def test_plain_core_alarm_routes_to_wg(self):
        self.assertEqual(self._result("Alarm cable 2C 1.5mm")["fields"]["style"], "WG")

    def test_fire_alarm_routes_to_wg_not_fr(self):
        # "fire alarm" must not be grabbed by the generic fire->FR branch; WG is the
        # fire-rated signal cable. The fire flag is still set.
        r = self._result("Fire alarm cable 3C 1.5mm")
        self.assertEqual(r["fields"]["style"], "WG")
        self.assertTrue(r["fields"].get("fire"))

    def test_signal_with_pairs_routes_to_instrumentation(self):
        # Pairs override the Signal hint -> instrumentation, not WG.
        r = self._result("6 pair signal cable 0.5mm 110V")
        self.assertEqual(r["fields"]["style"], "R1")


class ParallelSingleCoreEscalationTests(unittest.TestCase):
    """"Nx SIZE + Mx SIZE earth" parallel single-core notation (confirmed 2026-07-02):
    a regex-only trial pass must never confidently resolve this to one product (it
    silently drops the phase conductors) -- it must escalate to AI so the row can be
    split into two line items. Once field_overrides supplies a split entry's own
    fields, normal resolution proceeds as usual for that entry."""

    def test_trial_pass_escalates_instead_of_matching(self):
        r = match_line("8x 185mm + 1x 185mm earth", catalog)

        self.assertEqual(r["status"], "needs_clarification")
        self.assertLess(r["confidence"], 0.6)
        self.assertIsNone(r["fields"].get("count"))

    def test_field_overrides_bypasses_the_escalation_guard(self):
        # Simulates the AI's phase-conductor split entry for the same row.
        r = match_line(
            "8x 185mm + 1x 185mm earth",
            catalog,
            field_overrides={"count": 1, "core_type": "C", "cross_section": 185.0, "conductor": "A"},
        )

        self.assertEqual(r["status"], "matched")

    def test_field_overrides_bypasses_guard_for_earth_entry_too(self):
        r = match_line(
            "8x 185mm + 1x 185mm earth",
            catalog,
            field_overrides={
                "count": 1, "core_type": "C", "cross_section": 185.0,
                "conductor": "A", "earth": True, "earth_only": True,
            },
        )

        self.assertEqual(r["status"], "matched")
        self.assertTrue(r["fields"].get("earth_only"))


class FlattenedConstructionScheduleResolverTests(unittest.TestCase):
    """Clean OCR-flattened construction-schedule rows should resolve deterministically.

    In this schedule shape, "Trefoil" and "Flat" are formation/configuration values,
    not product-family style hints."""

    def test_trefoil_resolves_to_single_core_with_configuration_run_count(self):
        r = match_line("2 x 300mm2 Cu, X-90 XLPE, 3 x 1 core Trefoil 40m", catalog)

        self.assertEqual(r["status"], "matched")
        self.assertEqual(r["selected_product_code"], "XL-PAXN/1C300OR")
        self.assertEqual(r["fields"]["schedule_formation"], "trefoil")
        self.assertEqual(r["fields"]["count"], 1)
        self.assertEqual(r["fields"]["phase_cores"], 3)
        self.assertEqual(r["fields"]["neutral_parallel_runs"], 2)
        self.assertTrue(r["fields"]["neutral_inferred"])
        self.assertEqual(r["length"]["number_of_lengths"], 8)
        self.assertEqual(r["length"]["total_length_m"], 320)

    def test_schedule_flat_does_not_lock_to_fd_style(self):
        r = match_line("1 x 35mm2 Cu, X-90 XLPE, 4 core Flat 60m CABLE SCHEDULE", catalog)

        self.assertEqual(r["status"], "matched")
        self.assertEqual(r["selected_product_code"], "XL-PAXN/4C35OR")
        self.assertEqual(r["fields"]["schedule_formation"], "flat")
        self.assertNotIn("style_hint", r["fields"])


class ConstructedMakeCodeTests(unittest.TestCase):
    """A recognisable cable with no autocomplete CSV product still gets the engine's
    generated code surfaced as the line's product code. The cost API answers whether
    the code is stocked, make, or unavailable. Only when the engine can't build a code
    at all is the rep pointed to Technical (TSD)."""

    def test_uncatalogued_construction_surfaces_constructed_code(self):
        r = match_line("4C+E 70mm2 Cu fire resistant armoured", catalog)
        self.assertEqual(r["status"], "matched")
        self.assertTrue(r["selected_product_code"])  # non-empty generated code
        self.assertEqual(r["selected_product_code"], r["fields"]["canonical_code"])
        self.assertIsNotNone(r["selected_product"])
        self.assertFalse(r["fields"]["canonical_in_catalog"])
        self.assertTrue(any("Cost API" in w for w in r["warnings"]))
        # The old "no code -> Technical (TSD)" message must not fire when we have a code.
        self.assertFalse(any("Refer to Technical (TSD) for a product code" in w for w in r["warnings"]))

    def test_a_rewritten_code_is_no_longer_reported_as_uncatalogued(self):
        """The tinned rewrite (N->D) picks its target out of the catalog, so the flag
        that says "the resolver invented this code" has to stop saying so -- it is
        what decides whether an unpriced line reads as a phantom or as a real product
        the Cost API happens to be silent about."""
        r = match_line("1C 4mm2 Cu earth", catalog)
        self.assertEqual(r["selected_product_code"], "LS-XXXD/1C4EA")
        self.assertTrue(r["fields"]["canonical_in_catalog"])
        self.assertIn(r["selected_product_code"], catalog.products_by_code)

    def test_no_constructable_code_still_refers_to_technical(self):
        # An out-of-range voltage makes the code engine bail (build_code raises), so
        # there is genuinely no code -- the rep is told to get one from Technical.
        r = match_line("4C+E 70mm2 Cu XLPE PVC SWA armoured 8kV", catalog)
        self.assertEqual(r["status"], "unresolved")
        self.assertEqual(r["selected_product_code"], "")
        self.assertTrue(any("Refer to Technical (TSD) for a product code" in w for w in r["warnings"]))

    def test_catalog_match_is_unaffected(self):
        # A normal stocked match still selects the real priced product, not a bare code.
        r = match_line("4C+E 35mm2 Cu XLPE 0.6/1kV", catalog)
        self.assertEqual(r["status"], "matched")
        self.assertIsNotNone(r["selected_product"])
        self.assertEqual(r["selected_product_code"], r["selected_product"]["product_code"])


class StyleSeriesHintFromLlmTests(unittest.TestCase):
    """A house style/series can be named either inline in the row's own text
    ("KL Series X-HF-110/PVC") -- the pre-existing regex mechanism -- or, as of
    2026-07-02, inferred by the LLM from a section heading with no "Series"
    wording at all (e.g. "Aluminium KL-XH-F 110C AL"). Either source is
    catalog-validated via _validated_style_code before it can lock a style;
    regex wins if both are present."""

    def test_validated_style_code_rejects_bogus_code(self):
        self.assertIsNone(_validated_style_code("ZZ", catalog))

    def test_validated_style_code_accepts_and_normalizes_case(self):
        self.assertEqual(_validated_style_code("kl", catalog), "KL")

    def test_validated_style_code_handles_none_and_empty(self):
        self.assertIsNone(_validated_style_code(None, catalog))
        self.assertIsNone(_validated_style_code("", catalog))

    def test_regex_series_wins_over_llm_supplied_style(self):
        # Row states "XU Series" inline -- that must win over a conflicting
        # LLM-inferred heading style.
        r = match_line(
            "XU Series 4C 6mm cable", catalog, field_overrides={"explicit_style_hint": "KL"}
        )
        self.assertEqual(r["fields"]["style"], "XU")

    def test_llm_style_hint_used_when_regex_finds_none(self):
        # The core new capability: a heading-derived style (no "Series" wording
        # in the row itself) locks the style via field_overrides.
        overrides = {
            "explicit_style_hint": "KL",
            "count": 1, "core_type": "C", "cross_section": 185.0, "conductor": "A",
        }
        r = match_line("8x 185mm + 1x 185mm earth", catalog, field_overrides=overrides)
        self.assertEqual(r["fields"]["style"], "KL")

    def test_bogus_llm_style_hint_falls_through_to_house_default(self):
        r = match_line("4C+E 120mm Cu", catalog, field_overrides={"explicit_style_hint": "ZZ"})
        self.assertEqual(r["fields"]["style"], "XL")

    def test_field_overrides_dict_is_not_mutated(self):
        overrides = {"explicit_style_hint": "KL", "count": 4}
        before = dict(overrides)
        match_line("4C+E 120mm Cu", catalog, field_overrides=overrides)
        self.assertEqual(overrides, before)


class TinnedSmallCoreTests(unittest.TestCase):
    """LS/LP/UP single-core building wire <=6mm2 is tinned copper (conductor 'D'),
    overriding even a customer-supplied plain-'N' code."""

    def test_exact_n_code_is_forced_to_d_twin(self):
        # The customer's own code wins on everything but the conductor: N -> D.
        r = match_line("LS-XXXN/1C.75OR", catalog)
        self.assertEqual(r["selected_product_code"], "LS-XXXD/1C.75OR")
        self.assertIsNotNone(r["selected_product"])  # a real priced product, not made-to-order
        self.assertEqual(r["fields"]["conductor"], "D")
        self.assertIn("conductor", r["inferred_fields"])

    def test_resolved_small_core_defaults_to_tinned(self):
        # Normal resolution path (no explicit code) also lands on the D style.
        r = match_line("1C 0.75mm2 LSZH earth", catalog)
        self.assertTrue(r["selected_product_code"].startswith("LS-XXXD/"))
        self.assertEqual(r["fields"]["conductor"], "D")

    def test_size_above_threshold_stays_plain_copper(self):
        # 10mm2 > 6mm2: plain copper is correct, no rewrite.
        r = match_line("LS-XXXN/1C10OR", catalog)
        self.assertEqual(r["selected_product_code"], "LS-XXXN/1C10OR")

    def test_missing_d_twin_keeps_n_code(self):
        # LP-XXXN/1C4OR has no stocked LP-XXXD/1C4OR twin: keep N rather than
        # force an unpriced made-to-order D (confirmed product decision).
        self.assertNotIn("LP-XXXD/1C4OR", catalog.products_by_code)
        r = match_line("LP-XXXN/1C4OR", catalog)
        self.assertEqual(r["selected_product_code"], "LP-XXXN/1C4OR")

    def test_aluminium_conductor_is_untouched(self):
        r = match_line("LS-XXXA/1C120EA", catalog)
        self.assertEqual(r["selected_product_code"], "LS-XXXA/1C120EA")

    def test_other_styles_are_untouched(self):
        # The rule is scoped to LS/LP/UP; an unrelated small-core 'N' code is left alone.
        r = match_line("XL-CAXN/4C2.5BK", catalog)
        self.assertEqual(r["selected_product_code"], "XL-CAXN/4C2.5BK")


class AluminiumMulticoreP9Tests(unittest.TestCase):
    """All aluminium multicores (3 cores and up) take the full-size-earth P9 config
    instead of the reduced-earth PA (confirmed 2026-08-03)."""

    def test_plain_aluminium_multicore_takes_p9(self):
        r = match_line("4C 25mm2 Aluminium", catalog)
        self.assertEqual(r["selected_product_code"], "XL-P9XA/4C25BK")
        self.assertEqual(r["fields"]["config_core"], "P9")

    def test_aluminium_core_plus_earth_takes_p9_not_pa(self):
        # Rule 3 overrides the "nC+E -> PA" default: an aluminium earth is full size.
        r = match_line("4C+E 25mm2 Aluminium", catalog)
        self.assertEqual(r["selected_product_code"], "XL-P9XA/5C25BK")

    def test_armoured_aluminium_multicore_takes_p9(self):
        r = match_line("4C+E 35mm2 Al SWA", catalog)
        self.assertEqual(r["selected_product_code"], "XA-P9SA/5C35OR")

    def test_missing_p9_twin_keeps_pa_code(self):
        # XA has no aluminium P9 above 70mm2: keep the priced PA product rather than
        # force an unpriced made-to-order P9 (confirmed product decision, 2026-08-03).
        self.assertNotIn("XA-P9SA/5C120BK", catalog.products_by_code)
        r = match_line("4C+E 120mm2 Al SWA", catalog)
        self.assertEqual(r["selected_product_code"], "XA-PASA/5C120BK")

    def test_nylon_family_has_no_p9_and_keeps_pa(self):
        # The whole XN/KN nylon family is PA-only in the catalog.
        r = match_line("3C 25mm2 Al nylon termite", catalog)
        self.assertEqual(r["selected_product_code"], "XN-PAXA/3C25OR")

    def test_two_core_aluminium_is_out_of_scope(self):
        # P9 exists nowhere at 2C -- a 2C is phase+neutral, no earth core to size.
        r = match_line("2C 16mm2 Al", catalog)
        self.assertEqual(r["selected_product_code"], "XL-PAXA/2C16BK")

    def test_single_core_aluminium_is_untouched(self):
        r = match_line("1C 240mm2 Al", catalog)
        self.assertEqual(r["selected_product_code"], "XL-PAXA/1C240BK")

    def test_copper_multicore_keeps_pa(self):
        # Rule 2 ("nC -> P9") was declined: copper is unchanged, with or without earth.
        self.assertEqual(match_line("4C 25mm2 Cu", catalog)["selected_product_code"], "XL-PAXN/4C25OR")
        self.assertEqual(match_line("4C+E 25mm2 Cu", catalog)["selected_product_code"], "XL-PAXN/5C25OR")

    def test_customer_supplied_config_is_not_overridden(self):
        # Unlike the tinned rule, an explicitly-stated config wins over the house rule.
        r = match_line("XL-PAXA/4C25BK", catalog)
        self.assertEqual(r["selected_product_code"], "XL-PAXA/4C25BK")


class RadoxFireRatedTests(unittest.TestCase):
    """"Radox" names the fire-rated range, so it routes by family exactly as an
    explicit "fire rated" does (confirmed 2026-08-03)."""

    def test_radox_single_core_routes_to_fr(self):
        r = match_line("35mm SDI Radox", catalog)
        self.assertEqual(r["selected_product_code"], "FR-PAXN/1C35RE")

    def test_radox_multicore_routes_to_fr(self):
        r = match_line("4C+E 16mm2 Cu Radox 341", catalog)
        self.assertEqual(r["selected_product_code"], "FR-PAXN/5C16RE")

    def test_radox_earth_is_promoted_to_ls(self):
        # A fire earth takes LS, not the standard LP.
        r = match_line("1C 25mm Cu EARTH Radox", catalog)
        self.assertEqual(r["selected_product_code"], "LS-XXXN/1C25EA")

    def test_radox_vsd_routes_to_fh(self):
        r = match_line("VSD 3C 4mm + 3E Radox 0.6/1kV", catalog)
        self.assertEqual(r["selected_product_code"], "FH-PATN/4C4RE")


class BuildingWireStyleTests(unittest.TestCase):
    def test_building_wire_follows_the_size_rule(self):
        # Manusha, 9 October 2026: building wire is LS up to 10 mm2 and LP above, and LS
        # at any size when LSZH is asked for. It used to be LS at every size.
        for text, code in (
            ("2.5mm building wire red", "LS-XXXD/1C2.5RE"),
            ("10mm building wire black", "LS-XXXN/1C10BK"),
            ("16mm2 building wire black", "LP-XXXN/1C16BK"),
            ("50mm2 building wire red", "LP-XXXN/1C50RE"),
            ("1C 16mm2 building wire", "LP-XXXN/1C16EA"),
            ("1C 16mm2 BUILDING-WIRE", "LP-XXXN/1C16EA"),
            ("16mm2 building wire LSZH black", "LS-XXXN/1C16BK"),
            ("1C 16mm2 building wire R-125 black", "LS-XXXN/1C16BK"),
        ):
            with self.subTest(text=text):
                self.assertEqual(match_line(text, catalog)["selected_product_code"], code)

    def test_building_wire_earth_above_10mm2_stays_lp(self):
        for text, code in (
            ("Earth 6MM 7/1.04 CU BUILDING WIRE", "LS-XXXD/1C6EA"),
            ("Earth 16MM 7/1.70 CU BUILDING WIRE", "LP-XXXN/1C16EA"),
            ("Earth 25MM 19/1.35 CU BUILDING WIRE", "LP-XXXN/1C25EA"),
            ("1C 25mm2 building wire earth", "LP-XXXN/1C25EA"),
        ):
            with self.subTest(text=text):
                self.assertEqual(match_line(text, catalog)["selected_product_code"], code)

    def test_section_heading_follows_the_size_rule(self):
        self.assertEqual(match_line("1C 6mm2 red", catalog, context=["BUILDING WIRE"])["fields"]["style"], "LS")
        self.assertEqual(match_line("1C 16mm2 black", catalog, context=["BUILDING WIRE"])["fields"]["style"], "LP")

    def test_b_w_is_building_wire(self):
        # "B/W" is trade shorthand for building wire (9 October 2026). Before it was
        # read, "6mm B/W E" quoted as XL-PAXN/1C6OR: the B/W hid the bare E from the
        # earth reading and named no style. Up to 10 mm2, and on any earth, the shorthand
        # and the words agree; above 10 mm2 a coloured B/W goes to LP (see below).
        for text, code in (
            ("6mm B/W E", "LS-XXXD/1C6EA"),
            ("25mm2 B/W E", "LP-XXXN/1C25EA"),
            ("25mm B/W earth", "LP-XXXN/1C25EA"),
            ("10mm B/W yellow", "LS-XXXN/1C10YE"),
            ("2.5mm B / W red", "LS-XXXD/1C2.5RE"),
        ):
            with self.subTest(text=text):
                self.assertEqual(match_line(text, catalog)["selected_product_code"], code)
                self.assertEqual(
                    match_line(text.replace("B / W", "B/W").replace("B/W", "building wire"),
                               catalog)["selected_product_code"],
                    code, "B/W and building wire disagree")

    def test_b_w_with_no_colour_is_an_earth(self):
        # Sales, 9 October 2026: with no colour named, B/W is an earth -- and takes the
        # building-wire earth rule, LS up to 10 mm2 and LP above.
        for text, code in (
            ("2.5mm2 B/W", "LS-XXXD/1C2.5EA"),
            ("10mm B/W", "LS-XXXN/1C10EA"),
            ("16mm2 B/W", "LP-XXXN/1C16EA"),
            ("1C 16mm2 B/W", "LP-XXXN/1C16EA"),
            ("25mm2 Cu B/W", "LP-XXXN/1C25EA"),
            ("50mm2 Al B/W", "LP-XXXA/1C50EA"),
        ):
            with self.subTest(text=text):
                result = match_line(text, catalog)
                self.assertTrue(result["fields"].get("earth_only"))
                self.assertEqual(result["selected_product_code"], code)

    def test_b_w_with_a_colour_is_that_colour_chosen_by_size(self):
        # Manusha, 9 October 2026: a coloured B/W follows the size rule -- LS up to
        # 10 mm2, LP above -- and LSZH makes it LS at any size.
        for text, code in (
            ("6mm B/W red", "LS-XXXD/1C6RE"),
            ("10mm B/W black", "LS-XXXN/1C10BK"),
            ("16mm2 B/W black", "LP-XXXN/1C16BK"),
            ("50mm2 B/W red", "LP-XXXN/1C50RE"),
            ("70mm2 Al B/W black", "LP-XXXA/1C70BK"),
            ("16mm2 B/W LSZH black", "LS-XXXN/1C16BK"),
            ("50mm2 B/W LSZH red", "LS-XXXN/1C50RE"),
        ):
            with self.subTest(text=text):
                result = match_line(text, catalog)
                self.assertFalse(result["fields"].get("earth_only"))
                self.assertEqual(result["selected_product_code"], code)

    def test_b_w_with_lszh_and_no_colour_is_an_ls_earth(self):
        self.assertEqual(match_line("16mm2 B/W LSZH", catalog)["selected_product_code"], "LS-XXXN/1C16EA")

    def test_b_w_and_building_wire_in_a_colour_agree_at_every_size(self):
        for size in ("2.5", "10", "16", "50"):
            with self.subTest(size=size):
                self.assertEqual(
                    match_line(f"{size}mm2 B/W black", catalog)["selected_product_code"],
                    match_line(f"{size}mm2 building wire black", catalog)["selected_product_code"],
                )

    def test_spelled_out_building_wire_with_no_colour_is_not_made_an_earth(self):
        # The no-colour earth default is the shorthand's, per Sales. The code comes out
        # the same either way: the size rule and the earth rule agree.
        result = match_line("16mm2 building wire", catalog)
        self.assertFalse(result["fields"].get("earth_only"))
        self.assertEqual(result["selected_product_code"], "LP-XXXN/1C16EA")

    def test_b_w_section_heading_follows_the_size_rule(self):
        self.assertEqual(match_line("1C 6mm2 red", catalog, context=["B/W"])["fields"]["style"], "LS")
        self.assertEqual(match_line("1C 16mm2 black", catalog, context=["B/W"])["fields"]["style"], "LP")

    def test_b_w_on_a_marine_line_keeps_the_marine_substitute(self):
        self.assertEqual(match_line("16mm2 marine B/W black", catalog)["fields"]["style"], "UP")

    def test_b_w_on_a_multicore_is_not_forced_to_single_core_ls(self):
        result = match_line("2C 1.5mm2 flex B/W", catalog)
        self.assertNotEqual(result["fields"]["style"], "LS")

    def test_explicit_series_keeps_precedence(self):
        result = match_line("1C 16mm2 building wire KL Series", catalog)
        self.assertEqual(result["fields"]["style"], "KL")

    def test_marine_building_wire_keeps_marine_substitute(self):
        result = match_line("1C 16mm2 marine building wire", catalog)
        self.assertEqual(result["fields"]["style"], "UP")

    def test_multicore_is_not_forced_to_single_core_ls(self):
        result = match_line("4C 16mm2 building wire", catalog)
        self.assertNotEqual(result["fields"]["style"], "LS")


class SingleInsulatedFamilyTests(unittest.TestCase):
    """"Single insulated" names the unsheathed building-wire family outright.

    LP/LS/UP are the only styles in the catalog with no sheath, so the phrase rules out
    every sheathed style rather than merely favouring these three; which one follows the
    insulation stated, exactly as the earth rule does. Before this the phrase scored
    nothing and an RFQ was decided by whatever else it said -- "SINGLE INSULATED FLEXIBLE
    HF-110" landed on KL, the sheathed 110C rung of the XL/KL/CC ladder.

    Not to be confused with "SDI" (Single DOUBLE Insulated), which is a sheathed single
    core -- the opposite construction.
    """

    HEADING = "SINGLE INSULATED FLEXIBLE HF-110 600/1000V"

    def test_hf_110_single_insulated_is_ls_not_the_sheathed_kl(self):
        result = match_line("1C 16mm2 Cu", catalog, context=[self.HEADING])
        self.assertEqual(result["fields"]["style"], "LS")

    def test_bare_row_inherits_the_phrase_from_its_section_heading(self):
        # The rows under such a heading carry no spec of their own, so the signal has to
        # be read from the heading -- it never reaches the row's own extracted fields.
        result = match_line("100 MTR - 16MM WHITE", catalog, context=[self.HEADING])
        self.assertEqual(result["selected_product_code"], "LS-XXXN/1C16WH")

    def test_black_row_does_not_drift_to_a_black_defaulted_style(self):
        result = match_line("100 MTR - 35MM BLACK", catalog, context=[self.HEADING])
        self.assertEqual(result["selected_product_code"], "LS-XXXN/1C35BK")

    def test_small_core_single_insulated_is_tinned(self):
        # <=6mm2 LS/LP/UP building wire is tinned copper ('D'), not plain 'N'.
        result = match_line("200 MTR - 2.5MM Yellow", catalog, context=[self.HEADING])
        self.assertEqual(result["selected_product_code"], "LS-XXXD/1C2.5YE")

    def test_plain_pvc_single_insulated_is_lp(self):
        result = match_line("1C 16mm2 Cu single insulated V-90", catalog)
        self.assertEqual(result["fields"]["style"], "LP")

    def test_small_plain_single_insulated_defaults_to_ls(self):
        result = match_line("1C 10mm2 Cu single insulated V-90", catalog)
        self.assertEqual(result["selected_product_code"], "LS-XXXN/1C10EA")

    def test_ls_flex_is_an_explicit_ls_style_keyword(self):
        result = match_line("1C 16mm2 Cu LS flex yellow", catalog)
        self.assertEqual(result["fields"]["style"], "LS")
        self.assertEqual(result["selected_product_code"], "LS-XXXN/1C16YE")

    def test_sdi_is_not_single_insulated(self):
        # "Flex S.D.I." is a sheathed single core; it must not reach the unsheathed three.
        result = match_line("1C 300mm2 Al Flex S.D.I. X-HF-110", catalog)
        self.assertEqual(result["fields"]["style"], "KL")

    def test_named_series_still_wins(self):
        result = match_line("1C 16mm2 Cu single insulated KL Series", catalog)
        self.assertEqual(result["fields"]["style"], "KL")


class UninformativeColourTests(unittest.TestCase):
    """Black is the house colour of 77 of the 114 styles, so on a power line it names no
    family. Paying the full colour-match bonus for it let KP and KB (submersible pump /
    battery, both BK) outrank the temperature ladder's own answer on a bare
    "100 MTR - 35MM BLACK" row. Distinctive colours must still discriminate."""

    def test_black_does_not_pull_a_power_line_off_the_ladder(self):
        self.assertEqual(match_line("100 MTR - 35MM BLACK", catalog)["fields"]["style"], "XL")

    def test_black_keeps_the_hot_rung_when_the_row_is_hot(self):
        self.assertEqual(match_line("1C 35mm2 Cu 110C black", catalog)["fields"]["style"], "KL")

    def test_earth_colour_still_selects_the_earth_style(self):
        self.assertEqual(match_line("1C 95mm2 Cu green/yellow earth", catalog)["fields"]["style"], "LP")


class LsflexInsulationNameTests(unittest.TestCase):
    """"LSFLEX R-70" is TriCab's trade name for the 110C rubber insulation, listed beside
    its synonyms in every rubber style's Insulation cell ("LSFLEX R-70 or X-HF-110 or 110
    deg C"). Read with the bare AS/NZS 3808 grammar it parses as "rubber at 70C", which
    dropped a whole aluminium submains schedule onto the 90C XL default; TriCab quoted it
    KL-PAXA, and a PowerCAD export in the corpus carries the same mapping on 89 rows."""

    def test_lsflex_r70_is_the_110c_rung(self):
        result = match_line("1C 300mm2 Al LSFLEX® R-70", catalog)
        self.assertEqual(result["selected_product_code"], "KL-PAXA/1C300BK")

    def test_other_lsflex_grades_keep_their_own_reading(self):
        # LSFLEX XL-20 is the 90C grade: it must not be swept up by the R-70 rule.
        self.assertEqual(match_line("1C 70mm2 Cu LSFLEX® XL-20", catalog)["fields"]["style"], "XL")

    def test_a_bare_r_70_keeps_the_3808_grammar(self):
        self.assertEqual(match_line("1C 70mm2 Cu R-70", catalog)["fields"]["style"], "XL")


class LsflexR125StyleTests(unittest.TestCase):
    """"R-125" is not just a temperature: it is LS's OWN insulation designation, and the
    only style in the register that claims it (R-70 is claimed by 24 sheathed styles,
    XL-20 by 9, the bare trade name by 62). A customer stock order whose every
    Description read "LSFLEX(R) R-125 (X-HF-110)" -- unsheathed switchboard/panel wire,
    1 mm2 to 70 mm2 -- coded as KL, sheathed power cable, because the shared X-HF-110
    token reached the temperature ladder while the unique R-125 signalled nothing. Two
    rows were right only by accident (a GREEN/YELLOW earth, and a spaced "LS FLEX"); the
    6 and 16 mm2 rows came back `matched` and would have gone through silently."""

    def test_lsflex_r125_is_the_unsheathed_ls_style(self):
        result = match_line("1mm2 PINK LSFLEX(R) R-125 (X-HF-110) 200m", catalog)
        self.assertEqual(result["fields"]["style"], "LS")
        self.assertEqual(result["selected_product_code"], "LS-XXXD/1C1PI")

    def test_r125_holds_above_the_small_conductor_range(self):
        # is_small_ls_conductor (<=10 mm2) is only consulted inside the earth_only and
        # single_insulated branches, so it never sees these rows. The sheet runs to
        # 70 mm2 and those sizes are as unsheathed as the 1 mm2 ones.
        for size in ("16", "25", "35", "50", "70"):
            with self.subTest(size=size):
                result = match_line(f"{size}mm2 BLACK LSFLEX(R) R-125 (X-HF-110) 100m", catalog)
                self.assertEqual(result["fields"]["style"], "LS")

    def test_the_compact_and_bare_spellings_all_read_as_ls(self):
        for text in (
            "1mm2 PINK LSFLEX R-125 200m",
            "1mm2 PINK LSFLEX125 200m",
            "1mm2 PINK LSFLEX 125 200m",
            "1mm2 PINK R-125 200m",
            "1mm2 PINK R125 200m",
        ):
            with self.subTest(text=text):
                self.assertEqual(match_line(text, catalog)["fields"]["style"], "LS")

    def test_a_marine_r125_row_still_lands_on_the_marine_style(self):
        # The marine range substitutes UP for LS, exactly as the "LS flex" branch does.
        result = match_line("1mm2 PINK LSFLEX(R) R-125 marine DNV approved 200m", catalog)
        self.assertEqual(result["fields"]["style"], "UP")

    def test_r70_and_xl20_keep_their_sheathed_styles(self):
        # The protecting assertion: the fix is R-125 alone, NOT "LSFLEX is a style
        # signal". A real schedule row "1C 300mm2 Al LSFLEX(R) R-70" is a sheathed
        # aluminium phase and must stay one.
        self.assertEqual(match_line("1C 300mm2 Al LSFLEX® R-70", catalog)["fields"]["style"], "KL")
        self.assertEqual(match_line("1C 70mm2 Cu LSFLEX® XL-20", catalog)["fields"]["style"], "XL")

    def test_a_stated_multicore_falls_through_to_normal_ranking(self):
        # Unsheathed building wire is single core; a stated core count contradicts the
        # designation, so the row keeps the ordinary ladder rather than being forced to LS.
        self.assertEqual(match_line("4C 25mm2 Cu LSFLEX R-125", catalog)["fields"]["style"], "KL")


class ScreenedInstrumentationArmourCharTests(unittest.TestCase):
    """A stated screen must not eat the armour character (SELECTION_LOGIC_FINDINGS F2).

    The R-series carries its screen in the style itself, not in the armour/braiding
    columns, so a screened instrumentation line fell through derive_armour_code's
    `if fields.get("screen"): return ""` and built a 3-character zone 2 (R1-DEN/...).
    All 2,479 instrumentation products in the catalog have a 4-character zone 2; the
    empty armour character is never a valid form. Only fired when the screen was
    STATED, so `12PR 1.5mm2` was right and `12PR 1.5mm2 OS` -- how anyone actually
    writes it -- was not."""

    # The generated code is what gets quoted and pushed, and it is the half that was
    # broken: _collapse_to_standard_form still found a real stocked product, so
    # selected_product_code looked right while canonical_code carried the invalid form.
    def _code(self, text):
        return match_line(text, catalog)["fields"]["canonical_code"]

    def test_collective_screen_keeps_the_unarmoured_x(self):
        self.assertEqual(self._code("12PR 0.5mm2 OS instrumentation"), "R1-DEXN/12P.5BK")

    def test_individual_screen_keeps_the_unarmoured_x(self):
        self.assertEqual(self._code("12PR 1.5mm2 IOS"), "R3-DEXN/12P1.5BK")

    def test_screened_450_750v_pair_keeps_the_unarmoured_x(self):
        self.assertEqual(self._code("12PR 1.5mm2 OS 450/750V"), "RE-DEXN/12P1.5BK")

    def test_unstated_screen_is_unchanged(self):
        # The case that already worked -- it must keep working.
        self.assertEqual(self._code("12PR 1.5mm2"), "R1-DEXN/12P1.5BK")

    def test_every_generated_zone_2_is_four_characters(self):
        # The whole 18,994-row catalog has exactly one 3-character zone 2
        # (OT-PDN/5C2.5BK, an export error): the empty armour character is never valid.
        for text in (
            "12PR 0.5mm2 OS instrumentation",
            "12PR 1.5mm2 IOS",
            "12PR 1.5mm2 OS 450/750V",
            "8TR 1.5mm2 OS",
            "4PR 0.5mm2 IOS 110V",
        ):
            with self.subTest(text=text):
                zone2 = self._code(text).split("/")[0].split("-")[1]
                self.assertEqual(len(zone2), 4)

    def test_screened_power_still_takes_the_copper_laminate_z(self):
        # The VSD/screened power family gets Z from the copper-laminate branch further
        # up, so it never reaches the line this fix changed.
        self.assertEqual(match_line("4C 1.5mm2 screened VSD", catalog)["fields"]["armour"], "Z")


class ControlVoltageTierTests(unittest.TestCase):
    """A stated voltage must survive the control re-homing (SELECTION_LOGIC_FINDINGS F5).

    The C-series is a voltage tier: C1-C6 are 110 V, KC/CF are 450/750 V, XL-CA/KL-CA/
    CC-CA are 600/1000 V. style_violates_construction gates on that correctly, but the
    standard-control re-homing synthesises an XL/KL/CC-CA code directly and never
    consulted the gate -- so stating 110 V on a control line had no effect whatsoever."""

    def _style(self, text):
        return match_line(text, catalog)["fields"]["style"]

    def test_110v_control_stays_in_the_110v_tier(self):
        self.assertEqual(self._style("4C 1.5MM2 CONTROL 110V"), "C1")

    def test_450_750v_control_stays_in_the_mid_tier(self):
        self.assertEqual(self._style("4C 1.5MM2 CONTROL 450/750V"), "KC")

    def test_unstated_voltage_still_re_homes_to_the_house_default(self):
        # The confirmed default (TriCab 2026-06-30) is untouched: standard control with
        # no stated voltage is still XL-CA, black, even where no product is stocked.
        result = match_line("4C 1.5MM2 CONTROL", catalog)
        self.assertEqual(result["selected_product_code"], "XL-CAXN/4C1.5BK")

    def test_600_1000v_control_re_homes_as_before(self):
        self.assertEqual(self._style("4C 1.5MM2 CONTROL 600/1000V"), "XL")

    def test_stated_voltage_holds_across_the_core_count(self):
        self.assertEqual(self._style("12C 1.5mm2 control 110V"), "C1")


class StyleDefaultConductorTests(unittest.TestCase):
    """A style default conductor the catalog never stocks excludes its own products
    (SELECTION_LOGIC_FINDINGS F6).

    `conductor` is a hard filter in violates_hard_constraints and the resolver derives
    it from the top-ranked style, so KC's register default of D (tinned) gated out all
    118 KC products -- every one of which is N. Naming rubber, KC's OWN sheath material,
    lifts KC to the top of the ranking and therefore moved the answer OFF KC onto CF
    (Flexible LSZH Control), a different family. Same class as the DVA-PATA aluminium
    design example already handled for the marine range."""

    def _code(self, text):
        return match_line(text, catalog)["selected_product_code"]

    def test_rubber_does_not_flip_control_onto_the_lszh_style(self):
        self.assertEqual(self._code("4C 1.5mm2 control rubber"), "KC-CAXN/4C1.5BK")

    def test_flexible_rubber_agrees_with_flexible(self):
        self.assertEqual(
            self._code("4C 1.5mm2 control flexible rubber"),
            self._code("4C 1.5mm2 control flexible"),
        )

    def test_no_style_default_conductor_is_absent_from_its_own_catalog_rows(self):
        for code, style in catalog.styles.items():
            products = catalog.products_by_style.get(code) or []
            stocked = {(p.conductor_type or "").upper() for p in products if (p.conductor_type or "").strip()}
            if not stocked:
                continue
            with self.subTest(style=code):
                self.assertIn(style.default_conductor_code.upper(), stocked)

    def test_a_style_stocking_both_keeps_its_register_default(self):
        # LS stocks D and N; the reconciliation must only fire where the default has
        # zero catalog rows, never re-decide a genuine mixed range.
        self.assertEqual(catalog.styles["LS"].default_conductor_code, "N")


class ControlCoreBoundaryTests(unittest.TestCase):
    """More than 5 cores is a control construction (SELECTION_LOGIC_FINDINGS F1).

    Confirmed by Manusha 2026-08-26. 5C *is* 4C+E, the largest power construction;
    above that you are numbering cores, which is control. The engine used to keep the
    style's PA power default at any core count and synthesise codes that are not
    products -- `XL-PAXN/7C2.5OR` where the stocked row is `XL-CAXN/7C2.5OR`."""

    def _fields(self, text):
        return match_line(text, catalog)["fields"]

    def _code(self, text):
        return self._fields(text)["canonical_code"]

    def test_six_cores_takes_the_control_config(self):
        self.assertEqual(self._code("6C 2.5mm2"), "XL-CAXN/6C2.5BK")

    def test_five_cores_keeps_the_power_config(self):
        # The boundary itself: 5C is 4C+E and stays PA.
        self.assertEqual(self._code("5C 2.5mm2"), "XL-PAXN/5C2.5OR")

    def test_four_core_plus_earth_keeps_the_power_config(self):
        self.assertEqual(self._code("4C+E 2.5mm2"), "XL-PAXN/5C2.5OR")

    def test_six_core_plus_earth_crosses_the_boundary(self):
        self.assertEqual(self._code("1.5mm2 6C+E Cu/PVC/PVC/SWA/PVC"), "XA-CASN/7C1.5BK")

    def test_high_core_lszh_takes_the_control_config(self):
        self.assertEqual(self._code("12C 2.5mm2 LSZH"), "CC-CAXN/12C2.5BK")

    def test_high_core_fire_defaults_to_control_black(self):
        # With no stated colour, a high-core control construction defaults to black.
        self.assertEqual(self._code("12C 1.5mm2 fire rated"), "FR-CAXN/12C1.5BK")

    def test_high_core_vsd_takes_the_control_config(self):
        self.assertEqual(self._code("7C 2.5mm2 VSD"), "DP-CAZN/7C2.5BK")

    def test_pairs_are_instrumentation_not_control(self):
        # The rule reads plain numbered C cores only; P/T/Q keep the DE ladder.
        self.assertEqual(self._code("12PR 1.5mm2"), "R1-DEXN/12P1.5BK")

    def test_no_generated_high_core_code_is_a_power_config(self):
        for text in (
            "6C 2.5mm2",
            "7C 1.5mm2",
            "12C 1.5mm2 Cu/PVC/PVC",
            "27C 1.5mm2",
            "16C 1.5mm2 LSZH SWA",
            "10C+E 6mm2 power cable",
        ):
            with self.subTest(text=text):
                config = self._fields(text)["config_core"]
                self.assertTrue(
                    config.startswith("C"),
                    f"{text} -> config {config}, expected a control config",
                )


if __name__ == "__main__":
    unittest.main()


class InstrumentCoreConfigurationTests(unittest.TestCase):
    def test_instrument_cores_plus_earth_follow_normal_control_tree(self):
        specifications = (
            "V75 PVC 75°C 24VDC",
            "PVC 110V",
            "PVC 450/750V",
            "PVC 0.6/1kV",
            "PVC 110°C",
            "LSZH",
            "PVC flexible",
            "PVC SWA",
            "PVC overall screen",
        )
        styles = set()
        for spec in specifications:
            expected = match_line(f"1.5mm2 8C+E {spec} Control 105m", catalog)
            styles.add(expected["fields"].get("style"))
            for label in ("Instrument", "Instrumentation"):
                for config in ("8C+E", "8C + E"):
                    for overrides in (None, {"cable_type_hint": "Instrumentation"}):
                        with self.subTest(spec=spec, label=label, config=config, overrides=overrides):
                            result = match_line(
                                f"1.5mm2 {config} {spec} {label} 105m",
                                catalog, field_overrides=overrides,
                            )
                            self.assertEqual(result["fields"]["cable_type_hint"], "Control")
                            self.assertEqual(result["fields"]["count"], 9)
                            self.assertEqual(result["fields"].get("style"), expected["fields"].get("style"))
                            self.assertEqual(result["selected_product_code"], expected["selected_product_code"])
                            self.assertEqual(result["status"], expected["status"])
        self.assertGreater(len(styles - {None}), 1)

    def test_instrument_pairs_remain_instrumentation(self):
        result = match_line("1.5mm2 50PR V-75 ID/OA 75°C 24VDC/4-20mA Instrument 180m", catalog)
        self.assertEqual(result["fields"]["cable_type_hint"], "Instrumentation")
        self.assertEqual(result["fields"]["core_type"], "P")
        self.assertEqual(result["fields"]["count"], 50)
        self.assertNotEqual(result["fields"]["style"], "C1")


class AssembledLeadResolverTests(unittest.TestCase):
    """Haymans draft fca38b24: a 4C+E cable with plugs fitted was quoted as G01 heat
    shrink with no code. With the AI's fields applied it must resolve as the cable."""

    def test_assembled_lead_resolves_as_cable(self):
        text = (
            "Set 1 3 phase 4C+E cable: Active 4mm2, Earth 2.5mm2; rated at least 90°C, shielded "
            "preferred, flexible and UV-rated for outdoor use; Government branding and individual "
            "designation where required; labelled at both ends; fully assembled with heat shrink, "
            "male/female Clipsal IP56 32A 5-pin plugs, test and tag"
        )
        overrides = {
            "earth": True, "raw_core_expression": "4C+E", "count": 5, "earth_count": 1,
            "core_type": "C", "cross_section": "4", "alternate_sizes": ["2.5"],
        }

        result = match_line(text, catalog, field_overrides=overrides)

        self.assertEqual(result["item_type"], "cable")
        self.assertNotEqual(result.get("line_kind"), "accessory")
        self.assertEqual(result["selected_product_code"], "XL-PAXN/5C4OR")


class ConduitResolverTests(unittest.TestCase):
    """Conduit is off the accessory list; a conduit line must still never be quoted as
    a cable at its bore size ("25mm PVC conduit" was XL-PAXN/1C25OR, `matched`)."""

    def test_conduit_itself_needs_review_with_no_code(self):
        result = match_line("25mm PVC conduit 20 lengths", catalog)

        self.assertEqual(result["status"], "unresolved")
        self.assertEqual(result["selected_product_code"], "")
        self.assertNotEqual(result.get("line_kind"), "accessory")
        self.assertIn("conduit", " ".join(result["warnings"]).lower())

    def test_cable_in_conduit_is_quoted(self):
        result = match_line("Cable 1C 120mm2 Cu Rigid XLPE/PVC - In Conduit", catalog)

        self.assertEqual(result["item_type"], "cable")
        self.assertEqual(result["selected_product_code"], "XL-PAXN/1C120OR")


class OrangeCircularShorthandTests(unittest.TestCase):
    """"OC" is orange circular: plain power, so XL/KL/CC by the house defaults unless a
    feature such as armour is stated (Sales, 2026-10-26; estimate 7562)."""

    def test_oc_lines_take_the_house_default_style(self):
        cases = (
            ("2.5 2CE OC", "XL-PAXN/3C2.5OR"),
            ("4.0 4CE OC", "XL-PAXN/5C4OR"),
            ("2.5 2CE OC 110C", "KL-PAXN/3C2.5OR"),
            ("6.0 4CE OC LSZH", "CC-PAXN/5C6OR"),
        )
        for text, code in cases:
            with self.subTest(text=text):
                result = match_line(text, catalog)
                self.assertEqual(result["selected_product_code"], code)
                self.assertEqual(result["status"], "matched")

    def test_armour_on_an_oc_line_leaves_the_plain_styles(self):
        result = match_line("6.0 4CE OC SWA", catalog)

        self.assertNotIn(result["fields"]["style"], {"XL", "KL", "CC"})
