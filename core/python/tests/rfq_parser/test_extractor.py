# Generated from the RFQ parser's tests/test_extractor.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
import unittest

from tricab_cable_rules.extractor import (
    CABLE_SIGNAL_RE,
    extract_entities,
    insulation_designation,
    mentions_high_temp,
    pair_circuit_label_lines,
)


class ExtractQuantityTests(unittest.TestCase):
    def test_ampersand_core_and_earth_is_normalized_to_plus(self):
        extracted = extract_entities("25mm 4c&e Radox 341", set())
        fields = extracted["fields"]

        self.assertIn("4c+e", extracted["normalized_text"].lower())
        self.assertEqual(fields["raw_core_expression"].upper(), "4C+E")
        # TriCab counts the earth as a core: "4C + E" is a 5C cable.
        self.assertEqual(fields["count"], 5)
        self.assertEqual(fields["core_type"], "C")
        self.assertTrue(fields["earth"])
        self.assertEqual(fields["cross_section"], "25")
        self.assertEqual(fields["length_each_m"], 341)

    def test_spaced_ampersand_core_and_earth_is_normalized(self):
        fields = extract_entities("16mm 4C & E Radox 10", set())["fields"]

        self.assertEqual(fields["count"], 5)
        self.assertTrue(fields["earth"])

    def test_slash_separated_earth_counts_as_a_core(self):
        # "4C/E" is the same cable as "4C+E". The slash form used to reach the
        # extractor untouched, so CORE_PLUS_E_RE missed it and the earth was
        # dropped -- a 5C cable silently quoted as a 4C.
        for text in ("4C/E 16mm2 Cu XLPE/PVC", "4C/EARTH 16mm2", "4 core/earth 16mm2"):
            fields = extract_entities(text, set())["fields"]
            self.assertEqual(fields["count"], 5, text)
            self.assertEqual(fields["core_type"], "C", text)
            self.assertTrue(fields["earth"], text)

    def test_slash_earth_does_not_match_a_construction_string(self):
        # The "/E" rule must not fire on slash-separated construction ("XLPE/PVC")
        # -- the digit before the C is what keeps them apart.
        fields = extract_entities("4C 240mm2 CU XLPE/SWA/PVC", set())["fields"]

        self.assertEqual(fields["count"], 4)
        self.assertNotEqual(fields.get("earth"), True)

    def test_earth_wire_abbreviation_is_an_earth(self):
        # "E/W" is trade shorthand for "earth wire".
        fields = extract_entities("4C + E/W 16mm2 Cu XLPE/PVC", set())["fields"]
        self.assertEqual(fields["count"], 5)
        self.assertTrue(fields["earth"])

        # ... including when it is the whole earth marker on a 1C run.
        fields = extract_entities("1C 16mm2 E/W", set())["fields"]
        self.assertTrue(fields["earth"])

        # ... and stacked with the slash form.
        fields = extract_entities("4C/E/W 16mm2", set())["fields"]
        self.assertEqual(fields["count"], 5)
        self.assertTrue(fields["earth"])

    def test_bare_trailing_e_after_a_size_is_an_earth(self):
        # An earth-size column / earth summary row writes the earth as a bare "E"
        # after the size ("120mm2 E"). Without it the row read as an ordinary
        # single-core power cable: XL rather than LP, colour OR rather than EA.
        for text in (
            "120mm\u00b2 E",
            "120mm2 E",
            "1C 120mm2 E",
            "120mm2 E Cu",
            "16 sq mm E",
        ):
            fields = extract_entities(text, set())["fields"]
            self.assertTrue(fields["earth"], text)
            self.assertTrue(fields["earth_only"], text)
            self.assertEqual(fields["count"], 1, text)
            self.assertEqual(fields["core_type"], "C", text)

    def test_the_conductor_word_may_sit_on_either_side_of_the_e(self):
        # "95mm Cu E" is the ordering people actually write, and the first version of
        # BARE_EARTH_SIZE_RE only accepted the conductor word *after* the E -- so the
        # commoner form went straight through as a power cable.
        for text in (
            "95mm Cu E",
            "1C 95mm Cu E",
            "1C 95mm2 CU E",
            "1C 70mm TC E",
            "35 sq mm Cu E",
            "95mm2 E Cu",
        ):
            fields = extract_entities(text, set())["fields"]
            self.assertTrue(fields["earth"], text)
            self.assertTrue(fields["earth_only"], text)
            self.assertEqual(fields["count"], 1, text)

    def test_bare_trailing_e_needs_a_size_and_a_single_conductor(self):
        # A stated multicore keeps the explicit "+E" requirement: there the "E" is
        # the added-core shorthand (4C+E is a 5C cable), not a colour marker on a
        # 4C, so reading it here would quote the wrong cable.
        for text in (
            "4C 16mm2 E",
            "2C 6mm2 E",
            # The conductor word before the E must not smuggle a multicore past the
            # count guard either.
            "4C 16mm2 Cu E",
            "8 pair 1.5mm2 E",
            # A conductor word alone is not an earth marker.
            "1C 95mm2 Cu",
            "1C 95mm2 Cu XLPE",
            "1C 95mm2 Cu PE",
            "1C 95mm2 Cu E90",
            # "E" that belongs to the words after it, or to no size at all.
            "120mm2 EPR",
            "120mm2 E-glass",
            "Rev E 120mm2 XLPE 4C",
            "120mm2 E 240mm2",
        ):
            fields = extract_entities(text, set())["fields"]
            self.assertNotEqual(fields.get("earth"), True, text)
            self.assertNotEqual(fields.get("earth_only"), True, text)

    def test_worded_core_and_earth_glues_count_regardless_of_separator(self):
        # "4-core and earth" / "4 core and earth" must reach the extractor as the
        # glued "4C+E" form, or the count is stranded and the cable silently
        # collapses to 1C (a hyphen or space between the digit and "core" used to
        # do exactly that).
        for text in (
            "40 metres of 16 mm2 4-core and earth",
            "40 metres of 16 mm2 4 core and earth",
        ):
            fields = extract_entities(text, set())["fields"]
            self.assertEqual(fields["count"], 5, text)
            self.assertEqual(fields["core_type"], "C", text)
            self.assertTrue(fields["earth"], text)

    def test_trailing_bare_number_is_length(self):
        fields = extract_entities("FR 110° CU 1C 70mm2 10548", set())["fields"]

        self.assertEqual(fields["length_each_m"], 10548)
        self.assertEqual(fields["number_of_lengths"], 1)
        self.assertEqual(fields["total_length_m"], 10548)
        self.assertEqual(fields["uom"], "m")

    def test_pipe_separated_trailing_bare_number_is_length(self):
        fields = extract_entities("FR 110° CU 4C+E 25mm2 | 1150", set())["fields"]

        self.assertEqual(fields["length_each_m"], 1150)
        self.assertEqual(fields["total_length_m"], 1150)

    def test_explicit_length_still_takes_precedence(self):
        fields = extract_entities("FR 110° CU 1C 70mm2 30m", set())["fields"]

        self.assertEqual(fields["length_each_m"], 30)

    def test_cable_description_without_trailing_number_has_no_length(self):
        fields = extract_entities("FR 110° CU 1C 70mm2", set())["fields"]

        self.assertNotIn("length_each_m", fields)

    def test_real_degree_symbol_is_normalized_as_temperature(self):
        extracted = extract_entities("1C 70mm 110\u00b0 SDI XLPE/PVC", set())
        fields = extracted["fields"]

        self.assertIn("110 deg", extracted["normalized_text"])
        self.assertTrue(mentions_high_temp("1C 70mm 110\u00b0 SDI XLPE/PVC"))
        self.assertEqual(fields["count"], 1)
        self.assertEqual(fields["cross_section"], "70")
        self.assertTrue(fields.get("pvc"))

    def test_ampersand_earth_word_counts_as_core_plus_earth(self):
        fields = extract_entities("4C & Earth 35mm 110\u00b0 XLPE/PVC", set())["fields"]

        self.assertEqual(fields["count"], 5)
        self.assertTrue(fields["earth"])
        self.assertEqual(fields["cross_section"], "35")


