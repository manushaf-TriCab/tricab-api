# Generated from the RFQ parser's tests/test_mail_ingest.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
import base64
import unittest

from rfq_parser import mail_ingest as mi


class HtmlToTextTests(unittest.TestCase):
    def test_strips_tags_and_unescapes(self):
        html = "<div>Hello&nbsp;world</div><br><p>4C 10mm&sup2; cable</p>"
        text = mi.html_to_text(html)
        self.assertIn("Hello world", text)
        self.assertIn("4C 10mm", text)
        self.assertNotIn("<", text)

    def test_drops_script_and_style(self):
        html = "<style>.x{color:red}</style><script>evil()</script><p>Keep me</p>"
        text = mi.html_to_text(html)
        self.assertEqual(text.strip(), "Keep me")


def _drum_matrix_html():
    return """
    <table>
      <tr>
        <td colspan="3">Extra Mec Cables MEL02</td>
        <td></td>
        <td colspan="2">SDI</td>
        <td colspan="3">Earth</td>
        <td>OC</td>
      </tr>
      <tr>
        <td colspan="3"></td>
        <td rowspan="2">drum lengths</td>
        <td>70mm</td><td>50mm</td><td>25mm</td><td>50mm</td><td>10mm</td><td>35mm</td>
      </tr>
      <tr>
        <td>Drum #</td><td>Drum ID</td><td>Cable size</td>
        <td>7404</td><td>1100</td><td>1930</td><td>280</td><td>0</td><td>0</td>
      </tr>
      <tr>
        <td>Drum 401</td><td>ULP-1M-0-04 (FOHB)</td><td>1C 70mm 110&deg; SDI XLPE/PVC</td>
        <td>580m</td><td>580</td><td></td><td></td><td></td><td></td><td></td>
      </tr>
      <tr>
        <td>Drum 408</td><td>ULP-3M-4-02 (FOHB)</td><td>1C 70mm 110&deg; SDI XLPE/PVC</td>
        <td>620m</td><td>420</td><td></td><td></td><td></td><td></td><td></td>
      </tr>
      <tr>
        <td>Drum 424</td><td>Mech to DC lift 1B.1M,2M.3M</td><td>4C &amp; Earth 35mm 110&deg; XLPE/PVC</td>
        <td>530m</td><td></td><td></td><td></td><td></td><td></td><td>530</td>
      </tr>
    </table>
    """


def _active_earth_html():
    return """
    <table>
      <tr>
        <th>Total Active Length (m)</th>
        <th>Earth Length (m)</th>
        <th>Cable Type</th>
        <th>Cable Size</th>
        <th>Earth Size</th>
      </tr>
      <tr><td>104</td><td>26</td><td>XLPE</td><td>4x1C 70mm</td><td>25mm</td></tr>
      <tr><td>568</td><td>142</td><td>XLPE</td><td>4x1C 70mm</td><td>25mm</td></tr>
      <tr><td>1160</td><td>290</td><td>XLPE</td><td>2x 4x1C 185mrn</td><td>70mm</td></tr>
      <tr><td>508</td><td>127</td><td>Fire Rated</td><td>4x1C 50mm</td><td>16mm</td></tr>
      <tr><td>508</td><td>127</td><td>Fire Rated</td><td>4x1C 50mm</td><td>16mm</td></tr>
      <tr><td>636</td><td>159</td><td>Fire Rated</td><td>4x1C 95mm</td><td>25mm</td></tr>
    </table>
    """


def _csa_schedule_html():
    """OP18847 M80's table, verbatim -- the audit's headline defect (4.1).

    The unit suffix on "CSA(mm2)" is the whole point: with an exact-match size
    rule it was not recognised as the size column, decomposed_schedule_columns
    returned {}, and all eight columns collapsed to a bare "95mm2".
    """
    return """
    <table>
      <tr>
        <th>From</th><th>To</th><th>Conductor type</th><th>Core</th>
        <th>CSA(mm2)</th><th>Voltage</th><th>Insulation</th><th>Length (m)</th>
      </tr>
      <tr><td>500kVA TRANSFORMER</td><td>MSB-01</td><td>FINE STRANDED COPPER</td><td>1C</td>
          <td>185</td><td>600/1000</td><td>X-HF-110 FLEXIBLE RUBBER SDI</td><td>330</td></tr>
      <tr><td>MCC-01</td><td>ISOL-PU01 &amp; PU02</td><td>FINE STRANDED COPPER</td><td>3C+3E</td>
          <td>95</td><td>600/1000</td><td>X-HF-110 VSD FLEXIBLE SCREENED</td><td>100</td></tr>
      <tr><td>MCC-01</td><td>OMNISMART</td><td>STRANDED COPPER</td><td>4C+E</td>
          <td>10</td><td>600/1000</td><td>V-90 ORANGE CIRCULAR</td><td>55</td></tr>
    </table>
    """


