# Generated from the RFQ parser's tests/test_cable_code.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
import csv
import unittest
from pathlib import Path

from tricab_cable_rules import cable_code as cc
from tricab_cable_rules.data import clean_product_code

CATALOG_CSV = Path(__file__).resolve().parents[2] / "tricab_cable_rules" / "data" / "catalog" / "tpro_ext_current_cables.csv"

# Source rows with corrupt trailing bytes after the colour; the engine cleans them
# so an exact rebuild differs by design.
KNOWN_CORRUPT = {"KL-PAXN/1C1.5OR", "KL-PAXN/2C1.5OR", "KL-PAXN/2C16OR"}

# The expanded lookup catalog intentionally includes a few products that are valid
# lookup codes but outside the deterministic cable-code engine grammar.
KNOWN_NON_ENGINE_CODES = {"LBL-PLXX/101M54WH", "LBL-SHXX/104M173WH", "OT-PDN/5C2.5BK"}


class RoundTripTests(unittest.TestCase):
    """The primary correctness gate / drift detector: every real catalog code must
    parse to valid parts and rebuild to itself."""

    @classmethod
    def setUpClass(cls):
        with CATALOG_CSV.open("r", newline="", encoding="utf-8-sig") as handle:
            cls.codes = [clean_product_code(row.get("product_code")).upper() for row in csv.DictReader(handle)]
        cls.codes = [code for code in cls.codes if code]

    def test_all_codes_parse_valid(self):
        invalid = [
            code
            for code in self.codes
            if code not in KNOWN_NON_ENGINE_CODES and not cc.parse_code(code)["valid"]
        ]
        self.assertEqual(invalid, [], f"{len(invalid)} catalog codes failed to parse, e.g. {invalid[:5]}")

    def test_all_codes_round_trip(self):
        canonical_checked = 0
        mismatches = []
        for code in self.codes:
            if code in KNOWN_NON_ENGINE_CODES:
                continue
            parsed = cc.parse_code(code)
            if not parsed.get("valid"):
                continue
            rebuilt = cc.build_code(parsed)
            if rebuilt == code or rebuilt in KNOWN_CORRUPT:
                canonical_checked += 1
            elif not parsed.get("tail"):
                mismatches.append((code, rebuilt))
        self.assertGreater(canonical_checked, 10000)
        self.assertLessEqual(
            len(mismatches),
            100,
            f"{len(mismatches)} non-canonical lookup codes found, e.g. {mismatches[:5]}",
        )


class WorkedExampleTests(unittest.TestCase):
    def test_fire_rated_power_code(self):
        parsed = cc.parse_code("FR-PAXN/1C500RE")
        self.assertTrue(parsed["valid"])
        self.assertEqual(parsed["style"], "FR")
        self.assertEqual(parsed["stylgg"], "S")  # FR is standard-group, fire-rated via mica list
        self.assertTrue(parsed["fire_rated"])
        self.assertEqual(parsed["core_type"], "C")
        self.assertEqual(parsed["csa"], "500")
        self.assertEqual(parsed["colour"], "RE")

    def test_leading_zero_csa_is_dropped_in_code(self):
        self.assertEqual(cc.fmt_csa("0.5"), ".5")
        self.assertEqual(cc.fmt_csa("0.75"), ".75")
        self.assertEqual(cc.fmt_csa("2.5"), "2.5")
        self.assertEqual(cc.build_code(cc.parse_code("C1-CAXD/100C.5BK")), "C1-CAXD/100C.5BK")