class AddedEarthCoreTests(unittest.TestCase):
    def test_non_adjacent_added_earth_becomes_an_extra_core(self):
        # "4C ... + EARTH" with the earth separated from the core count is still the
        # cable's own earth -> counted as an added core (4C -> 5C).
        fields = extract_entities("4C 70mm Al XLPE90 + EARTH", set())["fields"]

        self.assertEqual(fields["count"], 5)
        self.assertTrue(fields["earth"])
        self.assertTrue(fields["earth_added_core"])

    def test_adjacent_core_plus_e_is_not_double_counted(self):
        fields = extract_entities("4C+E 70mm2 Cu XLPE", set())["fields"]

        self.assertEqual(fields["count"], 5)
        self.assertNotIn("earth_added_core", fields)

    def test_g_notation_earth_is_not_double_counted(self):
        # "4G70" (IEC) already includes the green/yellow earth in the 4 cores.
        fields = extract_entities("4G70 Cu XLPE", set())["fields"]

        self.assertEqual(fields["count"], 4)
        self.assertNotIn("earth_added_core", fields)

    def test_bare_earth_without_plus_is_not_promoted(self):
        # A standalone earth conductor ("1C 25mm Cu EARTH", no '+') is an earth-only
        # run, not a phase + earth: the count stays 1.
        fields = extract_entities("1C 25mm Cu EARTH", set())["fields"]

        self.assertEqual(fields["count"], 1)
        self.assertNotIn("earth_added_core", fields)

    def test_multicore_added_earth(self):
        fields = extract_entities("Control 12C 1.5mm Cu + E PVC", set())["fields"]

        self.assertEqual(fields["count"], 13)
        self.assertTrue(fields["earth_added_core"])


class InsulationCodeTests(unittest.TestCase):
    def test_r_hf_110_insulation_flags_lszh(self):
        fields = extract_entities("4C + E 16mm² Copper R-HF-110 HF-110-R", set())["fields"]

        self.assertTrue(fields.get("lszh"))

    def test_x_hf_110_is_high_temp_not_lszh(self):
        fields = extract_entities(
            "1 x 200m roll of 1mm2 LSFLEX125 switchboard X-HF - 110 0.6/1KV - Orange",
            set(),
        )["fields"]

        self.assertNotIn("lszh", fields)
        self.assertEqual(fields["colour"], "OR")
        self.assertTrue(fields["switchboard"])
        self.assertEqual(fields["cable_type_hint"], "Building Wire")

    def test_xlpe_pvc_codes_do_not_flag_lszh(self):
        fields = extract_entities("4C + E 16mm² Copper X-90 XLPE 5V-90 PVC", set())["fields"]

        self.assertNotIn("lszh", fields)
        self.assertTrue(fields.get("pvc"))


class PackQuantityAccessoryTests(unittest.TestCase):
    """A quantity counted in packs means accessory, never cable (audit 4.6)."""

    def _fields(self, text):
        return extract_entities(text, set())["fields"]

    def test_bagged_lines_are_accessories(self):
        for text in (
            "1 bag x Uninsulated lugs 120 x 8",
            "5 individual x Uninsulated lugs 240 x 12",
            "2 bags x inline splice connector",
            "1 bag x female bullet",
            "10 bags x 2.5m100bk",
        ):
            with self.subTest(text=text):
                self.assertTrue(self._fields(text).get("accessory_hint"))

    def test_crimp_vocabulary_is_not_a_cable_size(self):
        # These carried a size token, so they resolved as cable: "1 bag x 25mm2
        # single ferrule" came back XL-PAXN/1C25OR, `matched`.
        for text in (
            "1 bag x 25mm2 single ferrule",
            "2 bags x 6.4mm female spade",
            "1 bag x 6.4mm female piggy back spade (PB1.25-6.4 DG)",
            "2 bags x 5mm bullet male",
        ):
            with self.subTest(text=text):
                self.assertTrue(self._fields(text).get("accessory_hint"))

    def test_a_bagged_cable_is_still_a_cable(self):
        # The pack hint only applies with no cable construction evidence, so a
        # genuine cable that happens to be bagged keeps its cores and size.
        fields = self._fields("2 bags x 4C 6mm2 XLPE")

        self.assertIsNone(fields.get("accessory_hint"))
        self.assertEqual(fields.get("cross_section"), "6")
        self.assertEqual(fields.get("count"), 4)

    def test_rolls_and_drums_are_not_pack_units(self):
        # Cable IS sold by the roll and the drum; those must not become
        # accessories.
        self.assertIsNone(self._fields("2 rolls x 100m 4C 6mm2").get("accessory_hint"))
        self.assertIsNone(self._fields("1 drum x 500m 1C 95mm2").get("accessory_hint"))


class CircuitTagKeywordTests(unittest.TestCase):
    """A destination name must not steer style selection (audit 4.4).

    MacKillop's "DB-JNL-GF.1 > DB-SOLAR XLPE Cu 25mm2" resolved to the solar
    style while its twelve sibling rows went to XL-PAXN, and the aluminium twin
    came back `matched`, so nothing flagged it.
    """

    def _hint(self, text):
        return extract_entities(text, set())["fields"].get("cable_type_hint")

    def test_board_name_does_not_set_a_style(self):
        for text in (
            "DB-JNL-GF.1 > DB-SOLAR XLPE Cu 25mm2",
            "DB-JNL-GF.1 > DB-SOLAR AL Flex 70mm2",
            "MDB > DB-PV Cu XLPE 70mm2",
            "MSSB-CONTROL-1 XLPE Cu 4mm2",
            "DB-FIRE-2 XLPE Cu 16mm2",
        ):
            with self.subTest(text=text):
                self.assertIsNone(self._hint(text))

    def test_genuine_mentions_still_select_the_style(self):
        for text, expected in (
            ("Solar cable 1C 6mm2", "Solar"),
            ("PV solar 4mm2", "Solar"),
            ("1C 6mm2 PV cable", "Solar"),
            ("Control cable 7C 1.5mm2", "Control"),
            ("Fire rated 2C 2.5mm2", "Fire Resistant"),
        ):
            with self.subTest(text=text):
                self.assertEqual(self._hint(text), expected)

    def test_slash_separates_construction_layers_not_tags(self):
        # "/" joins layers in a construction spec, where the keyword is real.
        # Treating it as a tag joiner silently suppressed the fire flag on ten
        # armoured instrument lines and re-styled them as `matched`.
        self.assertTrue(extract_entities("10PR x 1.5mm PVC/SWA/PVC/ESCS/FR", set())["fields"].get("fire"))
        self.assertEqual(self._hint("3C + 3E VSD/EMC 120mm2 X90/PVC"), "VSD/VFD")

    def test_trailing_hyphen_is_ordinary_spec_grammar(self):
        self.assertEqual(self._hint("Fire-rated 2C 2.5mm2"), "Fire Resistant")


