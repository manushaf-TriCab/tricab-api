# Generated from the RFQ parser's tests/test_accessory_text.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Reading an accessory out of the words a customer typed.

The catalogue fixture below is hand-written, and that is a stated limitation rather than
a convenience. ACCESSORY_AND_AGENT_READINESS_ORDER.md §1.2: the ERP database is not
reachable from this machine (`TRICAB_DB_HOST=replace-with-host`, and `10.1.0.4` is an
Azure private address), so `scripts/export_accessory_engine.py` has never been run and
there is no `accessory_specs` snapshot. The order permits building and unit-testing
§2.1 and §2.2 against a fixture and says explicitly that §2.3's corpus validation cannot
be reported as passing. These rows are real code shapes taken from the order's own
worked examples; they are not the real table.
"""

from __future__ import annotations

import json
import unittest
from pathlib import Path
from tempfile import TemporaryDirectory

from tricab_cable_rules.accessory_text import (
    build_accessory_code,
    dimension_pair,
    is_accessory_request,
    load_specs,
    parse_dimensions,
    rank_families,
    read_accessory_line,
    score_styles,
    stated_style,
)


# Codes from §2.0's round-trip and §2.3's worked rejection. B20 in 16mm2 is deliberately
# present only at 6mm and 8mm stud: the order's example asks for 10mm, which is not made.
FIXTURE_CODES = [
    "B06-AJXX/12H120",     # 120M12 lug
    "B06-ACXX/10H300",     # form-factor pair, standard
    "B06-ACXL/10H300",     # form-factor pair, long
    "B20-AGXX/6H16",
    "B20-AGXX/8H16",
    "B21-ABXX/400J240",    # reducing link, cross-section to cross-section
    "B22-AGXX/12H185",     # bi-metal stalk
    "A01-AMXX/10B1.5BK",   # ferrule, black
    "D01-AGXX/32F25",      # brass gland
    "D11-AGXX/32F25",      # EMC gland, same dimensions
]


def write_fixture(directory: str) -> str:
    path = Path(directory) / "accessory_engine_snapshot.json"
    path.write_text(
        json.dumps({"accessory_specs": [{"product_code": code} for code in FIXTURE_CODES]}),
        encoding="utf-8",
    )
    return str(path)


class StyleScoringTests(unittest.TestCase):
    """§2.2: text -> style, and the two failures the order names by hand."""

    def test_a_discriminator_is_not_outvoted_by_generic_words(self):
        # "brass nickel plated" contributes three tokens and "emc" one, so plain term
        # frequency puts the standard brass gland first. EMC is a claim about which
        # gland, not an adjective, so a style that is not EMC is wrong rather than weaker.
        self.assertEqual(score_styles("EMC gland brass nickel plated")[0].style, "D11")

    def test_cable_attributes_do_not_score_against_accessory_names(self):
        # SWA describes the cable the gland is for. Scored against gland names it picks
        # the Ex-rated SWA gland over the plain brass one the line actually asks for.
        self.assertEqual(score_styles("1 x brass gland M32 for 4C 16mm2 SWA")[0].style, "D01")

    def test_the_family_is_chosen_before_the_style(self):
        # "lug" is universal among lugs and so carries no weight there, while it is rare
        # among plugs and sockets -- three "Lug Converter" parts out of forty-eight -- so
        # a single pooled ranking of family-local scores sends this line to J59.
        self.assertEqual(rank_families("120M12 Lug")[0][0], "B")
        self.assertTrue(score_styles("120M12 Lug")[0].style.startswith("B"))

    def test_a_line_with_no_accessory_in_it_scores_nothing(self):
        self.assertEqual(score_styles("4C 16mm2 XLPE SWA cable, 100m"), [])

    def test_a_style_the_customer_named_outright_wins(self):
        # 9% of corpus accessory lines carry the style. Scored on words alone this line
        # goes to G08 on "glue lined", against the G03 the customer actually wrote.
        self.assertEqual(stated_style("G03 Heat Shrink Glue Lined Black"), "G03")
        self.assertEqual(read_accessory_line("G03 Heat Shrink Glue Lined Black").style, "G03")

    def test_a_stud_size_is_not_read_as_a_style_code(self):
        # M10/M12/M16 are studs. The M series is excluded from stated-style detection for
        # exactly this reason -- "120M12 Lug" as style M12 loses conductor and stud both.
        self.assertIsNone(stated_style("120M12 Lug"))
        self.assertIsNone(stated_style("1 x brass gland M32 for 4C 16mm2 SWA"))


class DimensionParsingTests(unittest.TestCase):
    """§2.2: text -> dimensions, per noun, including the two compressed spellings."""

    def test_compressed_lug_notation(self):
        self.assertEqual(parse_dimensions("120M12 Lug", "B"), {"csa": 120.0, "stud": 12.0})

    def test_competitor_part_number_digits_are_the_dimensions(self):
        # 14% of file-corpus lug lines arrive as a competitor part number.
        self.assertEqual(parse_dimensions("CAL120-12_CAB", "B"), {"csa": 120.0, "stud": 12.0})

    def test_two_bare_millimetre_values_on_a_lug(self):
        # Neither number carries mm2. A lug's barrel takes a conductor larger than the
        # stud it bolts to, so the larger is the conductor.
        self.assertEqual(
            parse_dimensions("12 x 16mm bi-metallic lug, 10mm", "B"),
            {"csa": 16.0, "stud": 10.0},
        )

    def test_a_ferrule_carries_a_length_a_conductor_and_a_colour(self):
        self.assertEqual(
            parse_dimensions("10mm x 1.5mm2 Black", "A"),
            {"csa": 1.5, "length": 10.0, "colour": "BK"},
        )

    def test_a_reducing_link_states_conductor_to_conductor(self):
        parsed = parse_dimensions("400mm2 reducing link to 240mm2", "B")
        self.assertEqual(parsed, {"csa": 400.0, "csa_to": 240.0})
        self.assertEqual(dimension_pair(parsed, "B"), ("J", 400.0, 240.0))

    def test_a_tie_width_arrives_without_its_unit(self):
        # "4.8 x 300mm" gives the unit once, at the end.
        self.assertEqual(
            parse_dimensions("4.8 x 300mm cable tie black", "W"),
            {"width": 4.8, "length": 300.0, "colour": "BK"},
        )

    def test_a_missing_stud_is_absent_rather_than_defaulted(self):
        parsed = parse_dimensions("185mm2 bi-metallic stalk lug", "B")
        self.assertNotIn("stud", parsed)


class CodeConstructionTests(unittest.TestCase):
    """§2.1: the grammar."""

    def test_the_round_tripped_examples_assemble(self):
        self.assertEqual(
            build_accessory_code("B06", "H", 12, 120, pack_code="J"), "B06-AJXX/12H120"
        )
        self.assertEqual(
            build_accessory_code("A01", "B", 10, 1.5, pack_code="M", colour="BK"),
            "A01-AMXX/10B1.5BK",
        )
        self.assertEqual(
            build_accessory_code("B21", "J", 400, 240, pack_code="B"), "B21-ABXX/400J240"
        )

    def test_a_dimension_never_carries_a_trailing_zero(self):
        self.assertEqual(build_accessory_code("B06", "H", 12.0, 120.0, pack_code="J"),
                         "B06-AJXX/12H120")


class RequestDetectionTests(unittest.TestCase):
    """§6's trap: not every accessory noun is a request."""

    def test_the_customer_describing_their_own_quoting_is_not_a_request(self):
        self.assertFalse(is_accessory_request("I will be quoting glands to suit"))
        self.assertEqual(
            read_accessory_line("I will be quoting glands to suit").status, "not_a_request"
        )

    def test_an_excluded_item_is_not_a_request(self):
        self.assertFalse(is_accessory_request("Lugs by others"))

    def test_a_real_to_suit_line_is_still_a_request(self):
        self.assertTrue(is_accessory_request("Bi-Metallic stalk lug to suit 185mm2 AL"))


