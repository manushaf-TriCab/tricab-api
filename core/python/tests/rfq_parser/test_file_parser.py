# Generated from the RFQ parser's tests/test_file_parser.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from rfq_parser.reel_quantity import per_reel_length
from rfq_parser.file_parser import (
    _tabular_items,
    annotation_route_length,
    assign_notes_to_rows,
    attach_product_code,
    build_construction_schedule_items,
    build_route_schedule_items,
    build_structured_xlsx_item,
    is_colour_header,
    header_role,
    is_construction_schedule,
    is_core_size_header,
    is_transposed_cable_table,
    looks_like_header,
    looks_like_section_heading,
    parse_file,
    parse_pdf,
    parse_text,
    parse_xlsx,
    pivot_transposed_cable_table,
    price_column_indexes,
    product_code_continuation,
    route_schedule_columns,
)


def _construction_schedule_table():
    return [
        ["Cable No", "Active", "Neutral", "Earth", "Insulation", "Configuration", "Original Length", "Accurate Length"],
        ["1 - MSB", "3 x 400mm² Cu", "3 x 400mm²", "N/A", "R-HF-110 Elastomer", "3 x 1 core Trefoil", "0.00m", "Completed"],
        ["2 - LIFT 1/2", "1 x 25mm² Cu", "1 x 25mm²", "1 x 6mm² Cu", "R-HF-110 Elastomer", "4 core Circular", "", "118.00m"],
        ["11 - DB-B-PV-L9", "1 x 185mm² Al", "1 x 185mm²", "1 x 35mm² Cu", "X-90 XLPE", "3 x 1 core Trefoil", "175.00m", ""],
        ["9 - FDCIE", "1 x 16mm² Cu", "1 x 16mm²", "1 x 6mm² Cu", "R-HF-110 Elastomer", "2 core Circular", "", "105.00m"],
    ]


def _submain_table():
    return [
        ["", "Submain C1", "Submain C2", "Submain C3"],
        ["Type of power supply", "Non Essential", "Essential", "Essential"],
        ["Feed from", "L1 PS", "Main Essential Service Switchboard", "Portable Generator Junction box"],
        ["Supply to", "MSB", "MSB", "MSB"],
        ["Supply Breaker size", "800/1250A 3P", "315A 3P", "1250A 3P"],
        ["Conductor size", "2 x 300mm²", "300mm²", "3 x 300mm²"],
        ["Conductor material", "Copper", "Copper", "Copper"],
        ["Insulation", "X-90 XLPE", "R-HF-110", "R-HF-110"],
        ["Sheathing", "5V-90 PVC", "HF-110-R", "HF-110-R"],
        ["N° of cores", "4C + E", "4C + E", "4C + E"],
        ["Nominal length", "80m", "115m", "50m"],
        ["Cable type", "SDI Flex 0.6/1K", "SDI Flex 0.6/1K", "SDI Flex 0.6/1K"],
        ["Voltage drop", "1.37%", "1.46%", "0.92%"],
    ]


class PdfTableColumnTests(unittest.TestCase):
    def test_two_column_cable_length_header_is_detected(self):
        self.assertTrue(looks_like_header(["Cable Type", "Length\n(M)"]))

    def test_length_column_is_attached_as_metres(self):
        item = build_structured_xlsx_item(
            ["FR 110° CU 4C+E 25mm2", "1150"],
            ["Cable Type", "Length\n(M)"],
        )

        self.assertIsNotNone(item)
        self.assertEqual(item["text"], "FR 110° CU 4C+E 25mm2")
        self.assertEqual(item["metadata"]["table_quantity"], 1150)
        self.assertEqual(item["metadata"]["table_unit"], "m")

    def test_quantity_in_metres_types_of_cables_header_is_detected(self):
        self.assertTrue(looks_like_header(["QUANTITY (In Metres)", "TYPES OF CABLES"]))
        item = build_structured_xlsx_item(
            ["45", "6mm Building Wire - Earth"],
            ["QUANTITY (In Metres)", "TYPES OF CABLES"],
        )

        self.assertIsNotNone(item)
        self.assertEqual(item["text"], "6mm Building Wire - Earth")
        self.assertEqual(item["metadata"]["table_quantity"], 45)
        self.assertEqual(item["metadata"]["table_unit"], "m")

    def test_sn_name_qty_units_header_is_detected(self):
        # Real RFQ schedule headers: S/N | Name | Qty | Units. "Name" is the
        # description column and "Units" the unit column.
        self.assertTrue(looks_like_header(["S/N", "Name", "Qty", "Units"]))

    def test_name_qty_units_row_attaches_quantity(self):
        item = build_structured_xlsx_item(
            ["1", "4C+E 120mm Cu (EV-DV-B02-01)", "45", "M"],
            ["S/N", "Name", "Qty", "Units"],
        )
        self.assertIsNotNone(item)
        self.assertEqual(item["text"], "4C+E 120mm Cu (EV-DV-B02-01)")
        self.assertEqual(item["metadata"]["table_quantity"], 45)
        self.assertEqual(item["metadata"]["table_unit"], "M")
        self.assertEqual(item["metadata"]["reference"], "1")

    def test_description_size_quantity_um_row_attaches_size_and_unit(self):
        item = build_structured_xlsx_item(
            ["LSZH FLEXIBLE BUILDING WIRE 110d", "", "", "6MM", "", "", "2100", "M"],
            ["Description", "", "", "Size", "", "", "Quantity", "U/M"],
        )

        self.assertIsNotNone(item)
        self.assertEqual(item["text"], "LSZH FLEXIBLE BUILDING WIRE 110d 6MM")
        self.assertEqual(item["metadata"]["table_quantity"], 2100)
        self.assertEqual(item["metadata"]["table_unit"], "M")

    def test_section_header_has_no_table_quantity(self):
        item = build_structured_xlsx_item(
            ["Copper Fire rated 110° 4 core", ""],
            ["Cable Type", "Length\n(M)"],
        )

        self.assertIsNotNone(item)
        self.assertNotIn("table_quantity", item["metadata"])

    @patch("rfq_parser.file_parser.pdfplumber.open")
    def test_pdf_table_rows_are_structured_from_two_column_header(self, open_pdf):
        open_pdf.return_value.__enter__.return_value.pages = [
            _FakePage(
                [
                    ["Cable Type", "Length\n(M)"],
                    ["Copper Fire rated 110° 4 core", ""],
                    ["FR 110° CU 4C+E 16mm2", "1904"],
                    ["FR 110° CU 4C+E 25mm2", "1150"],
                    ["Copper Fire rated 110° 1 core", ""],
                    ["FR 110° CU 1C 70mm2", "10548"],
                    ["Copper XLPE 1 core EARTH", ""],
                    ["XLPE CU EARTH 1C 25mm2", "2593"],
                ],
                "Schedule Cables\nCable Type Length (M)\n"
                "Copper Fire rated 110° 4 core\nFR 110° CU 4C+E 16mm2 1904\n"
                "FR 110° CU 4C+E 25mm2 1150\nCopper Fire rated 110° 1 core\n"
                "FR 110° CU 1C 70mm2 10548\nCopper XLPE 1 core EARTH\n"
                "XLPE CU EARTH 1C 25mm2 2593",
            )
        ]

        items = parse_pdf(Path("sample.pdf"))

        self.assertEqual(len(items), 5)
        self.assertEqual(items[0]["text"], "FR 110° CU 4C+E 16mm2")
        self.assertEqual(items[0]["metadata"]["table_quantity"], 1904)
        self.assertEqual(items[0]["metadata"]["table_unit"], "m")
        self.assertIn("Copper Fire rated 110° 4 core", items[0]["context"])
        self.assertEqual(items[1]["metadata"]["table_quantity"], 1150)
        self.assertEqual(items[3]["text"], "XLPE CU EARTH 1C 25mm2")
        self.assertIn("Copper Fire rated 110° 1 core", items[3]["context"])
        self.assertIn("Copper XLPE 1 core EARTH", items[3]["context"])
        self.assertEqual(items[4]["text"], "Schedule Cables")