class StyleHintTests(unittest.TestCase):
    def test_flat_sets_fd_style_hint(self):
        fields = extract_entities("2C 2.5mm flat cable 100m", set())["fields"]

        self.assertEqual(fields["style_hint"], "FD")

    def test_lszh_flexible_termite_sets_cd_t_hint(self):
        fields = extract_entities("XLPE/PVC HF110 LSZH FLEXIBLE CLASS 5 TERMITE NYLON 1C 185mm", set())["fields"]

        self.assertEqual(fields["style_hint"], "CD")
        self.assertEqual(fields["sheath"], "T")

    def test_termite_nylon_rodent_set_the_termite_flag(self):
        for word in ("termite", "nylon jacket", "rodent resistant"):
            fields = extract_entities(f"1C 95mm Cu {word}", set())["fields"]
            self.assertTrue(fields.get("termite"), msg=word)
        # A plain nylon line carries no "T" sheath suffix (that is the SDI XDT/KDT/CDT
        # family, driven by the CD combination rule, not a bare termite mention).
        plain = extract_entities("1C 95mm Cu nylon", set())["fields"]
        self.assertNotIn("sheath", plain)

    def test_swa_pvc_does_not_force_a_style_hint(self):
        # PVC + armour no longer forces style_hint=KA. The armoured construction is now
        # chosen by the resolver temperature ladder (no temp/90 -> XA, 110 -> KA,
        # >110/LSZH -> CA), confirmed with TriCab 2026-06-29. The extractor still detects
        # armour and PVC; it just doesn't pin the style.
        fields = extract_entities("4C 2.5MM2 (SWA) PVC SHEATHED (BK)-SWA-PVC INSULATED", set())["fields"]

        self.assertTrue(fields["armoured"])
        self.assertEqual(fields["armour_type"], "SWA")
        self.assertNotIn("armour", fields)
        self.assertTrue(fields["pvc"])
        self.assertNotIn("style_hint", fields)


class ScreenedVsdTests(unittest.TestCase):
    def test_multiple_earths_set_earth_count(self):
        # "+3E" is the multiple-earth form -> drives XU (multiple) over DP (single).
        # Split earths count as a single earth-core, so 3C+3E is a 4C cable (not 6C).
        fields = extract_entities("VSD 3C 35mm + 3E 0.6/1kV", set())["fields"]

        self.assertEqual(fields["earth_count"], 3)
        self.assertEqual(fields["count"], 4)
        self.assertTrue(fields["screen"])
        self.assertEqual(fields["cable_type_hint"], "VSD/VFD")
        self.assertEqual(fields["voltage_u"], 1000)

    def test_single_earth_sets_earth_count_one(self):
        fields = extract_entities("VSD 3C 35mm + E 600/1000V", set())["fields"]

        self.assertEqual(fields["earth_count"], 1)
        self.assertEqual(fields["count"], 4)

    def test_emc_routes_to_vsd_family_but_is_not_a_screen_signal(self):
        # EMC => VSD/VFD family (confirmed 2026-07-02): every VSD/VFD cable is an EMC
        # cable, so EMC alone routes the cable type into the screened-drive family.
        # It is still NOT a screen trigger -- screen construction must be stated.
        fields = extract_entities("EMC cable 4C 6mm Cu", set())["fields"]

        self.assertNotIn("screen", fields)
        self.assertEqual(fields["cable_type_hint"], "VSD/VFD")

    def test_braid_is_detected(self):
        fields = extract_entities("Instrument 2 triad 1.5 collective screen braid 110V", set())["fields"]

        self.assertTrue(fields["braid"])


class ScreenTypeTests(unittest.TestCase):
    def test_overall_screen_is_collective(self):
        fields = extract_entities("Instrument 4 pair 1.5mm overall screen 110V", set())["fields"]

        self.assertEqual(fields["screen_type"], "coll")

    def test_individual_and_overall_screen_is_ind_coll(self):
        fields = extract_entities(
            "Instrument cable 4 pair 1.5mm individual and overall screen 450/750V", set()
        )["fields"]

        self.assertEqual(fields["screen_type"], "ind+coll")

    def test_ioas_implies_armour(self):
        fields = extract_entities("Instrument 8 pair 0.5 IOAS 0.6/1kV", set())["fields"]

        self.assertEqual(fields["screen_type"], "ind+coll")
        self.assertTrue(fields["armoured"])

    def test_i_and_o_ampersand_is_ind_coll_screen(self):
        # "I&O Scn" (Individual & Overall Screen) is the ampersand shorthand for IOS.
        fields = extract_entities("0.5mm2, 8TR, Cu/PVC/I&O Scn/PVC", set())["fields"]

        self.assertTrue(fields["screen"])
        self.assertEqual(fields["screen_type"], "ind+coll")


class TriadNotationTests(unittest.TestCase):
    def test_tr_suffix_is_read_as_triads(self):
        # "8TR" = 8 triads (T), the way "8PR" = 8 pairs -- not one core.
        fields = extract_entities("0.5mm2, 8TR, Cu/PVC/I&O Scn/PVC", set())["fields"]

        self.assertEqual(fields["count"], 8)
        self.assertEqual(fields["core_type"], "T")
        self.assertEqual(fields["cable_type_hint"], "Instrumentation")

    def test_bare_t_suffix_still_reads_as_triads(self):
        fields = extract_entities("8T 0.5mm", set())["fields"]

        self.assertEqual(fields["count"], 8)
        self.assertEqual(fields["core_type"], "T")

    def test_tr_word_boundary_does_not_match_inside_other_tokens(self):
        # A stray "TR" glued into another token (e.g. a size/word) is not a triad count.
        fields = extract_entities("4C 2.5mm2 TRAY rated Cu", set())["fields"]

        self.assertNotEqual(fields.get("core_type"), "T")
        self.assertEqual(fields["count"], 4)

    def test_oas_is_collective_and_implies_armour(self):
        # OAS = overall (collective) armoured screen -- IOAS without the individual screen.
        fields = extract_entities("Instrument 8 pair 0.5 OAS 0.6/1kV", set())["fields"]

        self.assertTrue(fields["screen"])
        self.assertEqual(fields["screen_type"], "coll")
        self.assertTrue(fields["armoured"])


class FireInstrumentTests(unittest.TestCase):
    def test_fire_instrument_pair_keeps_instrumentation_and_fire_flag(self):
        # Fire + pairs -> instrumentation family (A8) but the fire signal is preserved so
        # the resolver can route to the fire instrument style (WH).
        fields = extract_entities("Fire instrument cable 4 pair 1.5 450/750V", set())["fields"]

        self.assertEqual(fields["cable_type_hint"], "Instrumentation")
        self.assertTrue(fields["fire"])

    def test_fire_power_cable_still_fire_resistant(self):
        # A plain fire power cable (no pairs) must keep the Fire Resistant family.
        fields = extract_entities("FIRE 110 CU 4C+E 25mm2", set())["fields"]

        self.assertEqual(fields["cable_type_hint"], "Fire Resistant")
        self.assertTrue(fields["fire"])

    def test_fr_abbreviation_is_fire_rated(self):
        # "FR" is fire-rated (confirmed 2026-06-30).
        fields = extract_entities("185 mm2 4C+E LSZH FR", set())["fields"]

        self.assertTrue(fields["fire"])
        self.assertEqual(fields["cable_type_hint"], "Fire Resistant")

    def test_fr_on_a_vsd_line_keeps_vsd_family(self):
        # VSD wins the family hint; fire is carried separately so it routes to FH.
        fields = extract_entities("VSD 3C+3E 4mm LSZH FR", set())["fields"]

        self.assertEqual(fields["cable_type_hint"], "VSD/VFD")
        self.assertTrue(fields["fire"])

    def test_radox_is_fire_rated(self):
        # "Radox" is the brand name customers use for the fire-rated range
        # (confirmed 2026-08-03).
        fields = extract_entities("25mm 4c+e Radox 341", set())["fields"]

        self.assertTrue(fields["fire"])
        self.assertEqual(fields["cable_type_hint"], "Fire Resistant")

    def test_radox_on_a_vsd_line_keeps_vsd_family(self):
        # Same family routing as any other fire signal: VSD keeps its hint -> FH.
        fields = extract_entities("VSD 3C+3E 4mm Radox", set())["fields"]

        self.assertEqual(fields["cable_type_hint"], "VSD/VFD")
        self.assertTrue(fields["fire"])