class CsaScheduleColumnTests(unittest.TestCase):
    """A "CSA(mm2)" header must route through the decomposed-schedule path."""

    def test_unit_suffixed_csa_header_is_the_size_column(self):
        from rfq_parser.file_parser import decomposed_schedule_columns

        headers = ["From", "To", "Conductor type", "Core", "CSA(mm2)", "Voltage",
                   "Insulation", "Length (m)"]
        columns = decomposed_schedule_columns(headers)

        self.assertEqual(columns.get("size"), 4)
        self.assertEqual(columns.get("cores"), 3)
        self.assertEqual(columns.get("lengths"), [7])

    def test_csa_variants_all_resolve_to_the_size_column(self):
        from rfq_parser.file_parser import decomposed_schedule_columns

        headers = ["Core", "PLACEHOLDER", "Length (m)"]
        for header in ("CSA", "CSA(mm2)", "CSA (mm2)", "csa mm2", "C.S.A."):
            with self.subTest(header=header):
                headers[1] = header
                self.assertEqual(decomposed_schedule_columns(headers).get("size"), 1)

    def test_csa_does_not_fire_inside_an_unrelated_word(self):
        from rfq_parser.file_parser import decomposed_schedule_columns

        # No word-boundary and "Ecsam" would be read as the size column.
        columns = decomposed_schedule_columns(["Core", "Ecsam Ref", "Length (m)"])

        self.assertEqual(columns, {})

    def test_schedule_row_keeps_cores_conductor_insulation_and_length(self):
        items = mi.extract_items_from_body_html(_csa_schedule_html())

        self.assertEqual(len(items), 3)
        self.assertEqual([item["text"] for item in items], [
            "1C 185mm2 Cu X-HF-110 FLEXIBLE RUBBER SDI Flex",
            # "3C+3E", not "3C+E": three SPLIT earths. The count is still 4
            # (split earths collapse to one earth core) but the multiplicity is
            # what FIELD_SEMANTICS reads as earth_multiplicity=3.
            "3C+3E 95mm2 Cu X-HF-110 VSD FLEXIBLE SCREENED Flex",
            "4C+E 10mm2 Cu V-90 ORANGE CIRCULAR",
        ])
        self.assertEqual([item["metadata"]["table_quantity"] for item in items], [330, 100, 55])
        for item in items:
            self.assertEqual(item["metadata"]["source_table_shape"], "decomposed_schedule")
        self.assertEqual(items[1]["metadata"]["from"], "MCC-01")
        self.assertEqual(items[1]["metadata"]["to"], "ISOL-PU01 & PU02")

    def test_temperature_and_cores_survive_into_the_resolved_product(self):
        rows = mi.match_items(mi.extract_items_from_body_html(_csa_schedule_html()))

        # X-HF-110 is a 110C style (KL), not the 90C default (XL) the bare size
        # used to resolve to, and the screened VSD row is no longer a 1C.
        self.assertEqual(rows[0]["selected_product_code"], "KL-PAXN/1C185OR")
        self.assertEqual(rows[1]["fields"]["count"], 4)
        self.assertEqual(rows[2]["selected_product_code"], "XL-PAXN/5C10OR")

    def test_split_earth_multiplicity_survives_the_parse(self):
        rows = mi.match_items(mi.extract_items_from_body_html(_csa_schedule_html()))

        self.assertEqual(rows[1]["fields"]["raw_core_expression"].upper(), "3C+3E")
        self.assertEqual(rows[1]["fields"]["earth_count"], 3)
        # Split earths still collapse to one earth core, so the count holds.
        self.assertEqual(rows[1]["fields"]["count"], 4)
        # But the style moves: a symmetric split earth is the XU/KU/EU/EA family,
        # and X-HF-110 makes it the 110C member. Flattening to "3C+E" sent this
        # to DP-PAZN, the 90C style, against an explicit 110C request.
        self.assertEqual(rows[1]["selected_product_code"], "KU-PAZN/4C95BK")


