# Generated from the RFQ parser's tests/test_marine.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Marine/offshore range: opt-in gating, the style matrix, and jacket handling.

The rules come from TriCab's MARINE_OFFSHORE workbook (data/catalog/marine_offshore.xlsx,
built by scripts/build_marine_styles.py). Nothing in the sample corpus or the production
review log is marine, so these synthetic lines are the only coverage the range has --
keep them in step with the workbook's application-label column.
"""

import unittest

from rfq_parser.pipeline import catalog
from tricab_cable_rules.extractor import extract_entities
from tricab_cable_rules.resolver import match_line, _marine_target_style_code


def _fields(text):
    return extract_entities(text, set())["fields"]


class MarineCatalogTests(unittest.TestCase):
    def test_all_twenty_five_marine_styles_are_loaded(self):
        marine = {code for code, style in catalog.styles.items() if style.marine}
        self.assertEqual(
            marine,
            {
                "BV", "PJ", "RR", "BH", "DV", "DF", "RC", "RS", "ES", "FJ", "EW", "EG",
                "FC", "FL", "UP", "HT", "RB", "FK", "FZ", "FE", "NV", "NR", "NJ", "NT", "NU",
            },
        )

    def test_marine_families_are_assigned(self):
        families = {}
        for code, style in catalog.styles.items():
            if style.marine:
                families.setdefault(style.marine_family, set()).add(code)
        self.assertEqual(families["nek"], {"NV", "NR", "NJ", "NT", "NU"})
        self.assertEqual(families["offshore"], {"PJ", "HT", "RB", "FK", "FZ", "FE"})
        self.assertIn("BV", families["shipboard"])

    def test_offshore_styles_are_armoured_shipboard_braid_is_not(self):
        # Braid over a bedding is armour; braid directly over the cores is a screen.
        self.assertTrue(catalog.styles["PJ"].has_armour)
        self.assertTrue(catalog.styles["NJ"].has_armour)
        self.assertFalse(catalog.styles["DV"].has_armour)

    def test_conductor_default_is_tinned_not_the_design_examples_aluminium(self):
        # DVA-PATA/5C150BK is the aluminium exemplar; taking its conductor code would
        # hard-filter out all 750 copper DV products (conductor is a hard constraint).
        self.assertEqual(catalog.styles["DV"].default_conductor_code, "D")

    def test_upa_products_are_reachable(self):
        # 159 UPA rows arrive from tpro with style="UPA" and an empty sheath column.
        codes = {p.product_code for p in catalog.products_by_style["UP"]}
        self.assertIn("UPA-XXXD/1C.75BK", codes)
        self.assertIn("UP-XXXD/1C.5BK", codes)


class MarineDetectionTests(unittest.TestCase):
    def test_marine_family_detection(self):
        self.assertEqual(_fields("10C 2.5mm2 shipboard cable")["marine_family"], "shipboard")
        self.assertEqual(_fields("10C 2.5mm2 offshore cable")["marine_family"], "offshore")
        self.assertEqual(_fields("3C 2.5mm2 NEK606 cable")["marine_family"], "nek")
        self.assertEqual(_fields("3C 2.5mm2 NEK 606 P1/P8")["marine_family"], "nek")

    def test_ordinary_lines_are_not_marine(self):
        for text in (
            "4C 16mm2 XLPE/PVC 0.6/1kV armoured",
            "4C+E 120mm Cu",
            "10P 1.5mm2 instrumentation OS",
        ):
            self.assertNotIn("marine", _fields(text), text)

    def test_jacket_codes(self):
        self.assertEqual(_fields("10C 2.5mm2 marine SHF-2")["sheath"], "B")
        self.assertEqual(_fields("10C 2.5mm2 marine SHF 1")["sheath"], "A")
        self.assertEqual(_fields("10C 2.5mm2 marine NEK606 SHF-2")["sheath"], "C")
        self.assertEqual(_fields("10C 2.5mm2 marine NEK606 MUD")["sheath"], "M")
        self.assertEqual(_fields("10C 2.5mm2 marine SW4")["sheath"], "D")
        self.assertEqual(_fields("10C 2.5mm2 marine TPE")["sheath"], "E")
        self.assertEqual(_fields("10C 2.5mm2 marine low friction")["sheath"], "P")
        self.assertEqual(_fields("10C 2.5mm2 marine anti-termite")["sheath"], "T")

    def test_bare_pvc_is_not_read_as_the_pvc_jacket(self):
        # "XLPE/PVC" is the insulation compound, not the jacket.
        self.assertIsNone(_fields("10C 2.5mm2 marine XLPE/PVC").get("sheath"))
        self.assertEqual(_fields("10C 2.5mm2 marine PVC sheath")["sheath"], "F")

    def test_marine_termite_is_the_t_jacket_not_the_nylon_family(self):
        fields = _fields("10C 2.5mm2 marine termite resistant")
        self.assertEqual(fields["sheath"], "T")
        self.assertNotIn("termite", fields)
        # A non-marine termite line still routes to the nylon family.
        self.assertTrue(_fields("4C 70mm2 XLPE/PVC 110C termite nylon")["termite"])


class MarineStyleMatrixTests(unittest.TestCase):
    """The full workbook matrix, keyed on its application-label column."""

    def _style(self, text):
        return match_line(text, catalog)["fields"]["style"]

    def test_shipboard(self):
        self.assertEqual(self._style("10C 2.5mm2 marine shipboard cable"), "BV")
        self.assertEqual(self._style("10C 2.5mm2 shipboard braided power cable"), "DV")
        self.assertEqual(self._style("12C 1.5mm2 shipboard fire rated power cable"), "RR")
        self.assertEqual(self._style("7C 1.5mm2 shipboard fire rated braided cable"), "BH")
        self.assertEqual(self._style("4C 10mm2 shipboard VSD braided cable"), "DF")

    def test_shipboard_instrumentation(self):
        self.assertEqual(self._style("16P 0.75mm2 marine instrumentation collective screen"), "RC")
        self.assertEqual(self._style("12P 0.75mm2 marine instrumentation OS braided"), "RS")
        self.assertEqual(self._style("10P 1.5mm2 marine instrumentation OS fire rated"), "ES")
        self.assertEqual(self._style("19P 0.5mm2 marine instrument OS braided fire rated"), "FJ")
        self.assertEqual(self._style("10P 1mm2 marine instrumentation IOS"), "EW")
        self.assertEqual(self._style("12P 1.5mm2 marine instrumentation IOS braided"), "EG")
        self.assertEqual(self._style("12T 1.5mm2 marine instrumentation IOS fire rated"), "FC")
        self.assertEqual(self._style("10P 1mm2 marine instrument IOS braided fire rated"), "FL")

    def test_offshore(self):
        self.assertEqual(self._style("7C 1.5mm2 offshore braid armoured power cable"), "PJ")
        self.assertEqual(self._style("12C 2.5mm2 offshore braid armour fire rated power"), "HT")
        self.assertEqual(self._style("10P 2.5mm2 offshore instrumentation OS braid armour"), "RB")
        self.assertEqual(self._style("10P 1.5mm2 offshore instrument OS braid armour fire rated"), "FK")
        self.assertEqual(self._style("10P 1.5mm2 offshore instrumentation IOS braid armour"), "FZ")
        self.assertEqual(self._style("10T 1.5mm2 offshore instrument IOS braid armour fire"), "FE")

    def test_nek_ts606(self):
        self.assertEqual(self._style("3C 2.5mm2 NEK 606 RU P18 power cable"), "NV")
        self.assertEqual(self._style("3C 10mm2 NEK 606 BU P17 fire resistant power"), "NR")
        self.assertEqual(self._style("19C 1.5mm2 NEK 606 P1/P8 braid armoured power"), "NJ")
        self.assertEqual(self._style("12C 1.5mm2 NEK606 P5/P12 braid armoured fire resistant"), "NT")
        self.assertEqual(self._style("4C 120mm2 NEK606 P1/P8 braid armoured VSD"), "NU")

    def test_bv_is_the_marine_house_default(self):
        # BV is to a marine line what XL is to a mainstream one.
        self.assertEqual(
            _marine_target_style_code({"marine": True, "marine_family": "shipboard"}, ""), "BV"
        )
        self.assertEqual(self._style("10C 2.5mm2 marine cable"), "BV")

    def test_up_is_the_marine_earth_and_panel_wire(self):
        self.assertEqual(self._style("1C 16mm2 marine earth cable"), "UP")
        self.assertEqual(self._style("1C 6mm2 shipboard panel wire"), "UP")

    def test_uncovered_combinations_return_no_target(self):
        # NEK TS606 has no instrumentation cable and there is no offshore VSD/VFD.
        self.assertIsNone(
            _marine_target_style_code({"marine": True, "marine_family": "nek", "core_type": "P"}, "")
        )
        self.assertIsNone(
            _marine_target_style_code(
                {"marine": True, "marine_family": "offshore", "cable_type_hint": "VSD/VFD"}, ""
            )
        )


class MarineGatingTests(unittest.TestCase):
    """The range is opt-in: it must never compete for an ordinary industrial line."""

    def _style(self, text):
        return match_line(text, catalog)["fields"]["style"]

    def test_mainstream_defaults_are_untouched(self):
        self.assertEqual(self._style("4C+E 120mm Cu"), "XL")
        self.assertEqual(self._style("4C+E 120mm Cu 110C"), "KL")
        self.assertEqual(self._style("4C+E 120mm Cu LSZH"), "CC")

    def test_lszh_control_does_not_fall_into_the_marine_range(self):
        # BV is "Flexible Control LSZH" at 600/1000 V -- the nearest marine trap.
        self.assertEqual(self._style("10C 2.5mm2 LSZH control cable"), "CC")

    def test_marine_line_resolves_to_a_marine_style(self):
        result = match_line("10C 2.5mm2 marine shipboard cable", catalog)
        self.assertEqual(result["selected_product_code"], "BVA-CAXD/10C2.5BK")
        self.assertTrue(any("Marine range resolved" in w for w in result["warnings"]))


class MarineJacketFallbackTests(unittest.TestCase):
    def test_nek_line_resolves_despite_the_design_example_jacket(self):
        # NV illustrates jacket C but the catalog stocks mostly M (NEK606 MUD); an
        # unstated jacket must not leave the line unresolved.
        result = match_line("3C 2.5mm2 NEK 606 RU P18 power cable", catalog)
        self.assertEqual(result["fields"]["style"], "NV")
        self.assertTrue(result["selected_product_code"])

    def test_an_explicitly_named_jacket_stays_hard(self):
        result = match_line("19C 1.5mm2 NEK 606 P1/P8 braid armoured NEK606 MUD", catalog)
        self.assertEqual(result["fields"]["sheath"], "M")
        self.assertTrue(result["selected_product_code"].startswith("NJM-"))


if __name__ == "__main__":
    unittest.main()