class ItemCodeCableIdRunsTests(unittest.TestCase):
    HEADERS = ["Cable ID", "ITEM CODE", "DESCRIPTION", "runs", "Qty per run", "Unit", "Sum of Total Required"]

    def test_header_roles(self):
        self.assertEqual(header_role("ITEM CODE"), "product_code")
        self.assertEqual(header_role("Cable ID"), "reference")
        self.assertEqual(header_role("runs"), "cuts")
        # "Qty per run" is the per-cut length, not the cut count.
        self.assertEqual(header_role("Qty per run"), "quantity")

    def test_metre_qualified_run_header_is_route_length_not_cuts(self):
        # "Run/M" (and kin) is the cable's per-run route length in metres, not a
        # run COUNT -- the metre/length qualifier separates it from bare "Run".
        for header in ("Run/M", "Run m", "Run (m)", "Run Length", "Run Length/m"):
            self.assertEqual(header_role(header), "route_length", header)
        # A bare run/runs column stays a cut count.
        self.assertEqual(header_role("Run"), "cuts")
        self.assertEqual(header_role("Runs"), "cuts")

    def test_run_length_wins_quantity_over_conduit_columns(self):
        # 23-Allison: the real cable length is "Run/M"; a later "Length"/"Qty"/
        # "Total M" trio belongs to the conduit sub-table and must not win.
        from rfq_parser.file_parser import select_quantity

        pairs = [
            ("Run/M", "70"),
            ("Length", "50"),
            ("Qty", "5"),
            ("Total M", "250"),
        ]
        header, value, derived = select_quantity(pairs)
        self.assertEqual(header, "Run/M")
        self.assertEqual(value, "70")

    def test_same_row_code_id_and_runs_are_all_captured(self):
        item = build_structured_xlsx_item(
            ["ADMINISTRATION DB", "KL-PAXN/1C240OR", "1C 240 mm2 Cu X-HF-110 OG", "4", "19", "m", "76"],
            self.HEADERS,
        )
        self.assertIsNotNone(item)
        # Customer code folded into the text so the resolver uses it directly.
        self.assertIn("KL-PAXN/1C240OR", item["text"])
        self.assertIn("1C 240 mm2 Cu X-HF-110 OG", item["text"])
        self.assertEqual(item["metadata"]["reference"], "ADMINISTRATION DB")
        self.assertEqual(item["metadata"]["product_code"], "KL-PAXN/1C240OR")
        self.assertEqual(item["metadata"]["number_of_lengths"], 4)
        self.assertEqual(item["metadata"]["table_quantity"], 19)
        self.assertEqual(item["metadata"]["table_unit"], "m")

    def test_single_run_still_records_cut_count(self):
        item = build_structured_xlsx_item(
            ["ADMINISTRATION DB", "", "1C 120 mm2 Cu V-90HT Earth", "1", "19", "m", "19"],
            self.HEADERS,
        )
        self.assertEqual(item["metadata"]["number_of_lengths"], 1)
        self.assertEqual(item["metadata"]["table_quantity"], 19)
        self.assertNotIn("product_code", item["metadata"])

    def test_per_reel_description_uses_quantity_as_reel_count(self):
        item = build_structured_xlsx_item(
            [
                "LS-XXXD/1C1WH",
                "1mm2 LSFLEX125 Switchboard X-HF-110 0.6/1KV 200/reel",
                "3",
            ],
            ["Product Code", "Description", "Quantity"],
        )

        self.assertIsNotNone(item)
        metadata = item["metadata"]
        self.assertEqual(metadata["table_quantity"], 200)
        self.assertEqual(metadata["table_unit"], "m")
        self.assertEqual(metadata["number_of_lengths"], 3)
        self.assertEqual(metadata["quantity_basis"], "per_reel")

    def test_spaced_code_cell_is_closed_up(self):
        # Sales type "LS - XXXD/1C2.5WH" for "LS-XXXD/1C2.5WH". PRODUCT_CODE_RE matches
        # only the closed form, so without this the row lost its explicit code and was
        # resolved from the description alone -- which returned XL-PAXN for LS-XXXD
        # panel wire at 0.80, high enough to read `matched` and auto-sync.
        item = build_structured_xlsx_item(
            ["2.5 WHITE FLEX", "200m ROLL", "LS - XXXD/1C2.5WH", "1"],
            ["Description", "UOM", "Code", "Req"],
        )

        self.assertIsNotNone(item)
        self.assertEqual(item["metadata"]["product_code"], "LS-XXXD/1C2.5WH")
        self.assertIn("LS-XXXD/1C2.5WH", item["text"])

    def test_spaced_code_cell_keeps_its_trailing_gloss_out_of_the_code(self):
        item = build_structured_xlsx_item(
            ["10mm BLACK FLEX", "100m ROLL", "LS - XXXD/1C10BK BLACK", "5"],
            ["Description", "UOM", "Code", "Req"],
        )

        self.assertIsNotNone(item)
        self.assertEqual(item["metadata"]["product_code"], "LS-XXXD/1C10BK BLACK")

    def test_code_cell_that_is_not_a_code_is_left_alone(self):
        # The rescue only fires when closing the gaps produces a code. A "Code" column
        # holding something else must not be quietly rewritten.
        item = build_structured_xlsx_item(
            ["2.5 WHITE FLEX", "200m ROLL", "see quote 12345 - rev B", "1"],
            ["Description", "UOM", "Code", "Req"],
        )

        self.assertIsNotNone(item)
        self.assertEqual(item["metadata"]["product_code"], "see quote 12345 - rev B")

    def test_req_header_is_a_quantity(self):
        self.assertEqual(header_role("Req"), "quantity")
        self.assertEqual(header_role("Required"), "quantity")
        # "req" as a substring would fire on "Frequency", so matching is exact.
        self.assertNotEqual(header_role("Frequency"), "quantity")

    def test_uom_roll_column_is_the_per_reel_length(self):
        # A stock order keeps the package size in its own UOM column rather than in the
        # description, so "Req 5" against "100m ROLL" is 5 rolls of 100m, not 5 metres.
        item = build_structured_xlsx_item(
            ["10mm BLACK FLEX", "100m ROLL", "LS-XXXD/1C10BK", "5"],
            ["Description", "UOM", "Code", "Req"],
        )

        self.assertIsNotNone(item)
        metadata = item["metadata"]
        self.assertEqual(metadata["table_quantity"], 100)
        self.assertEqual(metadata["table_unit"], "m")
        self.assertEqual(metadata["number_of_lengths"], 5)
        self.assertEqual(metadata["quantity_basis"], "per_reel")

    def test_bare_roll_count_is_not_read_as_a_length(self):
        # "100m ROLL" is a length per package; "3 ROLLS" is a count of packages. The
        # metre unit is the only thing separating them, so the juxtaposed form requires
        # it -- otherwise a 3-roll order would quote as 3 metres.
        self.assertIsNone(per_reel_length("3 ROLLS", allow_juxtaposed=True))
        self.assertIsNone(per_reel_length("ROLL", allow_juxtaposed=True))
        self.assertEqual(per_reel_length("100m ROLL", allow_juxtaposed=True), 100)
        self.assertEqual(per_reel_length("50m roll", allow_juxtaposed=True), 50)
        # ...and only where the caller opted in. A description naming the package size
        # in passing must not be read as one: a real corpus PO says
        # "23mm Black Split Loom Tube PP (50m roll)" with "100m (2 rolls)" in its Qty
        # column, and reading the 50 there turned a correct 100 m order into 50 m.
        self.assertIsNone(per_reel_length("100m ROLL"))
        self.assertIsNone(
            per_reel_length("23mm Black Split Loom Tube PP (50m roll) F20-AKXX/XD23BK")
        )
        # The separator form keeps working, metre unit optional as before.
        self.assertEqual(per_reel_length("200/reel"), 200)
        self.assertEqual(per_reel_length("400m per drum"), 400)

    def test_code_only_row_is_a_continuation_not_a_new_line(self):
        # A row with only the ITEM CODE filled (description/qty blank) belongs to
        # the cable above it, not a section note.
        code = product_code_continuation(
            ["", "LP-XXXN/1C120EA", "", "", "", "", ""],
            self.HEADERS,
        )
        self.assertEqual(code, "LP-XXXN/1C120EA")

    def test_full_row_is_not_treated_as_continuation(self):
        self.assertEqual(
            product_code_continuation(
                ["ADMINISTRATION DB", "KL-PAXN/1C240OR", "1C 240 mm2 Cu X-HF-110 OG", "4", "19", "m", "76"],
                self.HEADERS,
            ),
            "",
        )

    def test_attach_product_code_folds_code_into_previous_item(self):
        item = {"text": "1C 120 mm2 Cu V-90HT Earth", "metadata": {"reference": "ADMINISTRATION DB"}}
        attach_product_code(item, "LP-XXXN/1C120EA")
        self.assertIn("LP-XXXN/1C120EA", item["text"])
        self.assertIn("1C 120 mm2 Cu V-90HT Earth", item["text"])
        self.assertEqual(item["metadata"]["product_code"], "LP-XXXN/1C120EA")

    def test_end_to_end_two_row_split_file_matches_provided_codes(self):
        # Corpus lives beside the repo, not inside it. Anchor on the repo so this
        # resolves on any machine; RFQ_SAMPLE_DIR overrides if it sits elsewhere.
        sample_dir = Path(
            os.environ.get(
                "RFQ_SAMPLE_DIR",
                Path(__file__).resolve().parents[2] / "sample rfq",
            )
        )
        source = sample_dir / "Excel - 85" / "21-Stuart-Tasmania-Test-Excel.xlsx"
        if not source.exists():
            self.skipTest("sample RFQ file not available in this environment")
        from rfq_parser.file_parser import parse_file

        items = parse_file(source)
        self.assertEqual(len(items), 10)
        # The three code-only continuation rows must not become their own items.
        earth = items[0]
        self.assertIn("LP-XXXN/1C120EA", earth["text"])
        self.assertEqual(earth["metadata"]["reference"], "ADMINISTRATION DB")
        self.assertEqual(earth["metadata"]["number_of_lengths"], 1)
        # Stale codes must not leak into later rows' context.
        for item in items[1:]:
            self.assertNotIn("LP-XXXN/1C120EA", item.get("context", []))


class TransposedCableTableTests(unittest.TestCase):
    def test_submain_schedule_is_detected_as_transposed(self):
        self.assertTrue(is_transposed_cable_table(_submain_table()))

    def test_row_wise_cable_table_is_not_transposed(self):
        row_wise = [
            ["Cable Type", "Length\n(M)"],
            ["FR 110° CU 4C+E 16mm2", "1904"],
            ["FR 110° CU 4C+E 25mm2", "1150"],
            ["XLPE CU EARTH 1C 25mm2", "2593"],
        ]
        self.assertFalse(is_transposed_cable_table(row_wise))

    def test_pivot_produces_one_item_per_cable(self):
        items = pivot_transposed_cable_table(_submain_table(), page_number=1)
        self.assertEqual(len(items), 3)

        c1 = items[0]
        self.assertEqual(c1["metadata"]["reference"], "Submain C1")
        for token in ("SDI Flex", "4C + E", "300mm²", "Copper"):
            self.assertIn(token, c1["text"])
        self.assertNotIn("Voltage drop", c1["text"])
        self.assertNotIn("MSB", c1["text"])

    def test_pivot_captures_length_and_routing_metadata(self):
        c1 = pivot_transposed_cable_table(_submain_table(), page_number=1)[0]
        self.assertEqual(c1["metadata"]["table_quantity"], 80)
        self.assertEqual(c1["metadata"]["table_unit"], "m")
        self.assertEqual(c1["metadata"]["from"], "L1 PS")
        self.assertEqual(c1["metadata"]["to"], "MSB")

    def test_parallel_runs_are_recorded(self):
        items = pivot_transposed_cable_table(_submain_table(), page_number=1)
        self.assertEqual(items[0]["metadata"]["parallel_runs"], 2)   # 2 x 300mm²
        self.assertNotIn("parallel_runs", items[1]["metadata"])       # 300mm²
        self.assertEqual(items[2]["metadata"]["parallel_runs"], 3)   # 3 x 300mm²

    @patch("rfq_parser.file_parser.pdfplumber.open")
    def test_parse_pdf_pivots_transposed_table_and_suppresses_flat_lines(self, open_pdf):
        page_text = (
            "DISTRIBUTION CABLE\n"
            "Submain C1 Submain C2 Submain C3\n"
            "Conductor size 2 x 300mm² 300mm² 3 x 300mm²\n"
            "N° of cores 4C + E 4C + E 4C + E\n"
            "Nominal length 80m 115m 50m\n"
        )
        open_pdf.return_value.__enter__.return_value.pages = [_FakePage(_submain_table(), page_text)]

        items = parse_pdf(Path("schedule.pdf"))
        references = [item.get("metadata", {}).get("reference") for item in items if item.get("metadata")]

        self.assertEqual(references, ["Submain C1", "Submain C2", "Submain C3"])
        # The flattened "Conductor size ..." page-text line must not leak back in.
        self.assertFalse(any("Conductor size" in item["text"] for item in items))