class ReadingAgainstTheCatalogueTests(unittest.TestCase):
    """§2.3: validate, and let it fail honestly."""

    def setUp(self):
        load_specs.cache_clear()
        self._directory = TemporaryDirectory()
        self.addCleanup(self._directory.cleanup)
        self.addCleanup(load_specs.cache_clear)
        self.path = write_fixture(self._directory.name)

    def test_a_complete_line_resolves_to_a_real_code(self):
        reading = read_accessory_line("120M12 Lug", path=self.path)
        self.assertEqual(reading.status, "matched")
        self.assertEqual(reading.product_code, "B06-AJXX/12H120")

    def test_existence_decides_between_styles_the_words_cannot_separate(self):
        # "bi-metallic" fits B20, B21 and B22 equally. Only B22 is made at 12mm stud on
        # a 185mm2 conductor, and that is a fact about the range, not a preference.
        reading = read_accessory_line("bi-metallic stalk lug 185mm2 M12", path=self.path)
        self.assertEqual(reading.status, "matched")
        self.assertEqual(reading.product_code, "B22-AGXX/12H185")

    def test_a_product_that_is_not_made_is_reported_with_what_is(self):
        reading = read_accessory_line("12 x 16mm bi-metallic lug, 10mm", path=self.path)
        self.assertEqual(reading.status, "no_such_product")
        self.assertIsNone(reading.product_code)
        self.assertIn("6", reading.note)
        self.assertIn("8", reading.note)

    def test_an_unstated_stud_size_is_named_rather_than_guessed(self):
        reading = read_accessory_line("185mm2 bi-metallic stalk lug", path=self.path)
        self.assertEqual(reading.missing, ["stud"])
        self.assertIsNone(reading.product_code)
        self.assertIn("stud size", reading.note)

    def test_form_factor_is_surfaced_as_two_candidates(self):
        # Every dimension supplied and the code still does not narrow to one product.
        reading = read_accessory_line("Cu Bell Mouth Lug - 300mm2 - M10", path=self.path)
        self.assertEqual(reading.status, "ambiguous")
        self.assertEqual(reading.codes, ["B06-ACXL/10H300", "B06-ACXX/10H300"])
        self.assertIsNone(reading.product_code)

    def test_a_ferrule_keeps_its_colour(self):
        reading = read_accessory_line("10mm x 1.5mm2 Black ferrule", path=self.path)
        self.assertEqual(reading.status, "matched")
        self.assertEqual(reading.product_code, "A01-AMXX/10B1.5BK")