class LegalFooterTests(unittest.TestCase):
    """Confidentiality notices are scaffolding, not residue (audit 4.9).

    They are harmless as residue today, but they are classifier input, so this
    is a prerequisite for RFQ_DIRECTIVES_ENABLED.
    """

    def _residue(self, body):
        return [seg.text for seg in mi.split_body_text(body).residue]

    def test_disclaimers_are_consumed_whatever_they_open_with(self):
        for line in (
            "This message (including any attachments) may contain confidential, "
            "proprietary, privileged and/or private information.",
            "This e-mail and any attachments are confidential and may contain "
            "private and privileged information intended for the named recipient(s) only.",
            "If you are not the intended recipient, please notify the sender.",
            "****DISCLAIMER**** This e-mail and any files transmitted are confidential.",
            "Any use, dissemination or copying is strictly prohibited.",
            "Butler Electrical is committed to protecting your privacy. Copyright 2026.",
            "P Please consider the environment before printing this email",
        ):
            with self.subTest(line=line[:40]):
                self.assertEqual(self._residue(line), [])

    def test_a_quote_line_is_never_swallowed(self):
        # The cable-signal and product-code guards: a line that could be quoted
        # survives even when it trips the disclaimer vocabulary. (Lines that
        # *open* with a boilerplate word are consumed by the older anchored rule
        # regardless -- that predates this and is not what these guards cover.)
        for line in (
            "Cable schedule below is confidential - 4C 16mm2 XLPE Cu 250m",
            "Proprietary spec: KL-PAXA/1C500BK 360m",
        ):
            with self.subTest(line=line):
                self.assertEqual(self._residue(line), [])
                self.assertTrue(mi.split_body_text(line).items)


class PackQuantityCaptureTests(unittest.TestCase):
    """Accessory lines quoted by the pack must not be dropped (audit 4.6)."""

    def _texts(self, body):
        return [item["text"] for item in mi.split_body_text(body).items]

    def test_bagged_accessory_lines_are_captured(self):
        body = "\n".join([
            "Can I please get a quote for the following items:",
            "Uninsulated Lugs",
            "5 individual x Uninsulated lugs 240 x 12",
            "1 bag x Uninsulated lugs 120 x 8",
            "2 bags x inline splice connector",
        ])
        texts = self._texts(body)

        self.assertIn("5 individual x Uninsulated lugs 240 x 12", texts)
        self.assertIn("1 bag x Uninsulated lugs 120 x 8", texts)
        self.assertIn("2 bags x inline splice connector", texts)

    def test_orphan_quantity_cells_are_not_captured(self):
        # A quantity column reflowed out of a table names no product.
        self.assertEqual(self._texts("10 bags\n2 bags\n1 bag"), [])


def _material_banner_html():
    """MacKillop's shape: a material banner spanning the table, above the header.

    The banner is one <td colspan>, so _expand_table_spans turns it into N copies
    of itself and the lone-cell section-label rule no longer sees a lone cell.
    """
    return """
    <table>
      <tr><td colspan="4">Copper</td></tr>
      <tr><th>Name</th><th>Type</th><th>Size</th><th>Run Length (m)</th></tr>
      <tr><td>EX SMSB &gt; DB-AD</td><td>XLPE Cu</td><td>150</td><td>256</td></tr>
      <tr><td>DB-AD &gt; MSSB-AD</td><td>XLPE Cu</td><td>70</td><td>18</td></tr>
      <tr><td colspan="4">Aluminium</td></tr>
      <tr><td>EX SMSB &gt; DB-AD</td><td>AL Flex</td><td>300</td><td>256</td></tr>
    </table>
    """


class SpanningSectionHeaderTests(unittest.TestCase):
    """Table-spanning section banners are context, not quote lines (§4.3)."""

    def test_banner_rows_do_not_become_items(self):
        items = mi.extract_items_from_body_html(_material_banner_html())

        self.assertEqual(len(items), 3)
        for item in items:
            self.assertNotIn("Copper | Copper", item["text"])
            self.assertNotIn("Aluminium | Aluminium", item["text"])

    def test_banner_is_carried_forward_as_context_deduplicated(self):
        items = mi.extract_items_from_body_html(_material_banner_html())

        # Not "Copper | Copper | Copper | Copper" -- the label is stored once.
        self.assertIn("Copper", items[0]["context"])
        self.assertNotIn("Copper | Copper", " ".join(items[0]["context"]))
        self.assertIn("Aluminium", items[2]["context"])

    def test_a_row_with_differing_cells_is_still_an_item(self):
        # The rule keys on every populated cell being identical; a genuine data
        # row that merely repeats one value must survive.
        html = """
        <table>
          <tr><th>Name</th><th>Type</th><th>Size</th><th>Run Length (m)</th></tr>
          <tr><td>70</td><td>XLPE Cu</td><td>70</td><td>70</td></tr>
        </table>
        """
        self.assertEqual(len(mi.extract_items_from_body_html(html)), 1)