class ConstructionScheduleTests(unittest.TestCase):
    def _headers(self):
        return _construction_schedule_table()[0]

    def _build(self, row):
        return build_construction_schedule_items(row, self._headers(), ["Page 1"], 1)

    def test_headers_detected_as_construction_schedule(self):
        self.assertTrue(is_construction_schedule(self._headers()))

    def test_description_table_is_not_a_construction_schedule(self):
        self.assertFalse(is_construction_schedule(["Item", "Description", "Qty"]))

    def test_multicore_with_earth_adds_earth_as_a_core(self):
        # "4 core Circular" + a sized earth -> 5C, synthesised as 4C+E for the extractor.
        items = self._build(_construction_schedule_table()[2])  # 2 - LIFT 1/2
        self.assertEqual(len(items), 1)
        self.assertTrue(items[0]["text"].startswith("4C+E 25mm2 Cu"))
        self.assertEqual(items[0]["metadata"]["table_quantity"], 118.0)
        self.assertEqual(items[0]["metadata"]["reference"], "2 - LIFT 1/2")

    def test_from_to_and_cable_no_are_carried_for_comments(self):
        headers = ["From", "To", "Cable No", "Active", "Neutral", "Earth", "Insulation", "Configuration", "Length"]
        row = ["Supply", "MSB", "CM", "2 x 300mm2 Cu", "2 x 300mm2", "", "X-90 XLPE", "3 x 1 core Trefoil", "40m"]

        items = build_construction_schedule_items(row, headers, ["Page 1"], 1)

        self.assertEqual(items[0]["metadata"]["reference"], "CM")
        self.assertEqual(items[0]["metadata"]["from"], "Supply")
        self.assertEqual(items[0]["metadata"]["to"], "MSB")
        self.assertEqual(items[0]["metadata"]["number_of_lengths"], 8)

    def test_smaller_multicore_count(self):
        items = self._build(_construction_schedule_table()[4])  # 9 - FDCIE, 2 core
        self.assertTrue(items[0]["text"].startswith("2C+E 16mm2 Cu"))

    def test_trefoil_splits_active_runs_and_separate_earth(self):
        items = self._build(_construction_schedule_table()[3])  # 11 - DB-B-PV-L9
        self.assertEqual(len(items), 2)
        active, earth = items
        self.assertTrue(active["text"].startswith("1C 185mm2 Al"))
        self.assertEqual(active["metadata"]["number_of_lengths"], 4)
        self.assertTrue(earth["text"].startswith("1C 35mm2 Cu"))
        self.assertIn("earth", earth["text"].lower())
        self.assertNotIn("number_of_lengths", earth["metadata"])

    def test_trefoil_without_earth_has_no_second_line(self):
        items = self._build(_construction_schedule_table()[1])  # 1 - MSB, earth N/A
        self.assertEqual(len(items), 1)
        self.assertTrue(items[0]["text"].startswith("1C 400mm2 Cu"))
        self.assertEqual(items[0]["metadata"]["number_of_lengths"], 12)
        self.assertNotIn("table_quantity", items[0]["metadata"])  # "Completed" is not a length

    def test_non_cable_row_yields_nothing(self):
        self.assertEqual(self._build(["Note", "see RFI", "", "", "", "", "", ""]), [])

    @patch("rfq_parser.file_parser.pdfplumber.open")
    def test_parse_pdf_builds_rows_and_skips_text_layer(self, open_pdf):
        page_text = (
            "Cable No Active Neutral Earth Insulation Configuration\n"
            "2 - LIFT 1/2 1 x 25mm² Cu 1 x 25mm² 1 x 6mm² Cu R-HF-110 Elastomer 4 core Circular 118.00m\n"
        )
        open_pdf.return_value.__enter__.return_value.pages = [
            _FakePage(_construction_schedule_table(), page_text)
        ]
        items = parse_pdf(Path("schedule.pdf"))
        texts = [item["text"] for item in items]
        self.assertTrue(any(text.startswith("4C+E 25mm2 Cu") for text in texts))
        # The reflowed text-layer line must not leak back in as its own item.
        self.assertFalse(any("Configuration" in text for text in texts))