class WithoutASnapshotTests(unittest.TestCase):
    """The state this machine is actually in, which must not read as success."""

    def setUp(self):
        load_specs.cache_clear()
        self.addCleanup(load_specs.cache_clear)

    def test_a_parsed_line_is_unvalidated_rather_than_matched(self):
        reading = read_accessory_line("120M12 Lug", path="/nonexistent/snapshot.json")
        self.assertNotEqual(reading.status, "matched")
        self.assertIsNone(reading.product_code)
        self.assertIn("no accessory_specs snapshot", reading.note)


if __name__ == "__main__":
    unittest.main()


class SilentCableMisquoteTests(unittest.TestCase):
    """A reducing link read as 400mm2 of cable, which is worse than a dropped row."""

    def test_a_reducing_link_is_not_quoted_as_cable(self):
        from rfq_parser.pipeline import match_source_line

        row = match_source_line(
            "400mm2 reducing link to 240mm2", {"line_number": 1}, allow_llm_fallback=False
        )[0]
        # Was XL-PAXN/1C400OR, `matched`, with nothing on the row to say it was wrong.
        self.assertEqual(row.get("line_kind"), "accessory")
        self.assertNotEqual(row.get("selected_product_code"), "XL-PAXN/1C400OR")

    def test_prose_asking_for_a_link_is_not_an_accessory(self):
        # Why bare "links?" stays out of the vocabulary: this line is in the file corpus.
        from tricab_cable_rules.extractor import ACCESSORY_RE

        self.assertIsNone(
            ACCESSORY_RE.search("If you answered YES, provide link or a copy of your statement:")
        )


# --------------------------------------------------------------------------------------
# The four defects of ACCESSORY_SNAPSHOT_FIRST_RUN.md, against the real catalogue
# --------------------------------------------------------------------------------------

from tricab_cable_rules.accessory_text import (  # noqa: E402
    find_codes_by_first_dimension,
    is_base_variant,
    specs_available,
)


class SpecifierOrderTests(unittest.TestCase):
    """§2: the pair a code needs, in the order the code writes it. No catalogue needed."""

    def test_a_tie_is_written_width_first(self):
        # W01-AMXX/4.8M250 is 4.8mm wide and 250mm long. The customer writes the larger
        # number first -- "250mm x 4.8mm" -- so source order is not the code's order.
        parsed = parse_dimensions("Cable tie - 250mm x 4.8mm", "W")
        self.assertEqual(parsed, {"width": 4.8, "length": 250.0})
        self.assertEqual(dimension_pair(parsed, "W"), ("M", 4.8, 250.0))

    def test_the_other_specifiers_keep_the_order_the_catalogue_uses(self):
        # Audited against the snapshot, one specifier at a time. Only M was inverted.
        self.assertEqual(dimension_pair({"length": 10.0, "csa": 1.5}, "A"), ("B", 10.0, 1.5))
        self.assertEqual(dimension_pair({"stud": 8.0, "csa": 120.0}, "B"), ("H", 8.0, 120.0))
        self.assertEqual(dimension_pair({"csa": 400.0, "csa_to": 240.0}, "B"), ("J", 400.0, 240.0))
        self.assertEqual(
            dimension_pair({"thread": 32.0, "outside_diameter": 25.0}, "D"), ("F", 32.0, 25.0)
        )
        self.assertEqual(
            dimension_pair({"length": 1.2, "diameter": 10.0}, "G"), ("D", 1.2, 10.0)
        )