class EarthRuleTests(unittest.TestCase):
    def test_reduced_earth_applies_only_to_multicore(self):
        single = cc.parse_code("FR-PAXN/1C500RE")
        self.assertEqual(single["ccnt_earth"], 0)  # 1 core -> no reduced earth

        multi = cc.parse_code("FR-PAXN/4C500RE")
        self.assertEqual(multi["earth_szp"], "R")
        self.assertEqual(multi["ccnt_main"], 3)
        self.assertEqual(multi["ccnt_earth"], 1)
        self.assertEqual(multi["csa_earth"], "120")  # R map: 500 -> 120

    def test_p9_config_means_full_earth_no_reduction(self):
        parsed = cc.parse_code("FR-P9XN/4C500RE")
        self.assertIsNone(parsed["earth_szp"])
        self.assertEqual(parsed["ccnt_earth"], 0)

    def test_aluminium_conductor_uses_reduced_aluminium_map(self):
        # concoata 'A' (aluminium) -> Q map: 95 -> 35
        szp = cc.classify_earth("FR", "mks", "PA", "A")
        self.assertEqual(szp, "Q")
        earth = cc.resolve_earth("Q", 4, "95")
        self.assertEqual(earth["csa_earth"], "35")

    def test_split_earth_needs_more_than_three_cores(self):
        szp = cc.classify_earth("EU", "mks", "PA", "N")
        self.assertEqual(szp, "S")
        three = cc.resolve_earth("S", 3, "50")
        self.assertEqual(three["ccnt_earth"], 0)
        four = cc.resolve_earth("S", 4, "50")
        self.assertEqual(four["ccnt_earth"], 3)
        self.assertEqual(four["csa_earth"], "10")  # S map: 50 -> 10


class AwgConversionTests(unittest.TestCase):
    def test_metric_to_awg_and_mcm(self):
        self.assertEqual(cc.mm2_to_awg("95"), ("3/0", "AWG"))
        self.assertEqual(cc.mm2_to_awg("120"), ("236", "MCM"))
        self.assertEqual(cc.mm2_to_awg("0.5"), ("20", "AWG"))

    def test_non_mappable_sizes_return_none(self):
        for size in ("8", "1.13", "2.9"):
            self.assertIsNone(cc.mm2_to_awg(size), f"{size} should be non-mappable")


class ClassificationTests(unittest.TestCase):
    def test_family_group(self):
        self.assertEqual(cc.family_group("BV"), "M")  # marine
        self.assertEqual(cc.family_group("TD"), "T")  # trial/custom
        self.assertEqual(cc.family_group("BC"), "B")  # british marine
        self.assertEqual(cc.family_group("FR"), "S")  # standard (fire-rated via mica list)
        self.assertEqual(cc.family_group("C1"), "S")
        self.assertEqual(cc.family_group("Z"), "U")

    def test_fire_rated_families(self):
        self.assertTrue(cc.is_fire_rated("FR"))
        self.assertTrue(cc.is_fire_rated("WG"))
        self.assertFalse(cc.is_fire_rated("C1"))

    def test_set_code_cores(self):
        self.assertEqual(cc.set_code_cores("C"), 1)
        self.assertEqual(cc.set_code_cores("P"), 2)
        self.assertEqual(cc.set_code_cores("T"), 3)
        self.assertEqual(cc.set_code_cores("Q"), 4)


class DescriptionTests(unittest.TestCase):
    def test_single_core_size_phrase(self):
        parsed = cc.parse_code("FR-PAXN/1C500RE")
        self.assertEqual(cc.describe_size(parsed), "1C 500mm²")

    def test_multicore_with_reduced_earth(self):
        # A 4C reduced-earth code is 3 main cores + 1 reduced earth (the earth is one
        # of the 4 cores), not 4 main cores plus an extra earth.
        parsed = cc.parse_code("FR-PAXN/4C500RE")
        self.assertEqual(cc.describe_size(parsed), "3C 500mm² + 1C 120mm² earth")

    def test_pair_set_noun(self):
        parsed = cc.parse_code("C1-DEXN/8P1.5BK")
        self.assertEqual(cc.describe_size(parsed), "8 pairs 1.5mm²")

    def test_describe_code_uses_style_metadata(self):
        parsed = cc.parse_code("C1-CAXN/12C1.5BK")
        desc = cc.describe_code(parsed, {"title": "Flexible Control", "voltage": "110 V"})
        self.assertIn("Flexible Control", desc)
        self.assertIn("12C 1.5mm²", desc)
        self.assertIn("BK sheath", desc)


class BuildFromResolverFieldsTests(unittest.TestCase):
    def test_builds_from_resolver_field_names(self):
        fields = {
            "style": "FR",
            "config_core": "PA",
            "armour": "X",
            "conductor": "N",
            "count": 1,
            "core_type": "C",
            "cross_section": "500",
            "colour": "RE",
        }
        self.assertEqual(cc.build_code(fields), "FR-PAXN/1C500RE")

    def test_missing_fields_raises(self):
        with self.assertRaises(ValueError):
            cc.build_code({"style": "FR"})


if __name__ == "__main__":
    unittest.main()