class RoleBasedHeaderDetectionTests(unittest.TestCase):
    """looks_like_header works per cell on label roles, not on substrings of
    the joined row (corpus audit: docs/table-shape-audit/FINDINGS.md)."""

    def test_data_row_with_conductor_word_is_not_a_header(self):
        # The 85-Sohil hijack: "to" in "conductor" scored 2 substring terms and
        # this data row became the header, destructuring the 29 rows after it.
        self.assertFalse(looks_like_header(["1C 240 Earth Conductor, Cu, PVC/PVC", "30"]))

    def test_spec_data_rows_are_not_headers(self):
        for row in [
            ["120MM XLPE/PVC FLEX 4 X 90MTRS", "100"],
            ["185mm earth ALI", "8 X 40"],
            ["CABLE,FLEX 1C 6.00MM2 0.6/1KV 110DEG", "100 M", "100"],
            ["4C 10mm2 Cu. FIRE RATED CABLE", "134"],
            ["1C 120mm2 Cu Earth cable", "400"],
            ["83", "400"],
            ["XLPE Copper Flex 185mm2 - 8 x 240m (1920m total)", "8"],
        ]:
            self.assertFalse(looks_like_header(row), row)

    def test_long_cells_cannot_be_header_labels(self):
        # Standards list row: "Conductors in insulated electric cables..." is
        # over the label-length cap, so its attribute words don't count.
        self.assertFalse(
            looks_like_header(
                ["AS/NZS 1125", "Conductors in insulated electric cables and flexible cords.", "False"]
            )
        )

    def test_terse_two_column_takeoff_headers_are_detected(self):
        # The dominant corpus family: ~30 xlsx files whose whole sheet fell to
        # raw text because these headers scored below the old 2-term threshold.
        for row in [
            ["Details", "Length"],
            ["Cable", "Qty"],
            ["Cable", "Quantity"],
            ["Data", "qty"],
            ["DES", "QTY"],
            ["Desc", "QTY"],
            ["Type", "Qty"],
            ["Product", "Quantity"],
            ["Cabling", "Qty"],
            ["SUBMAIN CABLE", "QTY"],
            ["Cable Description", "Metres"],
            ["Cable", "Unit", "Quantity"],
            ["ItemID", "Length"],
        ]:
            self.assertTrue(looks_like_header(row), row)

    def test_attribute_schedule_headers_are_detected(self):
        for row in [
            [
                "Cable No.", "Supply From", "Supply To", "Cores",
                "Active/Neutral [mm2] Cable Size", "Earth Size [mm2]",
                "Insulation", "Conductor", "Cable Length [m]",
            ],
            [
                "Designation", "From", "To", "Cable Selection (cables incl. neutrals)",
                "Cable installation", "Length (m)",
            ],
            ["Cable Size & # of cores", "Length per conductor (m)"],
            [
                "Cable No", "Active", "Neutral", "Earth", "Insulation",
                "Configuration", "Original Length", "Accurate Length",
            ],
        ]:
            self.assertTrue(looks_like_header(row), row)

    def test_existing_header_shapes_still_detected(self):
        for row in [
            ["Cable Type", "Length\n(M)"],
            ["QUANTITY (In Metres)", "TYPES OF CABLES"],
            ["S/N", "Name", "Qty", "Units"],
            ["Description", "UOM", "Qty"],
            ["Number", "Descriprtion", "Quantity"],  # misspelled Description
            ["Description", "Units", "Spare (O&M)", "Total"],
            ["Cable ID", "ITEM CODE", "DESCRIPTION", "runs", "Qty per run", "Unit", "Sum of Total Required"],
        ]:
            self.assertTrue(looks_like_header(row), row)

    def test_metres_header_maps_to_quantity(self):
        self.assertEqual(header_role("Metres"), "quantity")
        # ... but only as an exact label: a diameter column is not a quantity.
        self.assertNotEqual(header_role("Outside Diameter (mm)"), "quantity")

    def test_terse_description_aliases_are_exact_only(self):
        self.assertEqual(header_role("Cable"), "description")
        self.assertEqual(header_role("Details"), "description")
        self.assertEqual(header_role("TRICABLE"), "other")
        self.assertEqual(header_role("4C 10mm2 Cu. FIRE RATED CABLE"), "other")

    def test_prospective_fault_is_not_a_description_column(self):
        # "spec" is a substring of "prospective"; PowerCAD fault columns must
        # not leak kA figures into the cable description.
        self.assertEqual(header_role("Prospective Fault at End of Cable (kA)"), "other")
        self.assertEqual(header_role("Spec"), "description")
        self.assertEqual(header_role("Specification"), "description")

    def test_terse_header_rows_build_structured_items(self):
        item = build_structured_xlsx_item(
            ["16mm 4c+E XLPE/PVC", "650"],
            ["Cable Description", "Metres"],
        )
        self.assertIsNotNone(item)
        self.assertEqual(item["metadata"]["table_quantity"], 650)
        self.assertEqual(item["metadata"]["table_unit"], "m")

        item = build_structured_xlsx_item(["185mm ALI", "320"], ["DES", "QTY"])
        self.assertIsNotNone(item)
        self.assertEqual(item["text"], "185mm ALI")
        self.assertEqual(item["metadata"]["table_quantity"], 320)

    def test_descless_schedule_rows_carry_the_length_column(self):
        # PowerCAD-style sheets have no description column, so rows stay raw
        # text -- but the recognized "Length [m]" column must ride along as
        # table_quantity so the pipeline's trailing-number heuristic doesn't
        # recover the voltage-drop figure (1.19) as the line's length.
        from rfq_parser.file_parser import schedule_row_metadata

        headers = [
            "Cable Index", "From", "To", "Cores", "Conductor", "Insulation",
            "Active/Neutral Size [mm2]", "Earth Size [mm2]", "Length [m]",
            "Voltage Drop VD [%]",
        ]
        metadata = schedule_row_metadata(
            ["2", "MSB", "Lift 2", "Circ, 4C&E", "Cu", "RADOX", "16", "10", "160", "1.19"],
            headers,
        )
        self.assertEqual(metadata["table_quantity"], 160)
        self.assertEqual(metadata["table_quantity_header"], "Length [m]")
        self.assertEqual(metadata["table_unit"], "m")
        self.assertEqual(metadata["from"], "MSB")
        self.assertEqual(metadata["to"], "Lift 2")
        # A row with no value in the length column stays bare -- attaching a
        # zero would silently drop the line downstream.
        self.assertEqual(
            schedule_row_metadata(
                ["Transformer: T1", "Wt Cu = 37898 kg", "", "", "", "", "", "", "", ""],
                headers,
            ),
            {},
        )

    def test_total_quantity_column_wins_over_per_run_length(self):
        # "Length" is the per-run figure; "Total M" is the purchase quantity.
        item = build_structured_xlsx_item(
            ["C3", "SMSB", "FIP", "2c+e x-110", "16mm2", "45", "1", "120"],
            ["Cable", "From", "To", "Type", "Size", "Length", "Qty", "Total M"],
        )
        self.assertIsNotNone(item)
        self.assertEqual(item["metadata"]["table_quantity"], 120)
        self.assertEqual(item["metadata"]["table_quantity_header"], "Total M")

    def test_conductor_data_row_does_not_hijack_header_mid_sheet(self):
        # End-to-end reproduction of 85-Sohil: a data row containing
        # "Conductor" must not reset the header, so every row keeps its
        # structured quantity.
        import tempfile

        from openpyxl import Workbook

        from rfq_parser.file_parser import parse_xlsx

        workbook = Workbook()
        sheet = workbook.active
        sheet.append(["Cable Type", "Total Length"])
        sheet.append(["1C 500 Cu XLPE X90/PVC", 120])
        sheet.append(["1C 240 Earth Conductor, Cu, PVC/PVC", 30])
        sheet.append(["3C+E 95 Cu XLPE/PVC", 30])
        sheet.append(["12C 1.5 PVC/PVC", 770])
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "schedule.xlsx"
            workbook.save(path)
            items = parse_xlsx(path)

        self.assertEqual(len(items), 4)
        for item in items:
            self.assertIn("table_quantity", item.get("metadata", {}), item["text"])
        self.assertEqual(items[1]["text"], "1C 240 Earth Conductor, Cu, PVC/PVC")
        self.assertEqual(items[1]["metadata"]["table_quantity"], 30)
        self.assertEqual(items[3]["metadata"]["table_quantity"], 770)

    def test_active_earth_summary_table_splits_rows_into_two_cables(self):
        import tempfile

        from openpyxl import Workbook

        workbook = Workbook()
        sheet = workbook.active
        sheet.append(["Total Active Length (m)", "Earth Length (m)", "Cable Type", "Cable Size", "Earth Size"])
        sheet.append([104, 26, "XLPE", "4x1C 70mm", "25mm"])
        sheet.append([1160, 290, "XLPE", "2x 4x1C 185mrn", "70mm"])
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "active-earth-summary.xlsx"
            workbook.save(path)
            items = parse_xlsx(path)

        self.assertEqual([item["text"] for item in items], [
            "XLPE 1C 70mm",
            "1C 25mm earth",
            "XLPE 1C 185mm",
            "1C 70mm earth",
        ])
        self.assertEqual(items[0]["metadata"]["table_quantity"], 26)
        self.assertEqual(items[0]["metadata"]["number_of_lengths"], 4)
        self.assertEqual(items[0]["metadata"]["source_total_length"], 104)
        self.assertEqual(items[1]["metadata"]["table_quantity"], 26)
        self.assertEqual(items[1]["metadata"]["number_of_lengths"], 1)
        self.assertEqual(items[2]["metadata"]["table_quantity"], 145)
        self.assertEqual(items[2]["metadata"]["number_of_lengths"], 8)
        self.assertEqual(items[3]["metadata"]["table_quantity"], 145)
        self.assertEqual(items[3]["metadata"]["number_of_lengths"], 2)


class QuantityRankingTests(unittest.TestCase):
    """Typed quantity-column selection (audit fix #3)."""

    def test_cuts_column_with_arithmetic_proof_picks_per_run_length(self):
        # 26-Neil quote import view: # Lengths 4 | Length 400 | Quantity 1600.
        # No description column, so the row stays raw text -- the metadata
        # must still carry 4 cuts x 400 m, not table_quantity=4.
        from rfq_parser.file_parser import schedule_row_metadata

        metadata = schedule_row_metadata(
            ["XL-PAXN/1C240OR", "4", "400", "1600"],
            ["Item Number", "# Lengths", "Length", "Quantity"],
        )
        self.assertEqual(metadata["table_quantity"], 400)
        self.assertEqual(metadata["number_of_lengths"], 4)

    def test_order_quantity_outranks_takeoff_quantity(self):
        # 27-Neil: QUANTITY (m) is the takeoff; ORDER QUANTITY (m) is the buy.
        item = build_structured_xlsx_item(
            ["CPD-02", "CAT 6 STP", "1083", "1400"],
            ["TYPE", "Description", "QUANTITY (m)", "ORDER QUANTITY (m)"],
        )
        self.assertEqual(item["metadata"]["table_quantity"], 1400)

    def test_length_qty_total_triple_derives_cut_count(self):
        # 23-Allison: Length 15 | Qty 8 | Total M 120 -> 8 cuts of 15 m.
        item = build_structured_xlsx_item(
            ["2c+e x-110", "15", "8", "120"],
            ["Type", "Length", "Qty", "Total M"],
        )
        self.assertEqual(item["metadata"]["table_quantity"], 15)
        self.assertEqual(item["metadata"]["number_of_lengths"], 8)

    def test_total_still_wins_without_arithmetic_proof(self):
        item = build_structured_xlsx_item(
            ["2c+e x-110", "45", "1", "120"],
            ["Type", "Length", "Qty", "Total M"],
        )
        self.assertEqual(item["metadata"]["table_quantity"], 120)

    def test_quantity_cell_with_cuts_notation_splits(self):
        # 44-Amanda: a qty cell of "8 X 40" is 8 cuts of 40 m, not 8 m.
        item = build_structured_xlsx_item(
            ["185mm ALI", "8 X 40"],
            ["DES", "QTY"],
        )
        self.assertEqual(item["metadata"]["table_quantity"], 40)
        self.assertEqual(item["metadata"]["number_of_lengths"], 8)

    def test_earth_length_column_never_wins_the_quantity_role(self):
        from rfq_parser.file_parser import header_role

        self.assertEqual(header_role("Earth Length/M"), "earth_length")
        self.assertEqual(header_role("Earth Size/mm2"), "earth_size")
        self.assertEqual(header_role("Earth-no. of ccts"), "earth_cuts")
        self.assertEqual(header_role("# Lengths"), "cuts")
        self.assertEqual(header_role("A/N-no. of ccts"), "cuts")


class ColumnEarthRecompositionTests(unittest.TestCase):
    """Type | Size | Earth tables recompose to the inline parallel form (fix #9)."""

    HEADERS = ["Cable", "Type", "Size", "Earth", "Length", "Qty", "Total M"]

    def test_parallel_single_core_row_recomposes(self):
        item = build_structured_xlsx_item(
            ["C5", "4x1c+e XLPE X-90", "50mm2", "16mm2", "45", "1", "45"],
            self.HEADERS,
        )
        self.assertTrue(item["text"].startswith("4x 50mm2 + 1x 16mm2 earth"))
        self.assertIn("XLPE X-90", item["text"])
        self.assertEqual(item["metadata"]["source_table_shape"], "column_earth")

    def test_nested_multiplier_lifts_counts(self):
        item = build_structured_xlsx_item(
            ["C8", "3x4x1c+e X-90", "240mm2", "185mm2", "70", "4", "280"],
            self.HEADERS,
        )
        self.assertTrue(item["text"].startswith("12x 240mm2 + 3x 185mm2 earth"))

    def test_multicore_without_earth_marker_gains_added_earth(self):
        item = build_structured_xlsx_item(
            ["C9", "4c X-110", "35mm2", "16mm2", "60", "1", "60"],
            self.HEADERS,
        )
        self.assertIn("+ EARTH", item["text"])

    def test_multicore_with_counted_earth_is_left_alone(self):
        item = build_structured_xlsx_item(
            ["C3", "2c+e x-110", "16mm2", "6mm2", "45", "1", "45"],
            self.HEADERS,
        )
        self.assertEqual(item["text"], "C3 2c+e x-110 16mm2")
        self.assertNotIn("source_table_shape", item["metadata"])

    def test_no_earth_cell_means_no_recomposition(self):
        for cell in ("na", "No Earth", "-", ""):
            item = build_structured_xlsx_item(
                ["C2", "6x4x1c X-110", "400mm2", cell, "15", "8", "120"],
                self.HEADERS,
            )
            self.assertNotIn("earth", item["text"].lower(), cell)