class CircularTests(unittest.TestCase):
    def test_circ_sets_circular_not_flat(self):
        fields = extract_entities("4 mm2 LSZH Circ, 2C+E", set())["fields"]

        self.assertTrue(fields["circular"])
        self.assertNotIn("style_hint", fields)  # not flat

    def test_circuit_does_not_trigger_circular(self):
        fields = extract_entities("Circuit 1: 4C 70mm Cu XLPE 150m", set())["fields"]

        self.assertNotIn("circular", fields)


class ColourTests(unittest.TestCase):
    def test_purple_is_detected(self):
        fields = extract_entities("1.5mm2 2C Purple Dali Control Cable", set())["fields"]

        self.assertEqual(fields["colour"], "PU")

    def test_spelled_out_colours_resolve_to_their_catalog_codes(self):
        # YELLOW/BROWN/PINK/LIGHT BLUE were absent from the word list, so a
        # "100 MTR - 10MM Yellow" building-wire row silently took the style default.
        for word, code in [("Yellow", "YE"), ("Brown", "BR"), ("Pink", "PI"),
                           ("Light Blue", "LB"), ("Dark Blue", "DB")]:
            with self.subTest(word):
                fields = extract_entities(f"100 MTR - 10MM {word}", set())["fields"]
                self.assertEqual(fields["colour"], code)

    def test_green_yellow_still_reads_as_earth_not_yellow(self):
        for text in ("1C 95mm2 Cu Green/Yellow", "1C 95mm2 Cu GREEN YELLOW"):
            with self.subTest(text):
                fields = extract_entities(text, set())["fields"]
                self.assertEqual(fields["colour"], "EA")

    def test_plain_blue_is_not_swallowed_by_light_blue(self):
        fields = extract_entities("100 MTR - 10MM BLUE", set())["fields"]
        self.assertEqual(fields["colour"], "BL")


class SingleInsulatedFieldTests(unittest.TestCase):
    """"Single insulated" is an unsheathed conductor -- the opposite of "SDI" (Single
    DOUBLE Insulated), which is sheathed. The double spellings must not set the flag."""

    def _flag(self, text):
        return extract_entities(text, set())["fields"].get("single_insulated")

    def test_phrase_sets_the_flag(self):
        for text in ("SINGLE INSULATED FLEXIBLE HF-110 600/1000V",
                     "single-insulated power", "1C 16mm single insulation"):
            with self.subTest(text):
                self.assertTrue(self._flag(text))

    def test_double_insulated_spellings_do_not(self):
        for text in ("Single Double Insulated", "single/double insulated 1C 70mm",
                     "Flex S.D.I.", "1C 500mm2 Cu Flexible SDI PVC/XLPE-110/NJ"):
            with self.subTest(text):
                self.assertFalse(self._flag(text))


class AccessoryTests(unittest.TestCase):
    def test_barrel_link_is_accessory(self):
        fields = extract_entities("COPPER BARREL LINK 95mm | BS95 | 12 | ea", set())["fields"]

        self.assertTrue(fields["accessory_hint"])


class ParallelSingleCoreTests(unittest.TestCase):
    """"Nx SIZE1 + [Mx] SIZE2 earth" (e.g. real feeder schedules like "8x 185mm +
    1x 185mm earth") describes N single-core phase conductors run in parallel PLUS
    a separate earth conductor -- NOT a multicore cable, and NOT an earth-only
    cable. This must never resolve as a confident single-product match (it needs
    two line items, which the AI-split path produces); the extractor's job is only
    to flag it and stop the old earth-only collapse from silently dropping the
    phase conductors (confirmed 2026-07-02)."""

    def test_leading_count_plus_leading_count_earth_is_flagged(self):
        fields = extract_entities("8x 185mm + 1x 185mm earth", set())["fields"]

        self.assertTrue(fields["parallel_single_core"])
        self.assertIsNone(fields.get("earth_only"))
        self.assertIsNone(fields.get("count"))

    def test_bare_earth_size_with_no_leading_count_is_flagged(self):
        # "70mm Earth" with no "1x" prefix -- the earth-count defaults to 1 downstream
        # (AI split), but the extractor must still recognize and flag this shape.
        fields = extract_entities("6x 185mm + 70mm Earth", set())["fields"]

        self.assertTrue(fields["parallel_single_core"])
        self.assertIsNone(fields.get("earth_only"))

    def test_reduced_earth_size_variant_is_flagged(self):
        fields = extract_entities("4x 120mm + 1x 50mm earth", set())["fields"]

        self.assertTrue(fields["parallel_single_core"])
        self.assertIsNone(fields.get("earth_only"))

    def test_ordinary_earth_line_is_unaffected(self):
        # A genuine single-core earth-only line (no leading "Nx" parallel notation)
        # must still collapse to earth_only as before -- this guard is narrowly
        # scoped to the parallel-single-core shape, not earth-only lines generally.
        fields = extract_entities("XLPE CU EARTH 1C 25mm2", set())["fields"]

        self.assertNotIn("parallel_single_core", fields)
        self.assertTrue(fields["earth_only"])