class HtmlTableExtractionTests(unittest.TestCase):
    def test_parse_html_tables_expands_rowspan_and_colspan(self):
        table = mi.parse_html_tables(_drum_matrix_html())[0]

        self.assertEqual(table[0][0:4], ["Extra Mec Cables MEL02", "Extra Mec Cables MEL02", "Extra Mec Cables MEL02", ""])
        self.assertEqual(table[1][3], "drum lengths")
        self.assertEqual(table[2][3], "drum lengths")
        self.assertEqual(table[2][4:10], ["7404", "1100", "1930", "280", "0", "0"])

    def test_drum_matrix_table_becomes_structured_items(self):
        items = mi.extract_items_from_body_html(_drum_matrix_html())

        self.assertEqual(len(items), 3)
        first = items[0]
        self.assertEqual(first["text"], "1C 70mm 110° SDI XLPE/PVC")
        self.assertEqual(first["metadata"]["table_quantity"], 580)
        self.assertEqual(first["metadata"]["table_unit"], "m")
        self.assertEqual(first["metadata"]["reference"], "Drum 401 ULP-1M-0-04 (FOHB)")
        self.assertEqual(first["metadata"]["table_quantity_header"], "SDI 70mm")
        self.assertEqual(first["source"]["input_type"], "email_html_table")

    def test_matrix_quantity_wins_over_drum_length(self):
        items = mi.extract_items_from_body_html(_drum_matrix_html())
        mismatch = items[1]

        self.assertEqual(mismatch["metadata"]["drum_length_raw"], "620m")
        self.assertEqual(mismatch["metadata"]["table_quantity"], 420)

    def test_oc_column_is_preserved_as_context(self):
        items = mi.extract_items_from_body_html(_drum_matrix_html())
        oc = items[2]

        self.assertEqual(oc["text"], "4C & Earth 35mm 110° XLPE/PVC")
        self.assertEqual(oc["metadata"]["table_quantity"], 530)
        self.assertEqual(oc["metadata"]["table_quantity_header"], "OC 35mm")
        self.assertIn("OC 35mm", oc["context"])

    def test_active_earth_summary_table_uses_generic_tabular_parser(self):
        items = mi.extract_items_from_body_html(_active_earth_html())

        self.assertEqual(len(items), 12)
        self.assertEqual([item["text"] for item in items[:6]], [
            "XLPE 1C 70mm",
            "1C 25mm earth",
            "XLPE 1C 70mm",
            "1C 25mm earth",
            "XLPE 1C 185mm",
            "1C 70mm earth",
        ])
        self.assertEqual(items[0]["metadata"]["table_quantity"], 26)
        self.assertEqual(items[0]["metadata"]["number_of_lengths"], 4)
        self.assertEqual(items[0]["metadata"]["source_total_length"], 104)
        self.assertEqual(items[2]["metadata"]["table_quantity"], 142)
        self.assertEqual(items[4]["metadata"]["table_quantity"], 145)
        self.assertEqual(items[4]["metadata"]["number_of_lengths"], 8)
        self.assertEqual(items[5]["metadata"]["table_quantity"], 145)
        self.assertEqual(items[5]["metadata"]["number_of_lengths"], 2)
        self.assertEqual(items[0]["source"]["input_type"], "email_html_table")

    def test_layout_table_without_cable_signal_is_ignored(self):
        html = "<table><tr><td>Jeff Evans</td><td>Quote Generator</td></tr></table>"

        self.assertEqual(mi.extract_items_from_body_html(html), [])


class OriginalSenderTests(unittest.TestCase):
    def test_prefers_external_from_line(self):
        body = (
            "FYI please quote.\n\n"
            "From: Jane Doe <jane@acmemining.com>\n"
            "Sent: Tuesday\n"
            "To: rep@tricab.com\n"
            "Subject: RFQ\n"
        )
        self.assertEqual(mi.extract_original_sender(body), "jane@acmemining.com")

    def test_skips_internal_from_falls_back_to_external_body_email(self):
        body = "From: rep@tricab.com\nplease see customer note from bob@bigcorp.com.au"
        self.assertEqual(mi.extract_original_sender(body), "bob@bigcorp.com.au")

    def test_returns_none_when_only_internal(self):
        body = "From: rep@tricab.com\nThanks, regards tristang@tricab.com"
        self.assertIsNone(mi.extract_original_sender(body))

    def test_extracts_customer_and_contact_labels(self):
        body = (
            "Customer: Lai Switchboards\n"
            "Contact: Anil Bhatia\n"
            "Please quote the table below."
        )

        labels = mi.extract_customer_labels(body)

        self.assertEqual(labels["customer"], "Lai Switchboards")
        self.assertEqual(labels["contact"], "Anil Bhatia")
        self.assertEqual(labels["customer_code"], "")

    def test_customer_code_label_is_distinct_from_customer_name(self):
        body = "Customer Code: LAISWI\nContact Name: Anil Bhatia"

        labels = mi.extract_customer_labels(body)

        self.assertEqual(labels["customer_code"], "LAISWI")
        self.assertEqual(labels["contact"], "Anil Bhatia")
        self.assertEqual(labels["customer"], "")