class DecomposedXlsxScheduleTests(unittest.TestCase):
    """Cores | Size | Earth Size | Length schedules rebuild on xlsx (fix #4)."""

    HEADERS = [
        "", "CABLE NO.", "SUPPLY FROM", "SUPPLY TO", "CORES",
        "ACTIVE/NEUTRAL [mm2] CABLE SIZE", "EARTH SIZE [mm2]",
        "INSULATION", "CONDUCTOR", "CABLE LENGTH [m]",
    ]

    def _build(self, row):
        from rfq_parser.file_parser import (
            build_decomposed_schedule_items,
            decomposed_schedule_columns,
        )

        columns = decomposed_schedule_columns(self.HEADERS)
        self.assertTrue(columns)
        return build_decomposed_schedule_items(row, self.HEADERS, columns)

    def test_multicore_with_sized_earth_is_one_nce_line(self):
        # "Circ, 4C&E" + earth size 6 -> the earth is a core, one line.
        items = self._build(
            ["", "SWHB-1", "MSB-01", "MSSB-01", "Circ, 4C&E", "16", "6", "X-90", "Cu", "70"]
        )
        self.assertEqual(len(items), 1)
        self.assertTrue(items[0]["text"].startswith("4C+E 16mm2 Cu X-90"))
        self.assertEqual(items[0]["metadata"]["table_quantity"], 70)
        self.assertEqual(items[0]["metadata"]["reference"], "SWHB-1")

    def test_sdi_single_with_sized_earth_gets_separate_earth_line(self):
        items = self._build(
            ["", "G1-1.A", "G-01", "GENSWB-01", "Flex S.D.I.", "500", "120", "X-HF-110", "Cu", "120"]
        )
        self.assertEqual(len(items), 2)
        active, earth = items
        self.assertTrue(active["text"].startswith("1C 500mm2 Cu X-HF-110"))
        self.assertIn("SDI", active["text"])
        self.assertEqual(active["metadata"]["table_quantity"], 120)
        self.assertTrue(earth["text"].startswith("1C 120mm2 Cu X-HF-110 earth"))
        self.assertEqual(earth["metadata"]["table_quantity"], 120)

    def test_parallel_size_prefix_becomes_cut_count(self):
        # 63-Hayden: size "3x400" = three parallel 400mm2 conductors.
        items = self._build(
            ["", "1", "T1", "MSB (Bus A)", "S.D.I", "3x400", "No Earth", "XLPE 90", "Cu", "65"]
        )
        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]["metadata"]["table_quantity"], 65)
        self.assertEqual(items[0]["metadata"]["number_of_lengths"], 3)

    def test_non_cable_row_yields_nothing(self):
        self.assertEqual(
            self._build(["", "Transformer: T1", "Wt Cu = 37898 kg", "", "", "", "", "", "", ""]),
            [],
        )

    def test_run_and_total_columns_prove_the_cut_count(self):
        # 65-Julie: cores "4 x 4 x 1C + 3 x E", run 40, total 640 -> 16 x 40,
        # earth total 120 with 3 explicit earths -> 3 x 40.
        from rfq_parser.file_parser import (
            build_decomposed_schedule_items,
            decomposed_schedule_columns,
        )

        headers = [
            "Cable ID", "Insulation Type", "Cu Core Count", "Core Size/mm2",
            "Run Length/m", "Total Core Length/m", "Earth Size/mm2", "Earth Length/M",
        ]
        columns = decomposed_schedule_columns(headers)
        items = build_decomposed_schedule_items(
            ["C001", "RADOX", "4 x 4 x 1C + 3 x E", "630", "40", "640", "240", "120"],
            headers,
            columns,
        )
        self.assertEqual(len(items), 2)
        active, earth = items
        self.assertTrue(active["text"].startswith("1C 630mm2 Cu RADOX"))
        self.assertEqual(active["metadata"]["table_quantity"], 40)
        self.assertEqual(active["metadata"]["number_of_lengths"], 16)
        self.assertTrue(earth["text"].startswith("1C 240mm2"))
        self.assertEqual(earth["metadata"]["table_quantity"], 40)
        self.assertEqual(earth["metadata"]["number_of_lengths"], 3)

    def test_description_column_disqualifies_the_decomposed_path(self):
        from rfq_parser.file_parser import decomposed_schedule_columns

        self.assertEqual(
            decomposed_schedule_columns(["Description", "Cores", "Size", "Length"]),
            {},
        )


class CsvStructuredTests(unittest.TestCase):
    def test_clean_csv_header_produces_structured_items(self):
        import tempfile

        content = (
            "Item ID,Part No.,Item Name,Quantity\n"
            "1278469,OLXBZHE89AA001CXNA,185mm CU Flex XLPE,720\n"
            "1278472,639055,185mm Bellmouth Lug M10,16\n"
        )
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "order.csv"
            path.write_text(content, encoding="utf-8")
            from rfq_parser.file_parser import parse_csv

            items = parse_csv(path)

        self.assertEqual(len(items), 2)
        self.assertEqual(items[0]["text"], "185mm CU Flex XLPE")
        self.assertEqual(items[0]["metadata"]["table_quantity"], 720)
        self.assertEqual(items[0]["metadata"]["reference"], "1278469")


class JunkSheetFlagTests(unittest.TestCase):
    def _sheet_items(self, rows):
        from rfq_parser.file_parser import _tabular_items

        return _tabular_items(rows, sheet_title="Sheet1")

    def test_sheet_without_cable_signal_is_flagged(self):
        items = self._sheet_items(
            [
                ["Supplier Questionnaire", ""],
                ["Do you comply with AS/NZS ISO 9001?", "Yes"],
                ["#REF!", "#REF!"],
            ]
        )
        self.assertTrue(items)
        for item in items:
            self.assertTrue(item["metadata"]["low_relevance_sheet"], item["text"])

    def test_sheet_with_cable_signal_is_not_flagged(self):
        items = self._sheet_items(
            [
                ["Cable", "Qty"],
                ["4C+E 95mm2 Cu XLPE", "120"],
            ]
        )
        self.assertTrue(items)
        for item in items:
            self.assertNotIn("low_relevance_sheet", item.get("metadata") or {})


class PriceColumnTests(unittest.TestCase):
    """Money columns ("Unit Rate", "Total" beside a quantity) stay out of the
    fallback joined text: an unpriced schedule's "0 | 0" rate cells otherwise
    read as an explicit 0m length downstream (MSB-D1 submains)."""

    _headers = [
        "CABLE REFERENCE", "SOURCE", "DESTINATION", "ACTIVE", "NEUTRAL", "EARTH",
        "CONFIGURATION", "INSULATION", "LENGTH", "TOTAL METERS", "UNIT RATE", "TOTAL",
    ]

    def test_money_headers_take_the_price_role(self):
        for header in ["UNIT RATE", "Unit Price", "Cost ($)", "Price ea", "Ex GST"]:
            self.assertEqual(header_role(header), "price", header)

    def test_quantity_and_rated_headers_are_not_price(self):
        self.assertEqual(header_role("TOTAL METERS"), "quantity")
        self.assertNotEqual(header_role("FIRE RATED"), "price")

    def test_bare_total_is_price_only_beside_a_quantity_column(self):
        with_quantity = price_column_indexes(self._headers)
        self.assertIn(self._headers.index("UNIT RATE"), with_quantity)
        self.assertIn(self._headers.index("TOTAL"), with_quantity)
        # Without a quantity sibling, "Total" may be the sheet's only metres
        # column -- it must stay in the text.
        self.assertEqual(price_column_indexes(["Cable", "Total"]), set())

    def test_zero_rate_cells_are_excluded_from_fallback_text(self):
        # No CONFIGURATION column, so this is not a construction schedule and
        # the row takes the raw-join fallback -- where the "0 | 0" rate cells
        # must not leak into the text (they read as an explicit 0m length).
        headers = [
            "CABLE REFERENCE", "SOURCE", "DESTINATION", "EARTH",
            "LENGTH", "TOTAL METERS", "UNIT RATE", "TOTAL",
        ]
        rows = [
            headers,
            ["D1-7 EARTH", "MSB-D1", "MDB-UPS-MED-INPUT", "1 x 70mm² Cu Flex", "10", "10", "0", "0"],
        ]
        items = _tabular_items(rows)

        self.assertEqual(len(items), 1)
        self.assertNotIn("| 0", items[0]["text"])
        self.assertIn("70mm² Cu Flex", items[0]["text"])
        self.assertEqual(items[0]["metadata"]["table_quantity"], 10)
        self.assertEqual(items[0]["metadata"]["table_quantity_header"], "TOTAL METERS")