class SingleCoreRunNotationTests(unittest.TestCase):
    """"Nx1C" / "AxBx1C" phase runs and a leading "Nx" on an earth-only line are
    RUN multipliers on ONE single-core product (phases and earth quoted on
    separate lines), not core counts. The glued "x1C" has no word boundary after
    the "x", so CORE_RE never sees the core token -- without this the row
    collapses to a single 1C cut and drops every conductor after the first."""

    def test_glued_4x1C_is_four_runs_of_one_core(self):
        fields = extract_entities("4x1C, 150mm, 80m", set())["fields"]

        self.assertEqual(fields["count"], 1)
        self.assertEqual(fields["core_type"], "C")
        self.assertEqual(fields["cross_section"], "150")
        self.assertEqual(fields["parallel_run_count"], 4)
        self.assertEqual(fields["number_of_lengths"], 4)
        self.assertEqual(fields["length_each_m"], 80)
        self.assertEqual(fields["total_length_m"], 320)

    def test_circuit_times_cores_multiplies_runs(self):
        fields = extract_entities("3x4x1C, 300mm, 150m", set())["fields"]

        self.assertEqual(fields["count"], 1)
        self.assertEqual(fields["parallel_run_count"], 12)
        self.assertEqual(fields["number_of_lengths"], 12)
        self.assertEqual(fields["total_length_m"], 1800)

    def test_drum_count_separated_from_its_length(self):
        # "4 drums of <description> at 75 m each": the cable description sits between the
        # count and the length, so the adjacent-form DRUM_RE misses and the 4 used to be
        # dropped -- quoting one 75 m drum instead of four.
        fields = extract_entities(
            "4 drums of 400mm copper flex xlpe at 75 meters each drum", set()
        )["fields"]

        self.assertEqual(fields["number_of_lengths"], 4)
        self.assertEqual(fields["length_each_m"], 75)
        self.assertEqual(fields["total_length_m"], 300)

    def test_adjacent_drum_form_still_wins(self):
        fields = extract_entities("2 drums of 100m 4C 16mm2 XLPE", set())["fields"]

        self.assertEqual(fields["number_of_lengths"], 2)
        self.assertEqual(fields["length_each_m"], 100)
        self.assertEqual(fields["total_length_m"], 200)

    def test_drum_count_without_any_length(self):
        # A count but no metres anywhere: record the drums, leave the length unknown
        # rather than borrowing one.
        fields = extract_entities("1 drum of 120mm flex Earth", set())["fields"]

        self.assertEqual(fields["number_of_lengths"], 1)
        self.assertIsNone(fields.get("length_each_m"))
        self.assertIsNone(fields.get("total_length_m"))

    def test_each_drum_phrase_is_not_read_as_a_count(self):
        # "each drum" has no digit before it, so it must not be mistaken for a drum count.
        fields = extract_entities("3C 25mm2 XLPE 250m each drum", set())["fields"]

        self.assertEqual(fields["number_of_lengths"], 1)
        self.assertEqual(fields["length_each_m"], 250)

    def test_stray_dash_between_counts_is_tolerated(self):
        fields = extract_entities("3x - 4x1C, 95mm, 130m", set())["fields"]

        self.assertEqual(fields["parallel_run_count"], 12)
        self.assertEqual(fields["total_length_m"], 1560)

    def test_leading_multiplier_on_earth_line_counts_runs(self):
        fields = extract_entities("3x 240mm earth, 150m", set())["fields"]

        self.assertTrue(fields["earth_only"])
        self.assertEqual(fields["count"], 1)
        self.assertEqual(fields["parallel_run_count"], 3)
        self.assertEqual(fields["number_of_lengths"], 3)
        self.assertEqual(fields["total_length_m"], 450)

    def test_asterisk_multiplier_counts_bare_single_core_runs(self):
        fields = extract_entities("24* 400mm 110 degree AL @ 110M", set())["fields"]

        self.assertEqual(fields["count"], 1)
        self.assertEqual(fields["conductor"], "A")
        self.assertEqual(fields["cross_section"], "400")
        self.assertEqual(fields["parallel_run_count"], 24)
        self.assertEqual(fields["number_of_lengths"], 24)
        self.assertEqual(fields["length_each_m"], 110)
        self.assertEqual(fields["total_length_m"], 2640)

    def test_asterisk_multiplier_counts_earth_runs(self):
        fields = extract_entities("6* 185mm AL earth @ 110M", set())["fields"]

        self.assertTrue(fields["earth_only"])
        self.assertEqual(fields["parallel_run_count"], 6)
        self.assertEqual(fields["number_of_lengths"], 6)
        self.assertEqual(fields["length_each_m"], 110)
        self.assertEqual(fields["total_length_m"], 660)

    def test_earth_line_without_leading_multiplier_is_single_run(self):
        fields = extract_entities("50mm earth, 80m", set())["fields"]

        self.assertTrue(fields["earth_only"])
        self.assertEqual(fields["number_of_lengths"], 1)
        self.assertEqual(fields["total_length_m"], 80)
        self.assertNotIn("parallel_run_count", fields)

    def test_nx_size_plus_mx_earth_shape_is_still_flagged_not_folded(self):
        # The separate-products "Nx SIZE + Mx SIZE earth" shape (phases and earth
        # on ONE line) must keep flagging for the AI split -- the leading-earth
        # multiplier must not hijack it into a confident single-product fold.
        fields = extract_entities("6x 185mm + 70mm Earth", set())["fields"]

        self.assertTrue(fields["parallel_single_core"])
        self.assertNotIn("parallel_run_count", fields)
        self.assertIsNone(fields.get("earth_only"))

    def test_counted_earth_run_variant_stays_parallel_single_core(self):
        # "6x 185mm + 1x 185mm earth" has a counted+sized earth run, but the word
        # "earth" (with mm on both sides) keeps it a two-product parallel feeder --
        # the bundled-multicore pattern must not claim it.
        fields = extract_entities("6x 185mm + 1x 185mm earth", set())["fields"]

        self.assertTrue(fields["parallel_single_core"])
        self.assertIsNone(fields.get("count"))


class GluedSingleCorePlusEarthTests(unittest.TestCase):
    """Per Sales: "1c" is ALWAYS single-core and a trailing "+E" is a SEPARATE
    earth cable, not a 5th core. So "Nx1C+E" / "AxBx1C+E" is a parallel
    single-core feeder that must split into an active line and an earth line --
    it must be flagged (not confidently resolved as one multicore)."""

    def test_glued_core_plus_earth_is_flagged_for_split_not_multicore(self):
        fields = extract_entities("4x1c+e XLPE X-90", set())["fields"]

        self.assertTrue(fields["parallel_single_core"])
        self.assertIsNone(fields.get("count"))
        self.assertEqual(fields["active_run_count"], 4)
        self.assertEqual(fields["earth_run_count"], 1)

    def test_bundles_multiply_active_and_earth_runs(self):
        fields = extract_entities("3x4x1c+e XLPE X-90", set())["fields"]

        self.assertTrue(fields["parallel_single_core"])
        self.assertIsNone(fields.get("count"))
        self.assertEqual(fields["active_run_count"], 12)  # 3 bundles x 4 single cores
        self.assertEqual(fields["earth_run_count"], 3)    # one earth per bundle

    def test_glued_plus_earth_does_not_collapse_to_earth_only(self):
        # Even with a size present the flagged feeder must not become an earth-only
        # single-core line -- it is two products, resolved by the AI split.
        fields = extract_entities("4x1c+e 25mm XLPE", set())["fields"]

        self.assertTrue(fields["parallel_single_core"])
        self.assertIsNone(fields.get("earth_only"))


class MulticoreCableMultiplierTests(unittest.TestCase):
    """A leading "Nx" on an explicit multicore ("Nc"/"Nc+e") counts identical
    cables, not single-core runs. "4c+e" is 5C; "3x 4c+e" is 3 cables of 5C. The
    glued "3x4c" (no space) also defeats the core regex boundary, so the count is
    recovered here too."""

    def test_plain_core_plus_earth_is_five_core(self):
        fields = extract_entities("4c+e 25mm 100m", set())["fields"]

        self.assertEqual(fields["count"], 5)
        self.assertTrue(fields["earth"])
        self.assertEqual(fields["number_of_lengths"], 1)
        self.assertEqual(fields["total_length_m"], 100)

    def test_leading_multiplier_counts_multicore_cables(self):
        fields = extract_entities("3x 4c+e 25mm 100m", set())["fields"]

        self.assertEqual(fields["count"], 5)
        self.assertTrue(fields["earth"])
        self.assertEqual(fields["number_of_lengths"], 3)  # 3 identical 5C cables
        self.assertEqual(fields["total_length_m"], 300)

    def test_glued_leading_multiplier_recovers_count_and_cables(self):
        fields = extract_entities("3x4c+e 25mm 100m", set())["fields"]

        self.assertEqual(fields["count"], 5)
        self.assertTrue(fields["earth"])
        self.assertEqual(fields["number_of_lengths"], 3)
        self.assertEqual(fields["total_length_m"], 300)

    def test_multicore_without_earth_multiplies_cables(self):
        fields = extract_entities("3x4c 25mm 100m", set())["fields"]

        self.assertEqual(fields["count"], 4)
        self.assertEqual(fields["number_of_lengths"], 3)
        self.assertEqual(fields["total_length_m"], 300)

    def test_single_core_1c_form_is_not_treated_as_multicore_cables(self):
        # "4x1c+e" must stay a single-core split, never "4 cables of 1C" -- the
        # multicore multiplier ignores a 1-core token.
        fields = extract_entities("4x1c+e XLPE X-90", set())["fields"]

        self.assertTrue(fields["parallel_single_core"])
        self.assertIsNone(fields.get("count"))