class BareMillimetreDimensionTests(unittest.TestCase):
    """§1: a plain ``NNmm`` is a dimension, not noise."""

    def test_a_lone_bare_millimetre_beside_a_stated_stud_is_the_conductor(self):
        # "300mm" is the conductor and "M10" the stud. The stud is explicit, so the one
        # remaining value is the conductor whatever its size -- this does not rely on the
        # larger-of-two rule that the two-bare-value case uses.
        self.assertEqual(
            parse_dimensions("Cu Bell Mouth Lug - 300mm - M10", "B"),
            {"stud": 10.0, "csa": 300.0},
        )

    def test_a_dimension_written_twice_is_not_read_as_two(self):
        self.assertEqual(parse_dimensions("Lug M10, 10mm stud", "B"), {"stud": 10.0})

    def test_a_gland_states_its_thread_not_its_cable_diameter(self):
        # A gland is ordered by entry thread. Reading "32mm" as the cable outside diameter
        # lost the one dimension the line actually gave.
        self.assertEqual(parse_dimensions("32mm cable gland Brass", "D"), {"thread": 32.0})

    def test_an_explicit_thread_leaves_the_bare_value_as_the_diameter(self):
        self.assertEqual(
            parse_dimensions("brass gland M32 to suit 25mm OD", "D"),
            {"thread": 32.0, "outside_diameter": 25.0},
        )

    def test_a_cable_attribute_is_still_not_a_gland_dimension(self):
        # "16mm2" is the cable the gland is for. It must not become the thread.
        self.assertEqual(
            parse_dimensions("1 x brass gland M32 for 4C 16mm2 SWA", "D"), {"thread": 32.0}
        )


class BaseVariantTests(unittest.TestCase):
    """§4: which member of a subgroup a line that names no variant is asking for."""

    def test_the_register_names_the_base_variant(self):
        self.assertTrue(is_base_variant("Nylon Standard"))
        self.assertTrue(is_base_variant("Brass Standard"))
        self.assertFalse(is_base_variant("Nylon Long Thread"))
        self.assertFalse(is_base_variant("Brass EMC"))

    def test_a_raw_erp_name_is_not_evidence_of_a_placement(self):
        # D21 has no curated label and its ERP name is "Standard Nylon Gland". Reading
        # that as a base variant put two bases in one tie and stopped it resolving.
        self.assertFalse(is_base_variant(None))
        self.assertFalse(is_base_variant(""))

    def test_the_lowest_code_is_not_the_base_variant_inside_every_subgroup(self):
        # The convention held across brass (D01 < D06 < D11) and failed across nylon:
        # D03 is Nylon Long Thread and D08 is Nylon Standard.
        candidates = {candidate.style: candidate for candidate in score_styles("20mm Nylon Gland")}
        self.assertIn("D08", candidates)
        self.assertTrue(candidates["D08"].base_variant)
        self.assertFalse(candidates.get("D03").base_variant)