class XlsxConstructionScheduleTests(unittest.TestCase):
    """Construction schedules in xlsx route through the same rebuild as PDFs
    (MSB-D1 submains): raw joins leaked the EARTH cell into the main cable's
    text and misread TOTAL METERS as a per-cut length."""

    _headers = [
        "CABLE REFERENCE", "SOURCE", "DESTINATION", "ACTIVE", "NEUTRAL", "EARTH",
        "CONFIGURATION", "INSULATION", "LENGTH", "TOTAL METERS", "UNIT RATE", "TOTAL",
    ]

    def _items(self, row):
        return _tabular_items([self._headers, row], sheet_title="Sheet")

    def test_trefoil_counts_are_absolute_when_total_meters_confirms(self):
        # 24 actives + 8 neutrals at 37m each = 1184 total: the counts already
        # include the phases, so the trefoil's "3 x" must not multiply them.
        items = self._items([
            "TX-1 FIRE RATED", "2000kVA TRANSFORMER", "MSB-D1",
            "24 x 300mm² Cu Flex", "8 x 300mm² Cu Flex", "1 x 120mm² Cu TO EARTH STAKE",
            "3 x 1 core Trefoil", "X-HF-110 XLPE", "37", "1184", "0", "0",
        ])

        self.assertEqual(len(items), 2)
        active, earth = items
        self.assertEqual(active["text"], "1C 300mm2 Cu X-HF-110 XLPE")
        self.assertEqual(active["metadata"]["number_of_lengths"], 32)
        self.assertEqual(active["metadata"]["table_quantity"], 37)
        self.assertEqual(earth["text"], "1C 120mm2 Cu X-HF-110 XLPE earth")
        self.assertEqual(earth["metadata"]["table_quantity"], 37)

    def test_trefoil_counts_stay_per_phase_without_a_confirming_total(self):
        # 4 x (route 10m) with total 120 = 12 conductors x 10m: the counts are
        # per trefoil group, so the phase multiplication must still apply.
        items = self._items([
            "D1-X", "MSB-D1", "DB-1", "4 x 185mm² Cu Flex", "", "",
            "3 x 1 core Trefoil", "X-90 XLPE", "10", "120", "0", "0",
        ])

        self.assertEqual(items[0]["metadata"]["number_of_lengths"], 12)

    def test_earth_only_row_is_a_cut_at_the_route_length(self):
        # "G-1 EARTH | 6 x 120mm² | 45 | 270" asks for six 45m cuts. Read via
        # the bulk TOTAL METERS column it loses the run count and gets rounded
        # to prepack spools -- asked-for cuts must be quoted as asked.
        items = _tabular_items([
            self._headers,
            ["G-1 EARTH", "", "", "", "", "6 x 120mm² Cu Flex", "", "", "45", "270", "0", "0"],
        ])

        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]["text"], "1C 120mm2 Cu earth")
        self.assertEqual(items[0]["metadata"]["table_quantity"], 45)
        self.assertEqual(items[0]["metadata"]["number_of_lengths"], 6)
        self.assertEqual(items[0]["metadata"]["reference"], "G-1 EARTH")

    def test_earth_row_inherits_fire_marker_from_its_cable_row(self):
        items = _tabular_items([
            self._headers,
            [
                "G-1 FIRE RATED", "GENERATOR 1", "MDB-GEN", "18 x 300mm² Cu Flex",
                "6 x 300mm² Cu Flex", "", "3 x 1 core Trefoil", "X-HF-110 XLPE",
                "45", "1080", "0", "0",
            ],
            ["G-1 EARTH", "", "", "", "", "6 x 120mm² Cu Flex", "", "", "45", "270", "0", "0"],
            ["D1-9 XLPE", "MSB-D1", "DB-2N", "3 x 70mm² Cu Flex", "1 x 70mm² Cu Flex",
             "", "3 x 1 core Trefoil", "X-90 XLPE", "15", "60", "0", "0"],
            ["D1-9 EARTH", "", "", "", "", "1 x 25mm² Cu Flex", "", "", "15", "15", "0", "0"],
        ])

        by_ref = {item["metadata"]["reference"]: item for item in items}
        self.assertIn("FIRE RATED", by_ref["G-1 EARTH"]["context"])
        # A cool XLPE sibling passes no marker -- its earth stays the standard LP.
        self.assertIn("PAIRED MAINS CABLE", by_ref["G-1 EARTH"]["context"])
        self.assertIn("PAIRED MAINS CABLE", by_ref["D1-9 EARTH"]["context"])
        self.assertNotIn("FIRE RATED", by_ref["D1-9 EARTH"]["context"])
        self.assertNotIn("HIGH TEMP", by_ref["D1-9 EARTH"]["context"])

    def test_multicore_row_folds_earth_as_a_core(self):
        items = self._items([
            "D1-4 FIRE RATED", "MSB-D1", "MSSB-TO-L5.5", "3 x 10mm² Cu Flex",
            "1 x 10mm² Cu Flex", "1 x 4mm² Cu Flex", "4 core Circular",
            "X-HF-110 XLPE", "121", "121", "0", "0",
        ])

        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]["text"], "4C+E 10mm2 Cu X-HF-110 XLPE")
        self.assertEqual(items[0]["metadata"]["table_quantity"], 121)
        self.assertEqual(items[0]["metadata"]["reference"], "D1-4 FIRE RATED")
        self.assertIn("D1-4 FIRE RATED", items[0]["context"])


class CoresColumnScheduleTests(unittest.TestCase):
    """Construction schedules that state their conductor counts in their own columns.

    The sizes sit in "Active (mm²)"/"Earth (mm²)" as bare numbers (the unit is in the
    header), the counts in "Active + Neutral cores"/"Earth cores", and the per-cut
    distance in "Route Length (m)". The configuration cell holds a construction class
    ("Flex S.D.I."), not a core count, so the trefoil/multicore branches cannot read it.
    Rows are from a real aluminium submains schedule TriCab quoted.
    """

    _headers = [
        "Item", "Cable Ref", "From", "To", "Route Length (m)", "Conductor", "Construction",
        "Active (mm²)", "Neutral (mm²)", "Earth (mm²)", "Insulation", "Nom. OD (mm)",
        "Active + Neutral cores", "Earth cores", "Total cable (m)",
    ]

    def _items(self, row):
        return _tabular_items([self._headers, row], sheet_title="Cable Schedule")

    def test_row_splits_into_phase_and_earth_at_the_stated_counts(self):
        # 4x300 actives + 4x300 neutrals = 16 singles, plus 1 earth, over a 35 m route.
        items = self._items([
            "1", "N01", "New MSB-MS1", "MS1-M-L02-K2", "35", "Al", "Flex S.D.I.",
            "4×300", "4×300", "300", "LSFLEX® R-70", "33.9", "16", "1", "595",
        ])

        self.assertEqual(len(items), 2)
        phase, earth = items
        self.assertEqual(phase["text"], "1C 300mm2 Al LSFLEX® R-70")
        self.assertEqual(phase["metadata"]["number_of_lengths"], 16)
        self.assertEqual(phase["metadata"]["table_quantity"], 35)
        self.assertEqual(phase["metadata"]["table_quantity_header"], "Route Length")
        self.assertEqual(earth["text"], "1C 300mm2 Al earth")
        self.assertEqual(earth["metadata"]["table_quantity"], 35)
        self.assertEqual(earth["metadata"]["reference"], "N01 earth")

    def test_nominal_diameter_column_is_never_read_as_a_length(self):
        # "Nom. OD (mm)" 33.9 is a diameter. Left to the raw-join fallback it was taken
        # as the cut length, quoting 33.9 m of cable against a 35 m route.
        items = self._items([
            "1", "N01", "A", "B", "35", "Al", "Flex S.D.I.",
            "4×300", "4×300", "300", "LSFLEX® R-70", "33.9", "16", "1", "595",
        ])
        for item in items:
            self.assertEqual(item["metadata"]["table_quantity"], 35)

    def test_multiple_earth_cores_carry_their_own_run_count(self):
        items = self._items([
            "3", "N04", "A", "B", "30", "Al", "Flex S.D.I.",
            "4×400", "4×400", "2×150", "LSFLEX® R-70", "38.4", "16", "2", "540",
        ])
        self.assertEqual(len(items), 2)
        earth = items[1]
        self.assertEqual(earth["text"], "1C 150mm2 Al earth")
        self.assertEqual(earth["metadata"]["number_of_lengths"], 2)
        self.assertEqual(earth["metadata"]["table_quantity"], 30)

    def test_sheet_total_column_is_not_used_as_the_phase_quantity(self):
        # "Total cable (m)" counts the earth conductors too (17 x 35 = 595), so using it
        # for the phase line would double-count them. 16 x 35 + 1 x 35 = 595 reconciles.
        items = self._items([
            "1", "N01", "A", "B", "35", "Al", "Flex S.D.I.",
            "4×300", "4×300", "300", "LSFLEX® R-70", "33.9", "16", "1", "595",
        ])
        phase, earth = items
        phase_m = phase["metadata"]["table_quantity"] * phase["metadata"]["number_of_lengths"]
        earth_m = earth["metadata"]["table_quantity"] * earth["metadata"].get("number_of_lengths", 1)
        self.assertEqual(phase_m, 560)
        self.assertEqual(phase_m + earth_m, 595)

    def test_row_without_a_sized_earth_is_one_line(self):
        items = self._items([
            "9", "N11", "A", "B", "140", "Al", "Flex S.D.I.",
            "240", "240", "N/A", "LSFLEX® R-70", "30.1", "4", "", "560",
        ])
        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]["text"], "1C 240mm2 Al LSFLEX® R-70")

    def test_split_active_and_neutral_sizes_fall_through(self):
        # One count cannot be apportioned between two different sizes, so the row is
        # left to the configuration-driven branches rather than guessed at.
        items = self._items([
            "1", "N01", "A", "B", "35", "Al", "Flex S.D.I.",
            "4×300", "4×185", "300", "LSFLEX® R-70", "33.9", "16", "1", "595",
        ])
        self.assertNotIn("1C 300mm2 Al LSFLEX® R-70", [item["text"] for item in items])