class TemplateLabelTests(unittest.TestCase):
    """CUSTOMER REF: / EMAIL: / DELIVERY ADDRESS: (DIRECTIVE_CAPTURE_PLAN B1)."""

    def test_reads_the_full_create_quote_block(self):
        body = (
            "CREATE QUOTE\n"
            "CUSTOMER: Greenlands Electrical\n"
            "CUSTOMER CODE: GRELEC\n"
            "CONTACT: Ben Tyler\n"
            "CUSTOMER REF: 03599\n"
            "EMAIL: ben.tyler@greenlands.com.au\n"
            "DELIVERY ADDRESS: 14 Bayfield Rd\n"
        )

        self.assertEqual(
            mi.extract_customer_labels(body),
            {
                "customer": "Greenlands Electrical",
                "customer_code": "GRELEC",
                "contact": "Ben Tyler",
                "customer_ref": "03599",
                "email": "ben.tyler@greenlands.com.au",
                "delivery_address": "14 Bayfield Rd",
            },
        )

    def test_delivery_address_folds_in_wrapped_lines(self):
        body = (
            "DELIVERY ADDRESS: Unit 4, 14 Bayfield Rd\n"
            "Bayswater North VIC 3153\n"
            "Please quote the following\n"
        )

        labels = mi.extract_customer_labels(body)

        self.assertEqual(
            labels["delivery_address"], "Unit 4, 14 Bayfield Rd, Bayswater North VIC 3153"
        )

    def test_email_label_keeps_only_the_address(self):
        labels = mi.extract_customer_labels("Email: Ben Tyler <ben@greenlands.com.au>")

        self.assertEqual(labels["email"], "ben@greenlands.com.au")

    def test_email_label_ignored_when_it_carries_no_address(self):
        self.assertEqual(mi.extract_customer_labels("Email: see below")["email"], "")

    def test_label_carrying_a_quote_line_is_not_a_label(self):
        # "Deliver to: 500m of XL-PAXA/1C500BK" is an item, not an address --
        # consuming it as a label would silently delete the line.
        body = "Deliver to: 500m of XL-PAXA/1C500BK"

        self.assertEqual(mi.extract_customer_labels(body)["delivery_address"], "")
        self.assertEqual(mi.candidate_lines_from_text(body), [body])


class CustomerRefTests(unittest.TestCase):
    """Subject prefixes never reach the Customer Ref (749970.04 printed `Fwd: RFQ: ...`)."""

    def test_trims_stacked_prefixes_off_the_subject(self):
        self.assertEqual(
            mi.customer_ref_for_quote("", "Fwd: RFQ: P92837 - Rio Tinto - WASP – Comms Infrastructure"),
            "P92837 - Rio Tinto - WASP - Comms Infrastructure",
        )
        self.assertEqual(mi.customer_ref_for_quote("", "RE: FW: Re[2]: rfq : Site A"), "Site A")

    def test_rfq_without_a_colon_is_part_of_the_reference(self):
        self.assertEqual(mi.customer_ref_for_quote("", "FW: RFQ for site"), "RFQ for site")
        self.assertEqual(mi.customer_ref_for_quote("", "RFQ-1234 Magnum"), "RFQ-1234 Magnum")
        self.assertEqual(mi.customer_ref_for_quote("", "P92837 RE: pricing"), "P92837 RE: pricing")

    def test_typed_ref_wins_and_keeps_its_wording(self):
        self.assertEqual(mi.customer_ref_for_quote("RFQ: 03599", "Fwd: something else"), "RFQ: 03599")

    def test_typographic_characters_fold_to_ascii(self):
        self.assertEqual(
            mi.customer_ref_for_quote("Site A — ‘Stage’ “2”", ""),
            "Site A - 'Stage' \"2\"",
        )

    def test_prefix_does_not_count_against_the_length_cap(self):
        body = "P" * 60
        self.assertEqual(mi.customer_ref_for_quote("", f"Fwd: RFQ: {body}X"), body)

    def test_prefix_only_subject_leaves_the_ref_empty(self):
        self.assertEqual(mi.customer_ref_for_quote("", "Fwd: RFQ:"), "")
        self.assertEqual(mi.customer_ref_for_quote(None, None), "")


class InlineImageFilterTests(unittest.TestCase):
    def _img(self, **kw):
        base = {
            "@odata.type": "#microsoft.graph.fileAttachment",
            "contentType": "image/png",
            "size": 50000,
            "name": "schedule.png",
            "isInline": True,
        }
        base.update(kw)
        return base

    def test_keeps_real_content_image(self):
        self.assertTrue(mi.is_processable_inline_image(self._img(), 25600))

    def test_drops_tiny_image(self):
        self.assertFalse(mi.is_processable_inline_image(self._img(size=4000), 25600))

    def test_drops_noise_named_image(self):
        self.assertFalse(mi.is_processable_inline_image(self._img(name="outlook-logo.png"), 25600))
        self.assertFalse(mi.is_processable_inline_image(self._img(name="social-icon.gif"), 25600))

    def test_keeps_outlook_renamed_paste(self):
        # Outlook renames every inline image to imageNNN.png, including a pasted
        # cable schedule. Only the size floor may reject it.
        self.assertTrue(mi.is_processable_inline_image(self._img(name="image001.png"), 25600))
        self.assertFalse(mi.is_processable_inline_image(self._img(name="image001.png", size=4000), 25600))

    def test_drops_non_image(self):
        self.assertFalse(mi.is_processable_inline_image(self._img(contentType="application/pdf"), 25600))

    def test_size_falls_back_to_decoded_bytes(self):
        payload = base64.b64encode(b"x" * 30000).decode("ascii")
        attachment = self._img(name="paste.png", contentBytes=payload)
        attachment.pop("size")
        self.assertTrue(mi.is_processable_inline_image(attachment, 25600))