class BundledMulticoreNotationTests(unittest.TestCase):
    """A phase-plus-reduced-core written with sizes (and no "1c" single-core
    marker) is one bundled multicore: "4x10+1x4mm2" is a 5C cable at the phase
    size, the reduced earth folded into the same code."""

    def test_phase_plus_reduced_core_uses_phase_size_and_counts_earth(self):
        fields = extract_entities("4x10+1x4mm2 Flexible Rubber X-HF-110", set())["fields"]

        self.assertEqual(fields["count"], 5)
        self.assertEqual(fields["cross_section"], "10")
        self.assertEqual(fields["reduced_earth_size"], "4")
        self.assertTrue(fields["earth"])


class FlattenedConstructionScheduleTests(unittest.TestCase):
    """OCR can flatten Active/Insulation/Configuration schedule cells into one line.
    Formation words there must be read as construction, not catalog style hints."""

    def test_n_x_1_core_trefoil_recovers_single_core_and_run_count(self):
        fields = extract_entities(
            "2 x 300mm2 Cu, X-90 XLPE, 3 x 1 core Trefoil 40m", set()
        )["fields"]

        self.assertTrue(fields["trefoil"])
        self.assertEqual(fields["schedule_formation"], "trefoil")
        self.assertEqual(fields["count"], 1)
        self.assertEqual(fields["core_type"], "C")
        self.assertEqual(fields["cross_section"], "300")
        self.assertEqual(fields["conductor"], "N")
        self.assertEqual(fields["phase_cores"], 3)
        self.assertEqual(fields["neutral_parallel_runs"], 2)
        self.assertTrue(fields["neutral_inferred"])
        self.assertEqual(fields["number_of_lengths"], 8)
        self.assertEqual(fields["total_length_m"], 320)
        self.assertEqual(fields["active_parallel_runs"], 2)

    def test_schedule_flat_is_formation_not_fd_style_hint(self):
        fields = extract_entities("1 x 35mm2 Cu, X-90 XLPE, 4 core Flat 60m", set())["fields"]

        self.assertNotIn("trefoil", fields)
        self.assertEqual(fields["schedule_formation"], "flat")
        self.assertEqual(fields["count"], 4)
        self.assertEqual(fields["cross_section"], "35")
        self.assertNotIn("style_hint", fields)


class CircuitLabelPairingTests(unittest.TestCase):
    def test_pairs_standalone_label_with_following_cable_line(self):
        lines = [
            "MSSB-L3-4-E",
            "1 X 131m x 16mm Fire Rated 5core",
            "DB-H-L2M-01",
            "4 X 131m x 70mm SDI 90o",
            "DB-H-L1-01",
            "1 x 113m x 50mm 5core 90o",
            "DB-H-L2-03",
            "1 x 146m x 35mm 5core 90o",
        ]

        self.assertEqual(
            pair_circuit_label_lines(lines),
            [
                ("1 X 131m x 16mm Fire Rated 5core", "MSSB-L3-4-E"),
                ("4 X 131m x 70mm SDI 90o", "DB-H-L2M-01"),
                ("1 x 113m x 50mm 5core 90o", "DB-H-L1-01"),
                ("1 x 146m x 35mm 5core 90o", "DB-H-L2-03"),
            ],
        )

    def test_blank_lines_between_pairs_are_ignored(self):
        lines = ["MSSB-L3-4-E", "", "1 X 131m x 16mm Fire Rated 5core"]

        self.assertEqual(
            pair_circuit_label_lines(lines),
            [("1 X 131m x 16mm Fire Rated 5core", "MSSB-L3-4-E")],
        )

    def test_label_not_followed_by_cable_line_passes_through_alone(self):
        lines = ["MSSB-L3-4-E", "Please see attached schedule for full details"]

        self.assertEqual(
            pair_circuit_label_lines(lines),
            [
                ("MSSB-L3-4-E", ""),
                ("Please see attached schedule for full details", ""),
            ],
        )

    def test_ordinary_cable_lines_are_unaffected(self):
        lines = ["4C 10mm2 Cu XLPE 2000m", "0.6/1kV XLPE single core 240mm2"]

        self.assertEqual(
            pair_circuit_label_lines(lines),
            [(line, "") for line in lines],
        )


class QuantityGapTests(unittest.TestCase):
    """Extractor gaps from the table-shape audit (F4), fix #6."""

    def _fields(self, text):
        return extract_entities(text, set())["fields"]

    def test_trailing_unitless_cuts_after_size(self):
        fields = self._fields("185mm ALI | 8 X 40")
        self.assertEqual(fields["number_of_lengths"], 8)
        self.assertEqual(fields["length_each_m"], 40.0)
        self.assertEqual(fields["total_length_m"], 320.0)

    def test_trailing_cuts_do_not_fire_on_a_size_x_length(self):
        # "2.5mm2 x 500" means 500 m of 2.5mm2, never 2 cuts of 500.
        fields = self._fields("3C+E 2.5mm2 x 500")
        self.assertNotEqual(fields.get("number_of_lengths"), 2)

    def test_no_space_x_glued_length_is_found(self):
        # 08-Julie's chained notation: the length is glued to an x.
        fields = self._fields("2 x 4 x 1c x 400mm + E XLPE Flex x16m")
        self.assertEqual(fields.get("length_each_m"), 16.0)

    def test_no_prefix_counts_parallel_runs(self):
        fields = self._fields("12no. 1C x 300mm2 Cu Flex")
        self.assertEqual(fields["number_of_lengths"], 12)

    def test_mtrs_suffix_still_works_via_normalize(self):
        fields = self._fields("120MM XLPE/PVC FLEX 4 X 90MTRS")
        self.assertEqual(fields["number_of_lengths"], 4)
        self.assertEqual(fields["length_each_m"], 90.0)