class XlsParserTests(unittest.TestCase):
    """Legacy .xls (BIFF) attachments — parsed via xlrd into the same tabular
    machinery as xlsx, with visible-sheet-only policy and int-clean numbers.

    Reads `tests/fixtures/xls/legacy-schedule.xls`. These tests were previously written
    against a workbook built with `xlwt` and skipped on every machine that did not have
    that writer installed, which is to say they were skipped everywhere -- and .xls is the
    format one customer sends most. The cell-typing rules they cover now live in
    `matcher/xls_convert.xls_cell_value`, shared with the reader's .xls conversion, so
    this is also the check that the two readers of one .xls agree about its cells.
    """

    FIXTURE = Path(__file__).parent / "fixtures" / "xls" / "legacy-schedule.xls"

    def setUp(self):
        self.items = parse_file(self.FIXTURE)

    def test_parse_file_routes_xls_and_structures_rows(self):
        row = next(item for item in self.items if item["row_number"] == 3)
        self.assertEqual(row["text"], "LSZH FLEXIBLE BUILDING WIRE 110d 6MM")
        self.assertEqual(row["metadata"]["table_quantity"], 2100)
        self.assertEqual(row["metadata"]["table_unit"], "M")

    def test_whole_floats_do_not_leak_decimal_points(self):
        self.assertNotIn("2100.0", " ".join(item["text"] for item in self.items))

    def test_real_fractions_are_kept(self):
        row = next(item for item in self.items if item["row_number"] == 4)
        self.assertEqual(row["metadata"]["table_quantity"], 70.5)

    def test_a_date_cell_is_a_date_not_a_serial_number(self):
        row = next(item for item in self.items if item["row_number"] == 5)
        self.assertIn("2026-04-26", row["text"])
        self.assertNotIn("46138", row["text"])

    def test_a_boolean_cell_is_not_a_quantity(self):
        row = next(item for item in self.items if item["row_number"] == 5)
        self.assertIsNone(row["metadata"]["table_quantity"])

    def test_hidden_sheets_are_skipped(self):
        self.assertFalse(any("STALE HIDDEN ROW" in item["text"] for item in self.items))


class XlsmRoutingTests(unittest.TestCase):
    """.xlsm is the same OOXML openpyxl already reads; it differs only in carrying a macro
    part. It had a terra route (`attachment_reader.READER_SUFFIXES`) and no deterministic
    route at all, so with the reader off or failing a macro-enabled workbook returned
    "Unsupported file type" and the message contributed nothing."""

    def _xlsm(self, tmpdir: str) -> Path:
        from openpyxl import Workbook

        workbook = Workbook()
        sheet = workbook.active
        sheet.append(["Description", "Size", "Quantity", "U/M"])
        sheet.append(["LSZH FLEXIBLE BUILDING WIRE 110d", "6MM", 2100, "M"])
        path = Path(tmpdir) / "schedule.xlsm"
        workbook.save(str(path))
        return path

    def test_parse_file_structures_an_xlsm_rather_than_refusing_it(self):
        with tempfile.TemporaryDirectory() as tmpdir:
            items = parse_file(self._xlsm(tmpdir))
        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]["metadata"]["table_quantity"], 2100)
        self.assertNotIn("Unsupported file type", items[0]["text"])


class RouteScheduleTests(unittest.TestCase):
    """Drawing cable schedules (FROM | TO | Main | Earth) with FreeText take-off
    notes carrying the route lengths (the WARRENS take-off shape)."""

    HEADER = ["CABLE ROUTE", None, "Main Cable Size", "Earth Cable Size"]

    def test_route_schedule_columns_detected(self):
        self.assertEqual(
            route_schedule_columns(["CABLE ROUTE", "", "Main Cable Size", "Earth Cable Size"]),
            {"main": 2, "earth": 3},
        )

    def test_route_schedule_defers_to_tables_with_length_columns(self):
        self.assertEqual(
            route_schedule_columns(["Cable Size", "Earth Cable Size", "Length (m)"]),
            {},
        )

    def test_annotation_route_length_reads_mts(self):
        self.assertEqual(annotation_route_length(["230MTS X"]), (230, "230MTS X"))
        self.assertEqual(annotation_route_length(["60 MTS X"]), (60, "60 MTS X"))
        self.assertEqual(annotation_route_length(["150 MTS"]), (150, "150 MTS"))
        self.assertEqual(annotation_route_length(["45m"]), (45, "45m"))
        self.assertIsNone(annotation_route_length(["16mm2"]))
        self.assertIsNone(annotation_route_length(["LANTRAK ELECTRICAL"]))

    def test_route_row_splits_main_and_earth_with_length(self):
        items = build_route_schedule_items(
            ["MSB", "LIFT 1 -BLD A", "4x1C-95mm2 FIRE Cu", "1x1C-25mm2 PVC Cu"],
            {"main": 2, "earth": 3},
            ["230MTS X"],
            ["Page 1"],
        )

        self.assertEqual(len(items), 2)
        main, earth = items
        self.assertEqual(main["text"], "MSB | LIFT 1 -BLD A | 4x1C-95mm2 FIRE Cu | 230MTS X")
        self.assertEqual(main["metadata"]["table_quantity"], 230)
        self.assertEqual(main["metadata"]["table_unit"], "m")
        self.assertEqual(main["metadata"]["table_quantity_header"], "Route Length")
        self.assertEqual(
            earth["text"], "MSB | LIFT 1 -BLD A | EARTH | 1x1C-25mm2 PVC Cu | 230MTS X"
        )
        self.assertEqual(earth["metadata"]["table_quantity"], 230)

    def test_route_row_without_earth_emits_main_only(self):
        items = build_route_schedule_items(
            ["TX", "MSB", "5x4x1C-400mm2 FIRE Cu", ""],
            {"main": 2, "earth": 3},
            ["20MTS X"],
            ["Page 1"],
        )

        self.assertEqual(len(items), 1)
        self.assertEqual(items[0]["text"], "TX | MSB | 5x4x1C-400mm2 FIRE Cu | 20MTS X")

    def test_oversized_note_anchors_to_the_row_it_was_typed_on(self):
        table = _FakeTable([["a"], ["b"], ["c"]])
        # Box starts on row 1 (band 20-40) but is drawn double height; the
        # midpoint (49) would fall in row 2.
        note = _freetext("95MTS X", x0=10, top=22, bottom=76)

        assigned = assign_notes_to_rows(table, [note])

        self.assertEqual(list(assigned), [1])

    def test_title_banner_outside_rows_is_not_assigned(self):
        table = _FakeTable([["a"], ["b"]], top=100.0)
        banner = _freetext("LANTRAK ELECTRICAL", x0=10, top=10, bottom=40)

        self.assertEqual(assign_notes_to_rows(table, [banner]), {})

    @patch("rfq_parser.file_parser.pdfplumber.open")
    def test_parse_pdf_route_schedule_with_takeoff_notes(self, open_pdf):
        table = [
            self.HEADER,
            ["FROM", "TO", "", ""],
            ["MSB", "LIFT 1 -BLD A", "4x1C-95mm2 FIRE Cu", "1x1C-25mm2 PVC Cu"],
            ["MSB", "FIRE PUMP", "1x4C-35mm2 FIRE Cu", "1x1C-10mm2 PVC Cu"],
        ]
        page = _FakePage(
            table,
            text="MSB LIFT 1 -BLD A 4x1C-95mm2 FIRE Cu 1x1C-25mm2 PVC Cu\nTitle block junk",
            annots=[
                _freetext("230MTS X", x0=100, top=41),   # row 2's band is 40-60
                _freetext("60MTS X", x0=100, top=61),    # row 3's band is 60-80
            ],
        )
        open_pdf.return_value.__enter__.return_value.pages = [page]

        items = parse_pdf(Path("takeoff.pdf"))

        self.assertEqual(len(items), 4)
        self.assertEqual(
            [item["text"] for item in items],
            [
                "MSB | LIFT 1 -BLD A | 4x1C-95mm2 FIRE Cu | 230MTS X",
                "MSB | LIFT 1 -BLD A | EARTH | 1x1C-25mm2 PVC Cu | 230MTS X",
                "MSB | FIRE PUMP | 1x4C-35mm2 FIRE Cu | 60MTS X",
                "MSB | FIRE PUMP | EARTH | 1x1C-10mm2 PVC Cu | 60MTS X",
            ],
        )
        self.assertEqual(items[0]["metadata"]["table_quantity"], 230)
        self.assertEqual(items[2]["metadata"]["table_quantity"], 60)
        # The drawing's text layer (reflowed rows + title block) is skipped.
        self.assertNotIn("Title block junk", [item["text"] for item in items])
        self.assertIn("CABLE ROUTE | Main Cable Size | Earth Cable Size", items[0]["context"])


class _FakeRow:
    def __init__(self, bbox):
        self.bbox = bbox


class _FakeTable:
    """Stands in for a pdfplumber Table: data plus row geometry (20pt bands)."""

    ROW_HEIGHT = 20.0

    def __init__(self, data, x0=0.0, top=0.0, x1=400.0):
        self.data = data
        self.bbox = (x0, top, x1, top + self.ROW_HEIGHT * len(data))
        self.rows = [
            _FakeRow((x0, top + index * self.ROW_HEIGHT, x1, top + (index + 1) * self.ROW_HEIGHT))
            for index in range(len(data))
        ]

    def extract(self):
        return self.data


def _freetext(text, x0, top, x1=None, bottom=None):
    return {
        "data": {"Subtype": "/'FreeText'"},
        "contents": text,
        "x0": x0,
        "x1": x1 if x1 is not None else x0 + 60,
        "top": top,
        "bottom": bottom if bottom is not None else top + 18,
    }


class _FakePage:
    def __init__(self, table, text="", annots=()):
        self.table = table
        self.text = text
        self.annots = list(annots)

    def find_tables(self):
        return [_FakeTable(self.table)]

    def extract_tables(self):
        return [self.table]

    def extract_text(self):
        return self.text


