# Generated from the RFQ parser's tests/test_table_shape.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from rfq_parser import table_shape
from rfq_parser.table_shape import (
    _candidate_sheets,
    _validate_mapping,
    maybe_remap_sheets,
)

_ENABLED_ENV = {"RFQ_TABLE_SHAPE_LLM_ENABLED": "true", "FOUNDRY_ENDPOINT": "test-key"}


def _chat_response(mapping: dict):
    return {"choices": [{"message": {"content": json.dumps(mapping)}}]}, None


def _headerless_workbook(tmp: str) -> Path:
    from openpyxl import Workbook

    workbook = Workbook()
    sheet = workbook.active
    sheet.title = "Takeoff"
    # No header row at all -- the deterministic parser gets nothing here.
    sheet.append(["MSB-1", "4C+E 95mm2 Cu XLPE", 3, 120])
    sheet.append(["MSB-2", "2C+E 16mm2 Cu XLPE", 1, 45])
    sheet.append(["MSB-3", "1C 70mm2 Cu Earth", 2, 60])
    path = Path(tmp) / "takeoff.xlsx"
    workbook.save(path)
    return path


class GatingTests(unittest.TestCase):
    def test_disabled_by_default(self):
        self.assertFalse(table_shape.table_shape_enabled())

    def test_disabled_layer_never_calls_the_model(self):
        with patch("rfq_parser.table_shape._openrouter_chat") as chat:
            items = [{"text": "x", "sheet": "S", "row_number": 1}] * 5
            self.assertEqual(maybe_remap_sheets(Path("missing.xlsx"), items), items)
        chat.assert_not_called()

    def test_candidate_sheets_exclude_structured_and_junk(self):
        items = (
            [{"text": "a", "sheet": "raw", "metadata": {}}] * 3
            + [{"text": "b", "sheet": "good", "metadata": {"table_quantity": 5}}] * 3
            + [{"text": "c", "sheet": "junk", "metadata": {"low_relevance_sheet": True}}] * 3
            + [{"text": "d", "sheet": "tiny", "metadata": {}}]
        )
        self.assertEqual(_candidate_sheets(items), ["raw"])


class ValidationTests(unittest.TestCase):
    ROWS = [
        ["MSB-1", "4C+E 95mm2 Cu XLPE", "3", "120"],
        ["MSB-2", "2C+E 16mm2 Cu XLPE", "1", "45"],
        ["MSB-3", "1C 70mm2 Cu Earth", "2", "60"],
    ]

    def _mapping(self, **overrides):
        mapping = {
            "header_row": None,
            "columns": [
                {"index": 0, "role": "reference"},
                {"index": 1, "role": "description"},
                {"index": 2, "role": "cuts"},
                {"index": 3, "role": "quantity_each"},
            ],
        }
        mapping.update(overrides)
        return mapping

    def test_good_mapping_validates(self):
        header_row, roles = _validate_mapping(self._mapping(), self.ROWS)
        self.assertIsNone(header_row)
        self.assertEqual(roles[1], "description")
        self.assertEqual(roles[3], "quantity_each")

    def test_unknown_role_is_rejected(self):
        mapping = self._mapping(columns=[{"index": 1, "role": "product"}])
        self.assertIsNone(_validate_mapping(mapping, self.ROWS))

    def test_mapping_without_description_is_rejected(self):
        mapping = self._mapping(
            columns=[{"index": 0, "role": "reference"}, {"index": 3, "role": "quantity_each"}]
        )
        self.assertIsNone(_validate_mapping(mapping, self.ROWS))

    def test_mapping_without_quantity_is_rejected(self):
        mapping = self._mapping(
            columns=[{"index": 0, "role": "reference"}, {"index": 1, "role": "description"}]
        )
        self.assertIsNone(_validate_mapping(mapping, self.ROWS))

    def test_non_numeric_quantity_column_is_rejected(self):
        # The model claims the description column is the quantity: the cells
        # disprove it.
        mapping = self._mapping(
            columns=[
                {"index": 3, "role": "description"},
                {"index": 1, "role": "quantity_each"},
            ]
        )
        self.assertIsNone(_validate_mapping(mapping, self.ROWS))

    def test_out_of_range_header_row_is_rejected(self):
        self.assertIsNone(_validate_mapping(self._mapping(header_row=9), self.ROWS))

    def test_out_of_range_column_index_is_rejected(self):
        mapping = self._mapping(
            columns=[{"index": 11, "role": "description"}, {"index": 3, "role": "quantity_each"}]
        )
        self.assertIsNone(_validate_mapping(mapping, self.ROWS))


class RemapEndToEndTests(unittest.TestCase):
    MAPPING = {
        "header_row": None,
        "columns": [
            {"index": 0, "role": "reference"},
            {"index": 1, "role": "description"},
            {"index": 2, "role": "cuts"},
            {"index": 3, "role": "quantity_each"},
        ],
    }

    def test_validated_mapping_reparses_the_sheet_structurally(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = _headerless_workbook(tmp)
            from rfq_parser.file_parser import parse_xlsx

            raw_items = parse_xlsx(path)
            self.assertTrue(
                all((it.get("metadata") or {}).get("table_quantity") in (None, "") for it in raw_items)
            )
            with patch.dict(os.environ, _ENABLED_ENV), patch(
                "rfq_parser.table_shape._openrouter_chat",
                return_value=_chat_response(self.MAPPING),
            ):
                items = maybe_remap_sheets(path, raw_items)

        self.assertEqual(len(items), 3)
        first = items[0]
        self.assertEqual(first["text"], "4C+E 95mm2 Cu XLPE")
        self.assertEqual(first["metadata"]["table_quantity"], 120)
        self.assertEqual(first["metadata"]["number_of_lengths"], 3)
        self.assertEqual(first["metadata"]["reference"], "MSB-1")
        self.assertEqual(first["metadata"]["source_table_shape"], "llm_column_mapping")

    def test_failed_validation_keeps_original_items(self):
        bad_mapping = {"header_row": None, "columns": [{"index": 1, "role": "quantity_each"}]}
        with tempfile.TemporaryDirectory() as tmp:
            path = _headerless_workbook(tmp)
            from rfq_parser.file_parser import parse_xlsx

            raw_items = parse_xlsx(path)
            with patch.dict(os.environ, _ENABLED_ENV), patch(
                "rfq_parser.table_shape._openrouter_chat",
                return_value=_chat_response(bad_mapping),
            ):
                items = maybe_remap_sheets(path, raw_items)
        self.assertEqual(items, raw_items)

    def test_call_error_keeps_original_items(self):
        with tempfile.TemporaryDirectory() as tmp:
            path = _headerless_workbook(tmp)
            from rfq_parser.file_parser import parse_xlsx

            raw_items = parse_xlsx(path)
            with patch.dict(os.environ, _ENABLED_ENV), patch(
                "rfq_parser.table_shape._openrouter_chat",
                return_value=(None, "OpenRouter HTTP 500"),
            ):
                items = maybe_remap_sheets(path, raw_items)
        self.assertEqual(items, raw_items)


if __name__ == "__main__":
    unittest.main()