class StatedCutCountTests(unittest.TestCase):
    """A cut count the customer stated in prose, with no length of its own.

    01-Billy's `640m x 240mm 1C AL flex (8 cuts)` was read as a single 640 m length in
    every phrasing: `number_of_lengths` stayed 1, `cuts_explicit` stayed false, and the
    row carried no warning, so it read Ready. The count divides rather than multiplies
    -- the stated length is the total -- and it has to be heard for two reasons past the
    4js comment: `pipeline._prepack_applies` lets whole-spool rounding rewrite a row
    with no explicit cuts, and spools cannot be joined into a continuous 80 m run.
    """

    def _fields(self, text):
        return extract_entities(text, set())["fields"]

    def test_a_stated_cut_count_divides_the_total_in_every_phrasing(self):
        for text in ("640m x 240mm 1C AL flex (8 cuts)",
                     "640m x 240mm 1C AL flex, 8 cuts",
                     "240mm 1C AL flex 640m in 8 cuts",
                     "240mm 1C AL flex 640m, 8 off cuts"):
            with self.subTest(text=text):
                fields = self._fields(text)
                self.assertEqual(fields["number_of_lengths"], 8)
                self.assertEqual(fields["length_each_m"], 80.0)
                # The total is what the price is calculated on and is left exactly as
                # stated -- this fix corrects the breakdown, it does not move the money.
                self.assertEqual(fields["total_length_m"], 640.0)
                self.assertTrue(fields["cuts_explicit"])

    def test_an_uneven_split_is_left_uneven(self):
        # 650 m in 8 cuts is a real request. Rounding it here would change what ships.
        fields = self._fields("240mm 1C AL flex 650m in 8 cuts")
        self.assertEqual(fields["number_of_lengths"], 8)
        self.assertEqual(fields["length_each_m"], 81.25)
        self.assertEqual(fields["total_length_m"], 650.0)

    def test_a_cut_count_with_its_own_length_multiplies_instead(self):
        # "8 cuts of 40m" names both numbers, so 40 is per cut and the total is 320.
        for text in ("8 cuts of 40m 240mm 1C AL flex", "8 cuts x 40m 240mm 1C AL flex"):
            with self.subTest(text=text):
                fields = self._fields(text)
                self.assertEqual(fields["number_of_lengths"], 8)
                self.assertEqual(fields["length_each_m"], 40.0)
                self.assertEqual(fields["total_length_m"], 320.0)

    def test_a_single_cut_changes_nothing(self):
        # 1 x 640 is identical to 640, and claiming explicit cuts would switch off
        # prepack rounding on a row that never asked for it.
        fields = self._fields("640m x 240mm 1C AL flex (1 cut)")
        self.assertEqual(fields["number_of_lengths"], 1)
        self.assertEqual(fields["length_each_m"], 640.0)
        self.assertFalse(fields.get("cuts_explicit"))

    def test_a_quantity_stated_twice_in_two_directions_is_left_alone(self):
        # "12no. ... 8 cuts" multiplies and divides at once. Picking a winner is how a
        # quantity error gets made confidently, so this keeps the run-count behaviour.
        fields = self._fields("12no. 1C x 300mm2 x 50m, 8 cuts")
        self.assertEqual(fields["number_of_lengths"], 12)
        self.assertEqual(fields["length_each_m"], 50.0)

    def test_a_bare_cutting_charge_is_still_not_a_quantity(self):
        # CUT_SERVICE_RE routes these to the accessory panel; they must not acquire a
        # length here on the strength of the word "cuts".
        fields = self._fields("cutting charge")
        self.assertIsNone(fields.get("total_length_m"))


class ScheduleLinePairingTests(unittest.TestCase):
    """Alternating spec-line/quantity-line styles (F5), fix #6."""

    def _pairs(self, lines):
        from tricab_cable_rules.extractor import pair_schedule_lines

        return pair_schedule_lines(lines)

    def test_bare_number_line_folds_into_spec_line_above(self):
        # 10-Neil: the 200 is the cable's length, not its own item.
        self.assertEqual(
            self._pairs(["Cable 1 - 95mm2 SDI, X-HF-110", "200"]),
            [("Cable 1 - 95mm2 SDI, X-HF-110 200", "")],
        )

    def test_cuts_line_folds_into_product_code_line_above(self):
        # 07-Ajith: product code on one line, "2 X 50M" on the next.
        self.assertEqual(
            self._pairs(["LS-XXXD/1C1.5EA", "2 X 50M"]),
            [("LS-XXXD/1C1.5EA 2 X 50M", "")],
        )

    def test_spec_line_with_its_own_length_is_not_extended(self):
        self.assertEqual(
            self._pairs(["4C 10mm2 Cu XLPE 2000m", "30"]),
            [("4C 10mm2 Cu XLPE 2000m", ""), ("30", "")],
        )

    def test_bare_numbers_after_non_spec_lines_pass_through(self):
        self.assertEqual(
            self._pairs(["83", "400"]),
            [("83", ""), ("400", "")],
        )

    def test_label_pairing_still_happens_first(self):
        self.assertEqual(
            self._pairs(["MSSB-L3-4-E", "1 X 131m x 16mm Fire Rated 5core"]),
            [("1 X 131m x 16mm Fire Rated 5core", "MSSB-L3-4-E")],
        )


class InsulationDesignationTests(unittest.TestCase):
    """AS/NZS 3808 designations are a grammar, not a list.

    The old HIGH_TEMP_RE enumerated the HF family at 110 and the bare forms at 125, so
    `X-HF-125` fell through while `X-HF-110` matched. Vocabulary here is taken from the
    Insulation/Bedding columns of data/catalog/current_cables.xlsx.
    """

    HOT = [
        "X-125", "X-HF-110", "X-HF-125", "HF-110", "HF-125", "R-HF-125", "R-125",
        "R-E-110", "R-EP-125", "HFS-110-TP", "XLPE-110", "XLPE-125",
        "PVC/XLPE-110/NJ", "500mm2 1C Cu Flexible SDI PVC/XLPE-110/NJ",
    ]
    NOT_HOT = ["X-90", "X-HF-90", "R-70", "V-90-HT", "V-75", "XLPE-90", "PVC"]

    def test_designations_above_90_read_as_high_temp(self):
        for text in self.HOT:
            with self.subTest(text=text):
                self.assertTrue(mentions_high_temp(text))

    def test_designations_at_or_below_90_do_not(self):
        for text in self.NOT_HOT:
            with self.subTest(text=text):
                self.assertFalse(mentions_high_temp(text))

    def test_brand_designations_are_not_temperatures(self):
        """`XL-20` and `R-20` are LSFLEX brand codes and `5V90` is a *sheath*
        designation (leading digit = thickness class). A bare \\d{2,3} temperature slot
        would read all three as temperatures."""
        for text in ("XL-20", "R-20", "LSFLEX XL-20", "5V90"):
            with self.subTest(text=text):
                self.assertFalse(mentions_high_temp(text))
                self.assertIsNone(insulation_designation(text)[1])

    def test_a_size_is_not_read_as_a_temperature(self):
        """150 is whitelisted as a temperature and is also a common cross-section."""
        self.assertFalse(mentions_high_temp("XLPE 150mm2"))
        self.assertIsNone(insulation_designation("1C 150mm2 XLPE")[1])

    def test_product_codes_are_not_designations(self):
        for code in ("XL-PAXN/1C500OR", "KL-PAXN/1C500OR", "KN-PAXN/1C500OR"):
            with self.subTest(code=code):
                self.assertFalse(mentions_high_temp(code))

    def test_material_and_temperature_are_parsed_separately(self):
        self.assertEqual(insulation_designation("X-HF-125"), ("XLPE", 125))
        self.assertEqual(insulation_designation("R-EP-125"), ("elastomer", 125))
        self.assertEqual(insulation_designation("V-90-HT"), ("PVC", 90))
        self.assertEqual(insulation_designation("HF-110"), (None, 110))
        self.assertEqual(insulation_designation("XLPE"), ("XLPE", None))
        # "PVC/XLPE" names the sheath first and the insulation second: XLPE must win.
        self.assertEqual(insulation_designation("PVC/XLPE-110/NJ"), ("XLPE", 110))

    def test_radox_alone_carries_the_temperature(self):
        """Customers sometimes name only the brand (confirmed 2026-08-16). It stays a
        fire signal too -- this is additive."""
        self.assertTrue(mentions_high_temp("RADOX"))
        self.assertTrue(extract_entities("25mm 4c&e Radox 341", set())["fields"]["fire"])