class CandidateLinesTests(unittest.TestCase):
    def test_drops_headers_and_signature(self):
        body = (
            "From: jane@acme.com\n"
            "Sent: Tuesday\n"
            "Subject: RFQ\n"
            "4C 10mm2 Cu XLPE 2000m\n"
            "Regards\n"
            "Jane\n"
            "Sent from my iPhone\n"
        )
        lines = mi.candidate_lines_from_text(body)
        self.assertIn("4C 10mm2 Cu XLPE 2000m", lines)
        self.assertNotIn("From: jane@acme.com", lines)
        self.assertFalse(any(line.startswith("Sent from my") for line in lines))

    def test_drops_prose_without_cable_signal(self):
        body = (
            "TriCab Flexible Cables are designed to have a Low Bending Radius.\n"
            "Quote is based on Copper and Aluminium LME prices as detailed.\n"
            "Power cable 4C+E 1.5mm2 PVC Cu 50M\n"
            "Control cable, 1Pr OS, 1.5mm2, Cu, 100M\n"
            "0.6/1kV XLPE single core 240mm2\n"
        )
        lines = mi.candidate_lines_from_text(body)
        self.assertEqual(len(lines), 3)
        self.assertTrue(all("mm" in line or "kV" in line for line in lines))
        self.assertFalse(any("designed" in line for line in lines))

    def test_keeps_lines_that_are_only_a_product_code(self):
        # A customer quoting our own part numbers back at us: no free-text size,
        # core count or voltage anywhere, so the cable signal alone drops every line.
        body = (
            "Hi,\n\n"
            "Could I please get some pricing on the below?\n\n"
            "360m    x              KL-PAXA/1C500BK\n\n"
            "12           x              B20-ABXX/12H500\n\n"
            "720m    x              XL-PAXA/1C400BK\n\n"
            "160m    x              LP-XXXA/1C185EA\n\n"
            "8             x              B20-ABXX/12H400\n\n"
            "1             x              B20-ACXX/12H185\n\n"
            "Rainbow packs to suit the above\n"
        )

        lines = mi.candidate_lines_from_text(body)

        self.assertEqual(len(lines), 6)
        for code in (
            "KL-PAXA/1C500BK",
            "B20-ABXX/12H500",
            "XL-PAXA/1C400BK",
            "LP-XXXA/1C185EA",
            "B20-ABXX/12H400",
            "B20-ACXX/12H185",
        ):
            self.assertTrue(any(code in line for line in lines), code)
        self.assertFalse(any("pricing on the below" in line for line in lines))

    def test_pairs_circuit_label_with_following_cable_line(self):
        body = (
            "MSSB-L3-4-E\n\n"
            "1 X 131m x 16mm Fire Rated 5core\n\n"
            "DB-H-L2M-01\n\n"
            "4 X 131m x 70mm SDI 90o\n\n"
            "DB-H-L1-01\n\n"
            "1 x 113m x 50mm 5core 90o\n\n"
            "DB-H-L2-03\n\n"
            "1 x 146m x 35mm 5core 90o\n"
        )

        lines = mi.candidate_lines_from_text(body)

        self.assertEqual(
            lines,
            [
                "MSSB-L3-4-E | 1 X 131m x 16mm Fire Rated 5core",
                "DB-H-L2M-01 | 4 X 131m x 70mm SDI 90o",
                "DB-H-L1-01 | 1 x 113m x 50mm 5core 90o",
                "DB-H-L2-03 | 1 x 146m x 35mm 5core 90o",
            ],
        )

    def test_recovers_length_rows_from_run_on_outlook_body(self):
        body = (
            "Hello Mate,Please Price the following"
            "4 x 34Mtrs – 70mm AL – 90deg"
            "4 x 62Mtrs – 25mm AL – 90deg"
            "1 x 62Mtrs – 10mm Ecc Cu"
            "Regards Roman Fernando."
        )

        lines = mi.candidate_lines_from_text(body)

        self.assertEqual(
            lines,
            [
                "4 x 34Mtrs – 70mm AL – 90deg",
                "4 x 62Mtrs – 25mm AL – 90deg",
                "1 x 62Mtrs – 10mm Ecc Cu",
            ],
        )


