# Generated from the RFQ parser's tests/test_voltage_gate.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""The voltage band gate, its satisfy-or-exceed fallback, and what must not move.

`style_violates_construction` gates a style out when the stated voltage is disjoint from
the style's band. A rating the register has no band for therefore gated out the ENTIRE
catalog -- `300/500V` is a real, common flexible rating and the row landed `unresolved` at
0.25 with the rep sent to Technical. These tests pin the fix and, just as importantly, the
three things it must not break.
"""

import unittest

from rfq_parser.pipeline import catalog
from tricab_cable_rules.extractor import extract_voltage_reading, extract_voltages
from tricab_cable_rules.resolver import match_line


def band_of(result):
    style = catalog.styles.get(result["fields"].get("style"))
    return sorted(style.voltages) if style else None


class VoltageReadingTests(unittest.TestCase):
    def test_volts_spelled_out_and_vac_are_read(self):
        for text in ("110 VOLTS", "110VOLT", "110VAC", "240 V AC", "240V DC"):
            self.assertTrue(extract_voltages(text), f"{text} read as no voltage")

    def test_class_words_are_not_mapped_to_a_band(self):
        """`LV`/`MV`/`HV` are class words, not ratings. Inventing 600/1000 from "LV" is
        what FIELD_SEMANTICS forbids the agent from doing; the regex must not do it either."""
        for text in ("LV CABLE", "MV 95MM2", "HV FEEDER", "LV/MV"):
            self.assertEqual(extract_voltages(text), set(), text)

    def test_a_slash_pair_is_distinguished_from_a_bare_single(self):
        self.assertEqual(extract_voltage_reading("300/500V")[1], True)
        self.assertEqual(extract_voltage_reading("415V")[1], False)

    def test_a_pair_whose_halves_are_equal_is_still_a_pair(self):
        """1500/1500 V is a real register band, and it collapses to a one-element set --
        which is why pair-ness cannot be inferred from len(voltage_set)."""
        volts, is_pair = extract_voltage_reading("1500/1500 V")
        self.assertEqual(volts, {1500})
        self.assertTrue(is_pair)

    def test_a_v_that_starts_another_word_is_not_a_voltage(self):
        self.assertEqual(extract_voltages("110 VSD CABLE"), set())


class SatisfyOrExceedFallbackTests(unittest.TestCase):
    def test_300_500_falls_back_to_450_750_and_selection_proceeds(self):
        result = match_line("4C 2.5mm2 300/500V flexible", catalog)
        self.assertEqual(result["fields"]["voltage_set"], [300, 500])
        self.assertEqual(result["fields"]["voltage_gate_set"], [450, 750])
        self.assertNotEqual(result["status"], "unresolved")
        self.assertTrue(result["selected_product_code"])
        self.assertEqual(band_of(result), [450, 750])

    def test_300_500_never_selects_an_under_rated_style(self):
        """Satisfy-or-exceed, never nearest: 300/500 is equidistant from 150/250 and
        450/750, and half of "nearest"'s answers are a cable rated below the requirement."""
        for text in ("4C 2.5mm2 300/500V flexible", "4C 1.5mm2 300/500V control cable"):
            result = match_line(text, catalog)
            self.assertEqual(band_of(result), [450, 750], text)

    def test_the_warning_names_both_voltages_and_the_reasoning(self):
        """The rep is being asked to accept a rating the customer did not ask for. A
        warning reading only "voltage substituted" makes them re-derive why."""
        warnings = match_line("4C 2.5mm2 300/500V flexible", catalog)["warnings"]
        substitution = [w for w in warnings if "satisfies the stated" in w]
        self.assertTrue(substitution, warnings)
        text = substitution[0]
        self.assertIn("450/750 V", text)
        self.assertIn("300/500 V", text)
        self.assertIn("TriCab does not make", text)

    def test_the_fallback_is_a_fallback_not_a_replacement(self):
        """An exact band still wins outright -- nothing is substituted when one exists."""
        result = match_line("4C 2.5mm2 450/750V control", catalog)
        self.assertEqual(result["fields"]["voltage_gate_set"], [450, 750])
        self.assertNotIn(
            "satisfies the stated", " ".join(result["warnings"]),
        )


class BareSingleVoltageTests(unittest.TestCase):
    """A bare single is usually the *system* voltage, not the cable's rating -- but only
    below the MV rungs. Both halves of that are load-bearing."""

    def test_415v_no_longer_gates_the_whole_catalog(self):
        result = match_line("4C 2.5mm2 415V control cable", catalog)
        self.assertEqual(result["fields"]["voltage_gate_set"], [])
        self.assertNotEqual(result["status"], "unresolved")
        # 600/1000 is the standard cable for a 415 V system; satisfy-or-exceed would have
        # under-rated it at 450/750, which is why the fallback is pairs-only.
        self.assertEqual(band_of(result), [600, 1000])

    def test_a_bare_single_above_1000v_still_gates(self):
        """TriCab makes no 8 kV cable, and the rep must be sent to Technical rather than
        handed a `matched` code. Suppressing bare singles wholesale broke exactly this."""
        result = match_line("4C+E 70mm2 Cu XLPE PVC SWA armoured 8kV", catalog)
        self.assertEqual(result["status"], "unresolved")

    def test_11kv_still_routes_to_mv_power(self):
        result = match_line("MV cable 11kV 3C 95mm2", catalog)
        self.assertEqual(band_of(result), [6350, 11000])
        self.assertEqual(catalog.styles[result["fields"]["style"]].cable_type, "MV Power")


class ControlVoltageUnchangedTests(unittest.TestCase):
    """The regression the whole guard exists to prevent.

    `_rehome_target` synthesises a code directly and bypasses every other gate, so it
    re-checks `style_violates_construction` itself. 110 V keeps the C-series only because
    the 110 band exists and therefore still gates XL/KL out. Note 110 V is a BARE SINGLE --
    suppressing bare singles from the band test unconditionally returns this row to
    `XL-CAXN/4C1.5BK`, which is what that guard was written to fix.
    """

    def test_110v_control_stays_on_the_c_series(self):
        for text in (
            "4C 1.5MM2 CONTROL 110V",
            "4C 1.5MM2 CONTROL 110 VOLTS",
            "4C 1.5MM2 CONTROL 110VAC",
        ):
            result = match_line(text, catalog)
            self.assertEqual(result["fields"]["voltage_gate_set"], [110], text)
            self.assertTrue(result["fields"]["style"].startswith("C"), f"{text} -> {result['selected_product_code']}")

    def test_0_6_1kv_power_is_untouched(self):
        result = match_line("4C 35mm2 XLPE PVC 0.6/1kV", catalog)
        self.assertEqual(result["selected_product_code"], "XL-PAXN/4C35OR")


if __name__ == "__main__":
    unittest.main()