class NylonJacketAbbreviationTests(unittest.TestCase):
    def test_nj_sets_termite_on_a_cable_line(self):
        fields = extract_entities("500mm2 1C Cu Flexible SDI PVC/XLPE-110/NJ", set())["fields"]
        self.assertTrue(fields["termite"])

    def test_nj_alone_does_not_fire_without_a_cable_signal(self):
        """Two letters are too cheap to trust unguarded -- an address or reference line
        must not be read as a nylon-jacket request."""
        fields = extract_entities("Unit 4/10 Prestige Parade, Wangara NJ", set())["fields"]
        self.assertFalse(fields.get("termite"))


class LszhSpellingTests(unittest.TestCase):
    def test_ls0z_and_lshf_spellings_are_recognised(self):
        """Both appear in the style sheet's own Bedding column; only LS0H was listed."""
        for text in ("1C 4mm2 LS0Z", "1C 4mm2 LSHF"):
            with self.subTest(text=text):
                self.assertTrue(extract_entities(text, set())["fields"].get("lszh"))


class DecimalCommaSizeTests(unittest.TestCase):
    """"2,5mm" is 2.5mm2, not 5 (plan §1.3 row 3)."""

    def _size(self, text):
        return extract_entities(text, set())["fields"].get("cross_section")

    def test_decimal_comma_reads_as_a_fraction(self):
        for text, expected in (
            ("2,5mm", "2.5"),
            ("2,5mm2", "2.5"),
            ("4C 1,5mm2 Cu XLPE", "1.5"),
            ("2,5mm 2.5mm2", "2.5"),
        ):
            with self.subTest(text=text):
                self.assertEqual(self._size(text), expected)

    def test_thousands_separator_and_circuit_lists_are_untouched(self):
        # "2,500m" is a length, and "Cables 2,5 &7" is a list of circuit numbers.
        # Neither may be rewritten into a fractional size.
        self.assertEqual(self._size("2,500m of 95mm2 cable"), "95")
        self.assertEqual(self._size("Cables 2,5 &7 - 70mm2 SDI, X-HF-110 404"), "70")


class SquareMillimetreSpellingTests(unittest.TestCase):
    """"sqmm" / "sq.mm" / "sq mm" are sizes (docs/EMAIL_CORPUS_AUDIT.md 4.5).

    Two separate failures hid behind this. A line whose ONLY cable signal was a
    sq-mm size was dropped outright; a line that had some other signal ("4C",
    "1 Core") was captured but came back with cross_section=None, so it either
    could not resolve or -- worse -- resolved silently against a stale size.
    """

    def _fields(self, text):
        return extract_entities(text, set())["fields"]

    def test_sq_mm_spellings_all_yield_the_size(self):
        for text in (
            "70sqmm SDI Aluminium",
            "70 sqmm SDI Aluminium",
            "70sq.mm SDI Aluminium",
            "70 sq.mm SDI Aluminium",
            "70 sq mm SDI Aluminium",
            "70SQ.MM,CU,X-HF-110",
        ):
            with self.subTest(text=text):
                self.assertEqual(self._fields(text).get("cross_section"), "70")

    def test_line_with_no_other_cable_signal_is_still_captured(self):
        # 0151's four dropped lines: siblings survived only because they said
        # "4C+E". CABLE_SIGNAL_RE must accept the size on its own.
        self.assertTrue(CABLE_SIGNAL_RE.search("Qty 320 metres 70sqmm SDI Aluminium - Actives 110 degrees"))

    def test_sq_mm_size_wins_over_an_unrelated_number(self):
        # 0071 resolved this row as 4C.75 -- the size came from elsewhere in the
        # string entirely once "25SQ.MM" failed to parse.
        fields = self._fields("0.6/1kV X 4C X 25SQ.MM,CU,X-HF-90,UNARMOURED,HFS-90-TP")

        self.assertEqual(fields.get("cross_section"), "25")
        self.assertEqual(fields.get("count"), 4)

    def test_square_metres_are_not_a_cable_size(self):
        # "sq m" without the second m is an area, not a conductor cross-section.
        self.assertIsNone(self._fields("Site area 400 sq m").get("cross_section"))


class PerCutLineTests(unittest.TestCase):
    """"@ 55 meters per cut" must stay a cable, and the leading Nx must be read.

    Both defects hit the same real RFQ (Chris -> Bhoomi, quoted 2026-08-25): every
    main on it reads "4x 1Cx 120mm X-HF-110 @ 55 meters per cut". The word "cut" sat
    unguarded in ACCESSORY_RE, so every main was routed to the accessory panel with no
    product code at all; and the glued "1Cx" notation left no word boundary after the
    C, so the leading "4x" was invisible and the line quoted one run instead of four."""

    def _result(self, text):
        from rfq_parser.pipeline import match_source_line
        import json
        from unittest import mock
        from rfq_parser import pipeline

        # What the cost API answered for KL-PAXN/1C120OR on 2026-09-28: a stocked cable,
        # cut to length. Offline, the source rounds these lines to whole 50 m spools.
        stock = json.dumps({"success": True, "data": {"calculatedCostPerMeter": 26.23, "status": "2304",
                            "defaultCost": 26.23, "defaultLength": 6000}}).encode()
        with mock.patch.object(pipeline.cost_api, "_http_get", return_value=stock):
            return match_source_line(text, {"kind": "test"}, allow_llm_fallback=False)[0]

    def test_per_cut_line_is_a_cable_not_an_accessory(self):
        result = self._result("4x 1Cx 120mm X-HF-110 @ 55 meters per cut")

        self.assertEqual(result["item_type"], "cable")
        self.assertTrue(result["selected_product_code"])

    def test_leading_multiplier_is_the_cut_count(self):
        length = self._result("4x 1Cx 120mm X-HF-110 @ 55 meters per cut")["length"]

        self.assertEqual(length["length_each_m"], 55.0)
        self.assertEqual(length["number_of_lengths"], 4)
        self.assertEqual(length["total_length_m"], 220)

    def test_spaced_single_core_notation_reads_the_same_count(self):
        # "4 x 1C 120mm" resolves its core count up front, which used to skip the
        # parallel-run branch entirely.
        length = self._result("4 x 1C 120mm2 X-HF-110 @ 55 meters per cut")["length"]

        self.assertEqual(length["number_of_lengths"], 4)
        self.assertEqual(length["total_length_m"], 220)

    def test_bracketed_cuts_wording_stays_a_cable(self):
        # Real corpus line (text/01-Billy-Vic-Test-Text.txt) that the same bug ate.
        result = self._result("640m x 240mm 1C AL flex (8 cuts)")

        self.assertEqual(result["item_type"], "cable")
        self.assertEqual(result["selected_product_code"], "XL-PAXA/1C240BK")

    def test_single_run_line_is_unchanged(self):
        length = self._result("1Cx 120mm X-HF-110 @ 55 meters")["length"]

        self.assertEqual(length["number_of_lengths"], 1)
        self.assertEqual(length["length_each_m"], 55.0)

    def test_a_real_cutting_charge_is_still_an_accessory(self):
        # The guard must not swing the other way: a cutting-service line carries no
        # cable construction, so it still belongs on the accessory panel.
        for text in ("cable cutting charge", "2 x cutting charges"):
            with self.subTest(text=text):
                self.assertEqual(self._result(text)["item_type"], "accessory")


if __name__ == "__main__":
    unittest.main()