class PastedTableSizeColumnTests(unittest.TestCase):
    def test_cobar_row_three_resolves_at_its_stated_size(self):
        # Pro-built Cobar (plan §1.3 row 3): the mm2 column read 2.5 and was
        # dropped, leaving the comma-decimal "2,5mm" to resolve as 5mm2.
        html = (
            "<table>"
            "<tr><th>Item</th><th>Cable Type</th><th>mm2</th><th>Length</th></tr>"
            "<tr><td>3</td><td>2,5mm</td><td>2.5</td><td>50</td></tr>"
            "</table>"
        )

        items = mi.extract_items_from_body_html(html)

        self.assertEqual(items[0]["text"], "2,5mm 2.5mm2")
        code = mi.match_items(items)[0]["selected_product_code"]
        self.assertIn("2.5", code)


class BodySplitTests(unittest.TestCase):
    """Consume/residue split + body-path context (DIRECTIVE_CAPTURE_PLAN B1)."""

    def _reasons(self, split):
        return {segment.text: segment.reason for segment in split.consumed}

    def test_every_segment_lands_in_exactly_one_bucket_with_stable_indexes(self):
        body = (
            "CREATE QUOTE\n"
            "Customer: Rexel\n"
            "Hi team,\n"
            "Please quote the following\n"
            "Power Cables\n"
            "4C 10mm2 Cu XLPE 2000m\n"
            "Cables only. Exclude all pre-made leads and non-cable items.\n"
            "Regards\n"
        )

        split = mi.split_body_text(body)

        indexes = (
            [item["source"]["line_number"] for item in split.items]
            + [segment.index for segment in split.consumed]
            + [segment.index for segment in split.residue]
        )
        self.assertEqual(sorted(indexes), list(range(1, 9)))

    def test_directive_line_survives_as_residue(self):
        body = (
            "Please quote the following\n"
            "4C 10mm2 Cu XLPE 2000m\n"
            "Cables only. Exclude all pre-made leads and non-cable items.\n"
        )

        split = mi.split_body_text(body)

        self.assertEqual([item["text"] for item in split.items], ["4C 10mm2 Cu XLPE 2000m"])
        self.assertEqual(
            [segment.text for segment in split.residue],
            ["Cables only. Exclude all pre-made leads and non-cable items."],
        )

    def test_variant_fork_request_is_residue_not_an_item(self):
        body = (
            "1. please provide an option for KU & EA copper\n"
            "2. please provide an option for KU & EA Aluminium\n"
        )

        split = mi.split_body_text(body)

        self.assertEqual(split.items, [])
        self.assertEqual(len(split.residue), 2)

    def test_template_block_leaves_no_residue(self):
        # The zero-residue case the B1 cost argument rests on: a fully-labelled
        # CREATE QUOTE block plus item lines makes no work for a classifier.
        body = (
            "CREATE QUOTE\n"
            "Customer: Greenlands Electrical\n"
            "Customer Code: GRELEC\n"
            "Customer Ref: 03599\n"
            "Email: ben@greenlands.com.au\n"
            "Delivery Address: Unit 4, 14 Bayfield Rd\n"
            "Bayswater North VIC 3153\n"
            "Please quote the following\n"
            "4C 10mm2 Cu XLPE 2000m\n"
            "Kind regards\n"
        )

        split = mi.split_body_text(body)

        self.assertEqual(split.residue, [])
        self.assertEqual([item["text"] for item in split.items], ["4C 10mm2 Cu XLPE 2000m"])
        self.assertEqual(split.labels["customer_ref"], "03599")
        self.assertEqual(
            split.labels["delivery_address"], "Unit 4, 14 Bayfield Rd, Bayswater North VIC 3153"
        )

    def test_consumed_lines_carry_a_reason(self):
        body = (
            "Subject: RFQ\n"
            "Customer: Rexel\n"
            "CONTROLS Cable Types\n"
            "Hi team\n"
            "MSSB-L3-4-E\n"
            "1 X 131m x 16mm Fire Rated 5core\n"
        )

        reasons = self._reasons(mi.split_body_text(body))

        self.assertEqual(reasons["Subject: RFQ"], "boilerplate")
        self.assertEqual(reasons["Customer: Rexel"], "label")
        self.assertEqual(reasons["CONTROLS Cable Types"], "heading")
        self.assertEqual(reasons["Hi team"], "preamble")
        self.assertEqual(reasons["MSSB-L3-4-E"], "circuit_label")

    def test_section_heading_becomes_context_for_the_rows_under_it(self):
        body = (
            "CONTROLS Cable Types\n"
            "2.5mm2\n"
            "4C 10mm2 Cu XLPE 2000m\n"
        )

        split = mi.split_body_text(body)

        self.assertEqual([item["context"] for item in split.items], [["CONTROLS Cable Types"]] * 2)

    def test_context_is_capped_and_ordered(self):
        body = (
            "Power Cables\n"
            "Control Cables\n"
            "Solar cables\n"
            "4C 10mm2 Cu XLPE 2000m\n"
        )

        split = mi.split_body_text(body)

        self.assertEqual(split.items[0]["context"], ["Control Cables", "Solar cables"])

    def test_prose_is_never_promoted_to_context(self):
        # A directive sentence is short and numberless too; only a line that
        # actually names a cable family may become part of the ranking haystack.
        body = "Cables only. Exclude all pre-made leads.\n4C 10mm2 Cu XLPE 2000m\n"

        split = mi.split_body_text(body)

        self.assertEqual(split.items[0]["context"], [])

    def test_closing_courtesy_never_reaches_the_residue(self):
        # Named in the plan: MacKillop's clarification offer must not be
        # classifier input. _BOILERPLATE_RE does not match it, so the split does.
        body = (
            "4C 10mm2 Cu XLPE 2000m\n"
            "Let me know if you need any further clarification\n"
        )

        split = mi.split_body_text(body)

        self.assertEqual(split.residue, [])
        self.assertEqual(self._reasons(split)["Let me know if you need any further clarification"], "closing")

    def test_signature_block_under_a_signoff_is_consumed(self):
        body = "4C 10mm2 Cu XLPE 2000m\nKind regards\nMick\nProject Engineer\n"

        reasons = self._reasons(mi.split_body_text(body))

        self.assertEqual(reasons["Mick"], "signature")
        self.assertEqual(reasons["Project Engineer"], "signature")

    def test_a_directive_below_a_signoff_still_reaches_the_residue(self):
        body = (
            "4C 10mm2 Cu XLPE 2000m\n"
            "Regards\n"
            "Cables only. Exclude all pre-made leads and non-cable items.\n"
        )

        split = mi.split_body_text(body)

        self.assertEqual(
            [segment.text for segment in split.residue],
            ["Cables only. Exclude all pre-made leads and non-cable items."],
        )

    def test_quantity_line_folds_into_the_spec_above_it(self):
        # The alternating spec/qty style, the way pair_schedule_lines already
        # folds it on the .txt path.
        split = mi.split_body_text("4C 10mm2 Cu XLPE\n2 X 50M\n")

        self.assertEqual([item["text"] for item in split.items], ["4C 10mm2 Cu XLPE 2 X 50M"])
        self.assertEqual(split.residue, [])
        self.assertEqual(self._reasons(split)["2 X 50M"], "quantity_line")

    def test_quantity_restatement_in_brackets_is_dropped_before_folding(self):
        split = mi.split_body_text("XLPE Copper Flex 185mm2\n8 x 240m (1920m total)\n")

        self.assertEqual([item["text"] for item in split.items], ["XLPE Copper Flex 185mm2 8 x 240m"])

    def test_short_numeric_quantity_survives_the_noise_guard(self):
        # "60" is two characters; the short-line guard used to eat it before the
        # fold could see it, silently leaving the row on its 1 m default.
        split = mi.split_body_text("Cable 3 - 25mm2 SDI, X-HF-110\n60\n")

        self.assertEqual([item["text"] for item in split.items], ["Cable 3 - 25mm2 SDI, X-HF-110 60"])

    def test_a_second_quantity_never_overwrites_a_row_that_has_one(self):
        split = mi.split_body_text("4C 10mm2 Cu XLPE\n2 X 50M\n16 x 10m runs\n")

        self.assertEqual([item["text"] for item in split.items], ["4C 10mm2 Cu XLPE 2 X 50M"])
        self.assertEqual(self._reasons(split)["16 x 10m runs"], "quantity_orphan")

    def test_accessory_and_option_directives_stay_in_the_residue(self):
        body = "Option 1A.\nXLPE Copper Flex 185mm2\n8 x 240m (1920m total)\nLUGS TO SUIT X 18\n"

        split = mi.split_body_text(body)

        self.assertEqual(
            [segment.text for segment in split.residue], ["Option 1A.", "LUGS TO SUIT X 18"]
        )

    def test_a_well_formed_row_is_never_split_from_its_own_quantity(self):
        # The run-on splitter is for bodies whose newlines were lost. One item
        # start is an ordinary row; tearing it apart orphaned the quantity and
        # the row silently kept the 1 m default.
        body = "630mm2 Copper flex cabling – 16 x 10m runs\n"

        self.assertEqual(mi.candidate_lines_from_text(body), ["630mm2 Copper flex cabling – 16 x 10m runs"])

    def test_candidate_lines_is_a_view_over_the_split(self):
        body = "Power Cables\nMSSB-L3-4-E\n1 X 131m x 16mm Fire Rated 5core\n"

        self.assertEqual(
            mi.candidate_lines_from_text(body),
            [item["text"] for item in mi.split_body_text(body).items],
        )
