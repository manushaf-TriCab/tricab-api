# Generated from the RFQ parser's tests/test_vision_ocr.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
import io
import json
import unittest
from unittest.mock import patch

from rfq_parser.vision_ocr import VisionOcrResult, extract_table_with_qwen, normalize_image_type


def FakeResponse(content, model, finish_reason="stop"):
    return (
        {
            "model": model,
            "choices": [{"message": {"content": json.dumps(content)}, "finish_reason": finish_reason}],
        },
        None,
    )


class VisionOcrTests(unittest.TestCase):
    def test_image_type_is_inferred_from_png_signature(self):
        self.assertEqual(normalize_image_type(b"\x89PNG\r\n\x1a\nrest", "application/octet-stream"), "image/png")

    def test_browser_image_type_alias_is_normalized(self):
        self.assertEqual(normalize_image_type(b"image", "image/x-png"), "image/png")

    @patch.dict("os.environ", {"FOUNDRY_ENDPOINT": "test-key", "VISION_OCR_ENABLED": "true"})
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_primary_model_returns_normalized_rows(self, urlopen):
        urlopen.return_value = FakeResponse(
            {
                "overall_confidence": 0.96,
                "rows": [
                    {
                        "section": "Control cables",
                        "description": "260 meters 0.75mm2 Cu 4C+E PVC control cable",
                        "length": 260,
                        "unit": "m",
                        "confidence": 0.98,
                        "uncertain": False,
                        "notes": "",
                    }
                ],
            },
            "qwen/qwen3-vl-8b-instruct",
        )

        result = extract_table_with_qwen(b"image", "image/png")

        self.assertTrue(result.ok)
        self.assertFalse(result.escalated)
        self.assertEqual(result.text, "260 meters 0.75mm2 Cu 4C+E PVC control cable Control cables")
        request_payload = urlopen.call_args.args[0]
        image_url = request_payload["messages"][1]["content"][1]["image_url"]["url"]
        self.assertTrue(image_url.startswith("data:image/png;base64,"))

    @patch.dict("os.environ", {"FOUNDRY_ENDPOINT": "test-key", "VISION_OCR_ENABLED": "true"})
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_fire_rated_flag_is_applied_per_row(self, urlopen):
        urlopen.return_value = FakeResponse(
            {
                "overall_confidence": 0.95,
                "rows": [
                    {
                        "section": "",
                        "description": "1 CORE SDI FLEX 185mm2",
                        "length": 720,
                        "unit": "m",
                        "fire_rated": True,
                        "confidence": 0.95,
                        "uncertain": False,
                        "notes": "",
                    },
                    {
                        "section": "",
                        "description": "1 CORE X90 XLPE 500mm2",
                        "length": 1800,
                        "unit": "m",
                        "fire_rated": False,
                        "confidence": 0.95,
                        "uncertain": False,
                        "notes": "",
                    },
                ],
            },
            "qwen/qwen3-vl-8b-instruct",
        )

        result = extract_table_with_qwen(b"image", "image/png")
        lines = result.text.splitlines()

        self.assertIn("Fire Rated", lines[0])
        self.assertNotIn("Fire Rated", lines[1])
        # The schema must request fire_rated so the model commits to it per row.
        payload = urlopen.call_args.args[0]
        row_props = payload["response_format"]["json_schema"]["schema"]["properties"]["rows"]["items"]["properties"]
        self.assertIn("fire_rated", row_props)

    @patch.dict(
        "os.environ",
        {
            "FOUNDRY_ENDPOINT": "test-key",
            "VISION_OCR_ENABLED": "true",
            "VISION_OCR_ESCALATION_THRESHOLD": "0.82",
        },
    )
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_uncertain_primary_result_escalates(self, urlopen):
        primary = {
            "overall_confidence": 0.75,
            "rows": [
                {
                    "section": "",
                    "description": "260 meters 0.75mm2 Cu 4C+E PVC",
                    "length": 260,
                    "unit": "m",
                    "confidence": 0.75,
                    "uncertain": True,
                    "notes": "A cell was unclear.",
                }
            ],
        }
        escalation = {
            "overall_confidence": 0.97,
            "rows": [
                {
                    "section": "Control cables",
                    "description": "260 meters 0.75mm2 Cu 4C+E PVC control cable",
                    "length": 260,
                    "unit": "m",
                    "confidence": 0.97,
                    "uncertain": False,
                    "notes": "",
                }
            ],
        }
        urlopen.side_effect = [
            FakeResponse(primary, "qwen/qwen3-vl-8b-instruct"),
            FakeResponse(escalation, "qwen/qwen3-vl-30b-a3b-instruct"),
        ]

        result = extract_table_with_qwen(b"image", "image/jpeg")

        self.assertTrue(result.ok)
        self.assertTrue(result.escalated)
        self.assertEqual(result.model, "qwen/qwen3-vl-30b-a3b-instruct")
        self.assertEqual(urlopen.call_count, 2)

    @patch.dict(
        "os.environ",
        {
            "FOUNDRY_ENDPOINT": "test-key",
            "VISION_OCR_ENABLED": "true",
            "VISION_OCR_ESCALATION_THRESHOLD": "0.82",
        },
    )
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_cable_row_missing_length_escalates_even_with_high_confidence(self, urlopen):
        missing_length = {
            "overall_confidence": 0.97,
            "rows": [
                {
                    "section": "Submains Cable / MSSB Cable",
                    "description": "4C+E 50mm Cu (HDB-B1)",
                    "length": None,
                    "unit": None,
                    "confidence": 0.97,
                    "uncertain": False,
                    "notes": "",
                }
            ],
        }
        corrected = {
            "overall_confidence": 0.98,
            "rows": [
                {
                    "section": "Submains Cable / MSSB Cable",
                    "description": "4C+E 50mm Cu (HDB-B1)",
                    "length": 10,
                    "unit": "m",
                    "confidence": 0.98,
                    "uncertain": False,
                    "notes": "",
                }
            ],
        }
        urlopen.side_effect = [
            FakeResponse(missing_length, "qwen/qwen3-vl-8b-instruct"),
            FakeResponse(corrected, "qwen/qwen3-vl-30b-a3b-instruct"),
        ]

        result = extract_table_with_qwen(b"image", "image/png")

        self.assertTrue(result.escalated)
        self.assertEqual(result.rows[0]["length"], 10)
        self.assertEqual(result.rows[0]["unit"], "m")
        self.assertIn("10m", result.text)

    @patch.dict(
        "os.environ",
        {
            "FOUNDRY_ENDPOINT": "test-key",
            "VISION_OCR_ENABLED": "true",
        },
    )
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_truncated_primary_response_escalates(self, urlopen):
        corrected = {
            "overall_confidence": 0.98,
            "rows": [
                {
                    "section": "Submains Cable",
                    "description": "4C+E 50mm Cu",
                    "length": 10,
                    "unit": "m",
                    "confidence": 0.98,
                    "uncertain": False,
                }
            ],
        }
        urlopen.side_effect = [
            FakeResponse({}, "qwen/qwen3-vl-8b-instruct", finish_reason="length"),
            FakeResponse(corrected, "qwen/qwen3-vl-30b-a3b-instruct"),
        ]

        result = extract_table_with_qwen(b"image", "image/png")

        self.assertTrue(result.ok)
        self.assertTrue(result.escalated)
        self.assertEqual(result.rows[0]["length"], 10)

    @patch.dict("os.environ", {"FOUNDRY_ENDPOINT": "test-key", "VISION_OCR_ENABLED": "true"})
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_schema_is_column_anchored_and_forbids_invented_columns(self, urlopen):
        # Guards the 2026-07-29 fix. The prompt used to carry a worked example of a
        # decomposed schedule ("Active 2x300mm2 Cu, Neutral ..., Configuration 3x1
        # core Trefoil"); a small VL model copied that template onto every row of a
        # table whose columns were nothing like it, inventing Neutral/Insulation
        # columns and flipping an Al conductor to Cu. The model now transcribes the
        # real header row into `columns` and one aligned cell per column, and the
        # description is assembled from those here -- so there is no free-text field
        # left to fill with a remembered layout. This checks the request we send;
        # obedience needs a live call, which this suite can't make.
        urlopen.return_value = FakeResponse(
            {"overall_confidence": 0.9, "columns": [], "rows": []}, "qwen/qwen3-vl-30b-a3b-instruct"
        )

        extract_table_with_qwen(b"image", "image/png")

        payload = urlopen.call_args.args[0]
        schema = payload["response_format"]["json_schema"]["schema"]
        self.assertIn("columns", schema["properties"])
        self.assertIn("columns", schema["required"])
        row_props = schema["properties"]["rows"]["items"]["properties"]
        self.assertIn("cells", row_props)
        system_prompt = payload["messages"][0]["content"]
        self.assertIn("NEVER INVENT", system_prompt)
        self.assertIn("copy its cells verbatim", system_prompt)
        self.assertIn("never merge a count from one column", system_prompt)
        self.assertIn("never write 'Cu'", system_prompt)

    @patch.dict(
        "os.environ",
        {
            "FOUNDRY_ENDPOINT": "test-key",
            "VISION_OCR_ENABLED": "true",
            # Pin escalation to the primary so this stays a single-call test; a lug row
            # with a null length otherwise looks like a cable row missing its length.
            "RFQ_VISION_MODEL": "qwen/qwen3-vl-8b-instruct",
            "RFQ_VISION_ESCALATION_MODEL": "qwen/qwen3-vl-8b-instruct",
        },
    )
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_product_code_kept_and_bare_quantity_not_read_as_length(self, urlopen):
        urlopen.return_value = FakeResponse(
            {
                "overall_confidence": 0.95,
                "rows": [
                    {
                        "section": "Cable Lugs",
                        "code": "XL-PAXN/1C25OR",
                        "description": "Cu 25mm Flexible Rubber X-90 0.6/1KV 90C",
                        "length": None,
                        "unit": None,
                        "fire_rated": False,
                        "confidence": 0.95,
                        "uncertain": False,
                    }
                ],
            },
            "qwen/qwen3-vl-8b-instruct",
        )

        result = extract_table_with_qwen(b"image", "image/png")

        self.assertTrue(result.ok)
        self.assertEqual(result.rows[0]["code"], "XL-PAXN/1C25OR")
        self.assertIsNone(result.rows[0]["length"])
        # The code leads the matcher line and no phantom length is appended.
        self.assertTrue(result.text.startswith("XL-PAXN/1C25OR"))
        self.assertNotIn("None", result.text)

    @patch.dict("os.environ", {"FOUNDRY_ENDPOINT": "test-key", "VISION_OCR_ENABLED": "true"})
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_schema_requests_code_and_prompt_guards_bare_quantities(self, urlopen):
        urlopen.return_value = FakeResponse({"overall_confidence": 0.9, "rows": []}, "qwen/qwen3-vl-8b-instruct")

        extract_table_with_qwen(b"image", "image/png")

        payload = urlopen.call_args.args[0]
        row_props = payload["response_format"]["json_schema"]["schema"]["properties"]["rows"]["items"]["properties"]
        self.assertIn("code", row_props)
        system_prompt = payload["messages"][0]["content"]
        self.assertIn("`code` field", system_prompt)
        # Guards against reading a unit-less Qty/price column, or a cross-section
        # column, as a requested length.
        self.assertIn("Qty", system_prompt)
        self.assertIn("is NOT a length", system_prompt)
        self.assertIn("Active (mm2)", system_prompt)

    @patch.dict(
        "os.environ",
        {
            "FOUNDRY_ENDPOINT": "test-key",
            "VISION_OCR_ENABLED": "true",
            "RFQ_VISION_MODEL": "qwen/qwen3-vl-30b-a3b-instruct",
            "RFQ_VISION_ESCALATION_MODEL": "qwen/qwen3-vl-30b-a3b-instruct",
        },
    )
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_single_line_diagram_columns_are_labelled_not_invented(self, urlopen):
        # The MAIN SWITCHBOARD SINGLE LINE DIAGRAM shape: route columns plus an
        # "Active (mm2)" cross-section, and no length column anywhere. Every part of
        # this used to go wrong at once -- 300mm2 was reported as "300m", an Al
        # conductor came back as Cu, and Neutral/Insulation/Configuration columns
        # that do not exist in this table were fabricated on every row.
        urlopen.return_value = FakeResponse(
            {
                "overall_confidence": 0.94,
                "columns": [
                    "Cable Reference",
                    "FROM",
                    "TO",
                    "Arrangement",
                    "No of sets",
                    "Active (mm2)",
                    "Conduit Size",
                    "Conductor",
                ],
                "rows": [
                    {
                        "section": "",
                        "code": "",
                        "cells": ["C1", "Sub-Station", "MSB", "4 x 1 Core", "2", "300", "2 x 150mm dia", "Al"],
                        "description": "",
                        "length": 300,
                        "unit": "m",
                        "fire_rated": False,
                        "confidence": 0.94,
                        "uncertain": False,
                    },
                    {
                        "section": "",
                        "code": "",
                        "cells": ["C3", "T1-1", "Appt 6", "2 x 1 Core", "1", "35", "50mm Dia", "Cu"],
                        "description": "",
                        "length": None,
                        "unit": None,
                        "fire_rated": False,
                        "confidence": 0.94,
                        "uncertain": False,
                    },
                ],
            },
            "qwen/qwen3-vl-30b-a3b-instruct",
        )

        result = extract_table_with_qwen(b"image", "image/png")
        first, second = result.text.splitlines()

        self.assertEqual(
            first,
            "Cable Reference C1, FROM Sub-Station, TO MSB, Arrangement 4 x 1 Core, "
            "No of sets 2, Active 300mm2, Conduit Size 2 x 150mm dia, Conductor Al",
        )
        # The cross-section is never promoted to a requested length.
        self.assertIsNone(result.rows[0]["length"])
        self.assertEqual(result.rows[0]["unit"], "")
        self.assertNotIn("300m,", first)
        self.assertNotIn("300 m", first)
        # Only the columns that exist, with each row's own conductor material.
        for absent in ("Neutral", "Insulation", "Configuration", "Circular", "Trefoil"):
            self.assertNotIn(absent, result.text)
        self.assertTrue(first.endswith("Conductor Al"))
        self.assertTrue(second.endswith("Conductor Cu"))

    @patch.dict(
        "os.environ",
        {
            "FOUNDRY_ENDPOINT": "test-key",
            "VISION_OCR_ENABLED": "true",
            "RFQ_VISION_MODEL": "qwen/qwen3-vl-30b-a3b-instruct",
            "RFQ_VISION_ESCALATION_MODEL": "qwen/qwen3-vl-30b-a3b-instruct",
        },
    )
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_real_length_column_is_kept(self, urlopen):
        urlopen.return_value = FakeResponse(
            {
                "overall_confidence": 0.95,
                "columns": ["Description", "Size", "Length (m)"],
                "rows": [
                    {
                        "section": "",
                        "code": "",
                        "cells": ["4C+E PVC Cu", "16mm2", "250"],
                        "description": "",
                        "length": 250,
                        "unit": "m",
                        "fire_rated": False,
                        "confidence": 0.95,
                        "uncertain": False,
                    }
                ],
            },
            "qwen/qwen3-vl-30b-a3b-instruct",
        )

        result = extract_table_with_qwen(b"image", "image/png")

        self.assertEqual(result.rows[0]["length"], 250)
        self.assertEqual(result.rows[0]["unit"], "m")
        self.assertIn("Length 250m", result.text)

    @patch.dict(
        "os.environ",
        {
            "FOUNDRY_ENDPOINT": "test-key",
            "VISION_OCR_ENABLED": "true",
            "VISION_OCR_ESCALATION_THRESHOLD": "0.82",
        },
    )
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_cells_misaligned_with_columns_escalate(self, urlopen):
        # A short cells list means values have shifted left/right against their
        # headers -- the row-slippage that mislabelled C4/C6/C8's destinations.
        def row(cells, confidence=0.95):
            return {
                "section": "",
                "code": "",
                "cells": cells,
                "description": "",
                "length": None,
                "unit": None,
                "fire_rated": False,
                "confidence": confidence,
                "uncertain": False,
            }

        urlopen.side_effect = [
            FakeResponse(
                {
                    "overall_confidence": 0.95,
                    "columns": ["Cable Reference", "FROM", "TO", "Conductor"],
                    "rows": [row(["C4", "T1-1", "Cu"])],
                },
                "qwen/qwen3-vl-30b-a3b-instruct",
            ),
            FakeResponse(
                {
                    "overall_confidence": 0.97,
                    "columns": ["Cable Reference", "FROM", "TO", "Conductor"],
                    "rows": [row(["C4", "T1-1", "T1-2", "Al"], 0.97)],
                },
                "qwen/qwen3-vl-235b-a22b-instruct",
            ),
        ]

        result = extract_table_with_qwen(b"image", "image/png")

        self.assertTrue(result.escalated)
        self.assertEqual(urlopen.call_count, 2)
        self.assertFalse(result.rows[0]["uncertain"])
        self.assertIn("TO T1-2", result.text)

    @patch.dict(
        "os.environ",
        {
            "FOUNDRY_ENDPOINT": "test-key",
            "VISION_OCR_ENABLED": "true",
            "VISION_OCR_ESCALATION_THRESHOLD": "0.82",
        },
    )
    @patch("rfq_parser.vision_ocr._openrouter_chat")
    def test_table_with_no_length_column_does_not_escalate(self, urlopen):
        # A route/size schedule prints no lengths at all, so "missing length" is the
        # correct reading and must not burn a second, larger model call.
        urlopen.return_value = FakeResponse(
            {
                "overall_confidence": 0.95,
                "columns": ["Cable Reference", "FROM", "TO", "Active (mm2)", "Conductor"],
                "rows": [
                    {
                        "section": "",
                        "code": "",
                        "cells": ["C1", "Sub-Station", "MSB", "300", "Al"],
                        "description": "",
                        "length": None,
                        "unit": None,
                        "fire_rated": False,
                        "confidence": 0.95,
                        "uncertain": False,
                    }
                ],
            },
            "qwen/qwen3-vl-30b-a3b-instruct",
        )

        result = extract_table_with_qwen(b"image", "image/png")

        self.assertTrue(result.ok)
        self.assertFalse(result.escalated)
        self.assertEqual(urlopen.call_count, 1)

    @unittest.skip("The source's vision-ocr route; the platform reads images through reader jobs.")
    @patch("app.extract_table_with_qwen")
    def test_endpoint_accepts_image_upload(self, extract):
        extract.return_value = VisionOcrResult(
            True,
            "qwen/qwen3-vl-8b-instruct",
            [],
            "260 meters 0.75mm2 Cu 4C+E PVC",
            0.95,
        )

        response = app.test_client().post(
            "/api/vision-ocr",
            data={"image": (io.BytesIO(b"image"), "rfq.png")},
            content_type="multipart/form-data",
        )

        self.assertEqual(response.status_code, 200)
        self.assertTrue(response.get_json()["ok"])


if __name__ == "__main__":
    unittest.main()