@unittest.skipUnless(
    specs_available(),
    "No accessory_engine_snapshot.json on this machine -- see ACCESSORY_SNAPSHOT_FIRST_RUN.md §5.1",
)
class RealCatalogueReadingTests(unittest.TestCase):
    """The lines of ACCESSORY_SNAPSHOT_FIRST_RUN.md, read against the real snapshot.

    Not a fixture. These assert against ``data/catalog/accessory_engine_snapshot.json``,
    which is why they skip rather than fail where it is absent -- the file is 6.6 MB and
    whether it belongs in git is still open (§5.1). A skip here is that decision showing
    itself, not a passing test.
    """

    def test_a_lug_reads_its_conductor_from_a_bare_millimetre(self):
        reading = read_accessory_line("Cu Bell Mouth Lug - 300mm - M10")
        self.assertEqual(reading.style, "B06")
        self.assertEqual(reading.dimensions.get("csa"), 300.0)
        # Both dimensions are stated and two form factors remain, which is the honest
        # answer rather than the "does not state conductor size" it used to give.
        self.assertEqual(reading.codes, ["B06-ACXL/10H300", "B06-ACXX/10H300"])

    def test_a_gland_stating_only_its_thread_resolves(self):
        reading = read_accessory_line("32mm cable gland Brass")
        self.assertEqual(reading.status, "matched")
        self.assertEqual(reading.product_code, "D01-AGXX/32F25")

    def test_a_nylon_gland_gets_the_standard_variant_not_the_long_thread_one(self):
        reading = read_accessory_line("20mm Nylon Gland")
        self.assertEqual(reading.status, "matched")
        self.assertEqual(reading.product_code, "D08-AKXX/20F14")

    def test_a_tie_builds_the_code_the_catalogue_writes(self):
        reading = read_accessory_line("Cable tie - 250mm x 4.8mm")
        self.assertNotEqual(reading.status, "no_such_product")
        self.assertIn("W01-AMXX/4.8M250", reading.codes)

    def test_the_existence_tiebreak_cannot_change_the_style_the_words_chose(self):
        # B20 scores 2.67 and B01..B30 score zero. B20 in 16mm2 is made at 6mm and 8mm
        # stud only, so a 10mm stud is a no_such_product -- not seven codes from styles
        # the words ranked bottom, reported beside a style that made none of them.
        reading = read_accessory_line("12 x 16mm bi-metallic lug, 10mm")
        self.assertEqual(reading.status, "no_such_product")
        self.assertEqual(reading.style, "B20")
        self.assertEqual(reading.codes, [])
        self.assertIn("6", reading.note)
        self.assertIn("8", reading.note)

    def test_a_reported_code_always_belongs_to_the_reported_style(self):
        # The invariant behind §3: a consumer must never have to decide which half of
        # "style=B20 code=B01-AKXX/10H16" is the wrong one.
        lines = [
            "Cu Bell Mouth Lug - 300mm - M10",
            "32mm cable gland Brass",
            "20mm Nylon Gland",
            "12 x 16mm bi-metallic lug, 10mm",
            "120 mm2 Cu Lug 8mm Stud Circuit Breaker",
            "10mm x 1.5mm2 Black Bootlace/Ferrule, Single",
            "400mm2 Bi Metal Reducing Link to 240mm2",
        ]
        for line in lines:
            with self.subTest(line=line):
                reading = read_accessory_line(line)
                for code in reading.codes:
                    self.assertEqual(code.split("-")[0], reading.style)

    def test_the_shape_filter_narrows_a_tie_and_never_promotes_past_it(self):
        # F20/F21 "Split Loom" score 1.739 and are made only in the D shape; the F shape
        # this family asks for matches X04 "Bushings" at 0.357. A style the words ranked
        # far lower is not the answer, so the line goes back unresolved.
        reading = read_accessory_line("split loom 20mm 6 ea")
        self.assertEqual(reading.codes, [])
        self.assertNotEqual(reading.style, "X04")

    def test_a_bare_noun_still_reaches_the_whole_family(self):
        # §5's not-a-defect: several styles really are made in 12H120 and the line says
        # nothing to choose between them. Reporting the ambiguity is correct.
        reading = read_accessory_line("120M12 Lug")
        self.assertEqual(reading.status, "ambiguous")
        self.assertIn("B06-AJXX/12H120", reading.codes)

    def test_the_three_lines_that_already_resolved_still_do(self):
        for line, expected in [
            ("120 mm2 Cu Lug 8mm Stud Circuit Breaker", "B12-AJXX/8H120"),
            ("10mm x 1.5mm2 Black Bootlace/Ferrule, Single", "A01-AMXX/10B1.5BK"),
            ("400mm2 Bi Metal Reducing Link to 240mm2", "B21-ABXX/400J240"),
        ]:
            with self.subTest(line=line):
                reading = read_accessory_line(line)
                self.assertEqual(reading.status, "matched")
                self.assertEqual(reading.product_code, expected)

    def test_one_dimension_resolves_only_where_the_catalogue_leaves_no_question(self):
        # The lookup narrows; it never chooses. D06 makes two products at a 32mm thread,
        # so a line naming it could not be answered from the thread alone.
        self.assertEqual(find_codes_by_first_dimension("D01", "F", 32), ["D01-AGXX/32F25"])
        self.assertEqual(
            find_codes_by_first_dimension("D06", "F", 32),
            ["D06-AGXX/32F22", "D06-AGXX/32F25"],
        )