class UnitOnlySizeColumnTests(unittest.TestCase):
    """A size column headed by nothing but its unit (plan §1.3 row 3)."""

    def test_unit_only_headers_are_conductor_size_columns(self):
        for header in ("mm2", "mm²", "mm", "sq mm", "sqmm"):
            with self.subTest(header=header):
                self.assertTrue(is_core_size_header(header))
                self.assertEqual(header_role(header), "extra_description")

    def test_a_length_column_is_not_captured_by_its_unit(self):
        for header in ("Length (mm)", "Length", "Diameter (mm)", "OD (mm)"):
            with self.subTest(header=header):
                self.assertFalse(is_core_size_header(header))
                self.assertNotEqual(header_role(header), "extra_description")

    def test_the_size_cell_is_folded_into_the_row_text(self):
        table = [
            ["Item", "Cable Type", "mm2", "Length"],
            ["3", "2,5mm", "2.5", "50"],
        ]

        items = _tabular_items(table, sheet_title="")

        self.assertEqual(items[0]["text"], "2,5mm 2.5mm2")


class SectionHeadingTests(unittest.TestCase):
    """The free-text heading heuristic behind body/txt context (plan B1)."""

    def test_accepts_cable_section_headings(self):
        for heading in ("CONTROLS Cable Types", "VSD CABLES", "Solar cables", "Power Cables:"):
            with self.subTest(heading=heading):
                self.assertTrue(looks_like_section_heading(heading))

    def test_rejects_directives_greetings_and_sized_rows(self):
        for line in (
            "Cables only. Exclude all pre-made leads.",  # sentence, and a directive
            "Hi cable team",  # greeting
            "Please quote the following",  # no cable keyword
            "4C 10mm2 Cu XLPE cable",  # digits: a quote line
            "Cables",  # single word
            "Can you supply the control cables for level 3 by Friday",  # too long
        ):
            with self.subTest(line=line):
                self.assertFalse(looks_like_section_heading(line))


class ParseTextContextTests(unittest.TestCase):
    def _parse(self, body):
        with tempfile.TemporaryDirectory() as tmp:
            path = Path(tmp) / "rfq.txt"
            path.write_text(body, encoding="utf-8")
            return parse_text(path)

    def test_rows_carry_the_heading_they_sit_under(self):
        items = self._parse(
            "CONTROLS Cable Types\n"
            "2.5mm2\n"
            "4mm2\n"
        )

        self.assertEqual([item["text"] for item in items], ["CONTROLS Cable Types", "2.5mm2", "4mm2"])
        # The heading itself carries the context that preceded it, not itself.
        self.assertEqual(items[0]["context"], [])
        self.assertEqual(items[1]["context"], ["CONTROLS Cable Types"])
        self.assertEqual(items[2]["context"], ["CONTROLS Cable Types"])

    def test_no_line_is_dropped_and_plain_files_keep_empty_context(self):
        items = self._parse("4C 10mm2 Cu XLPE 2000m\nSome free note\n")

        self.assertEqual(len(items), 2)
        self.assertTrue(all(item["context"] == [] for item in items))


class ColourColumnTests(unittest.TestCase):
    """A colour kept in its own column, exactly like conductor CSA. The extractor reads
    colour out of the item TEXT, so a colour that never reaches the text is one the
    resolver cannot see and every row falls to the style's house default.

    Live case (Mega Electrics stock order, 2026-08-27): SIZE | COLOUR | Description | ...
    with ten distinct 1.5mm2 colours. All ten produced the identical text
    "1.5mm2 LSFLEX(R) R-125 (X-HF-110)" and therefore the identical code -- green/yellow
    EARTH cable -- and every one reported `matched`."""

    HEADERS = ["Part No.", "SIZE", "COLOUR", "Description", "Supplier", "ROLL LENGTH"]

    def test_the_colour_column_reaches_the_row_text(self):
        item = build_structured_xlsx_item(
            ["", "1.5mm2", "PINK", "LSFLEX® R-125 (X-HF-110)", "TRICAB", "200m"],
            self.HEADERS,
        )
        self.assertIsNotNone(item)
        self.assertEqual(item["text"], "1.5mm2 PINK LSFLEX® R-125 (X-HF-110)")

    def test_distinct_colours_no_longer_collapse_to_one_row_text(self):
        texts = {
            build_structured_xlsx_item(
                ["", "1.5mm2", colour, "LSFLEX® R-125 (X-HF-110)", "TRICAB", "200m"],
                self.HEADERS,
            )["text"]
            for colour in ("RED", "WHITE", "BLUE", "BLACK", "GREEN/YELLOW", "PINK")
        }
        self.assertEqual(len(texts), 6)

    def test_a_second_colour_column_does_not_bleed_into_the_row(self):
        # The live sheet puts a BOOTLACE/FERRULE table beside the cable table, so COLOUR
        # (and Supplier, and Price) appear twice. Taking both appended the ferrule's
        # colour and overrode the cable's: "1mm2 PINK ... RED" resolved to LS-XXXD/1C1RE.
        # First-wins mirrors first_by_role, which already resolves the other repeats.
        headers = self.HEADERS + ["PART NO.", "BOOTLACE/FERRULE", "COLOUR", "Supplier"]
        item = build_structured_xlsx_item(
            ["", "1mm2", "PINK", "LSFLEX® R-125 (X-HF-110)", "TRICAB", "200m",
             "A01-AMXX/10B1RE", "1mm2 SINGLE", "RED", "TRICAB"],
            headers,
        )
        self.assertEqual(item["text"], "1mm2 PINK LSFLEX® R-125 (X-HF-110)")
        self.assertNotIn("RED", item["text"])

    def test_a_colour_alone_does_not_make_a_row_a_cable_line(self):
        # A stray colour cell in an otherwise empty row is not a quotable item; before
        # colour was read at all such a row fell through to the raw join. Keep it doing so.
        self.assertIsNone(
            build_structured_xlsx_item(["", "", "RED", "", "", ""], self.HEADERS)
        )

    def test_colour_header_spellings(self):
        for header in ("COLOUR", "Color", "Cable Colour", "Core Color", "Sheath Colour"):
            with self.subTest(header=header):
                self.assertTrue(is_colour_header(header))
        for header in ("Description", "SIZE", "Supplier", "Colour Code", "Coloured Sleeve"):
            with self.subTest(header=header):
                self.assertFalse(is_colour_header(header))

    def test_a_sheet_without_a_colour_column_is_unchanged(self):
        item = build_structured_xlsx_item(
            ["FR 110° CU 4C+E 25mm2", "1150"], ["Cable Type", "Length\n(M)"]
        )
        self.assertEqual(item["text"], "FR 110° CU 4C+E 25mm2")


class QualifiedPartNumberColumnTests(unittest.TestCase):
    """A part-number column the customer named after their own catalogue.

    Reported against a live RFQ whose table read
    ``TRIANGLE Part Number | RMS Description | ORDER QTY.``. header_role matched
    product_code on an EXACT header set, so "triangle part number" fell to "other"
    and the column was dropped -- while "rms description" matched the description
    role, which has always been substring-based. The row reached the resolver as
    "2.5mm red - LSFLEX X-HF-110" with its code gone, and since LSFLEX is claimed
    by ~60 styles and X-HF-110 is shared with the sheathed ladder, an order for LS
    panel wire came back KL on 10 of 14 lines -- six of them silently `matched`.
    """

    def test_a_qualified_part_number_header_is_a_product_code_column(self):
        for header in (
            "TRIANGLE Part Number",
            "RMS Product Code",
            "Customer Item Code",
            "TriCab Cable Code",
        ):
            with self.subTest(header=header):
                self.assertEqual(header_role(header), "product_code")

    def test_the_loosened_match_does_not_swallow_neighbouring_roles(self):
        # "part no" is the line-item number in the sheets that spell it that way,
        # and a bare "code" substring fires on words that are not codes at all.
        # These are the assertions that keep the rule from over-reaching.
        self.assertEqual(header_role("Part No"), "reference")
        self.assertEqual(header_role("Postcode"), "other")
        self.assertEqual(header_role("Colour Code"), "other")

    def test_the_code_column_reaches_the_row_text(self):
        item = build_structured_xlsx_item(
            ["LS-XXXD/1C2.5RE Red", "2.5mm red - LSFLEX X-HF-110", "400"],
            ["TRIANGLE Part Number", "RMS Description", "ORDER QTY. LIST METRE QTY"],
        )
        self.assertEqual(item["metadata"]["product_code"], "LS-XXXD/1C2.5RE Red")
        self.assertIn("LS-XXXD/1C2.5RE", item["text"])

    def test_a_code_shaped_cell_is_read_under_an_unknown_header(self):
        # The half of the fix that survives the next customer's spelling. "Model"
        # is in no vocabulary, but "KL-PAXN/1C50OR" is unmistakable -- and without
        # this the row was re-derived from its description as XL-PAXN/1C50OR
        # (2026-07-14 "Bentrol Order #117198" in the harvest corpus).
        item = build_structured_xlsx_item(
            ["100", "KL-PAXN/1C50OR", "CU 50MM2 CLASS6 CONDUCTOR E-RUBBER S-20"],
            ["QTY", "Model", "Description"],
        )
        self.assertEqual(item["metadata"]["product_code"], "KL-PAXN/1C50OR")
        self.assertIn("KL-PAXN/1C50OR", item["text"])

    def test_an_accessory_code_is_recovered_by_shape_too(self):
        item = build_structured_xlsx_item(
            ["10", "T01-AGXX/35M8RE", "35MM X 8MM STANDOFF/BUSBAR INSULATOR RED"],
            ["QTY", "Model", "Description"],
        )
        self.assertEqual(item["metadata"]["product_code"], "T01-AGXX/35M8RE")

    def test_shape_matching_never_overrules_a_recognised_column(self):
        # A reference/tag column whose values look code-shaped keeps its own role;
        # the fallback only reads columns nothing else claimed.
        item = build_structured_xlsx_item(
            ["KL-PAXN/1C50OR", "CU 50MM2 CLASS6 CONDUCTOR E-RUBBER S-20"],
            ["Cable Tag", "Description"],
        )
        self.assertEqual(item["metadata"].get("reference"), "KL-PAXN/1C50OR")
        self.assertNotIn("product_code", item["metadata"])


if __name__ == "__main__":
    unittest.main()
