# Generated from the RFQ parser's tests/test_material_column.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""The Material column, the cut-count aliases, and what an unmapped column now leaves behind.

BODY_TABLE_FIDELITY_HANDOFF.md §7 items 1, 2, 3 and 5. The measured failure these close:
on the Cairns schedules `header_role()` returned "other" for Material, so the column was
dropped before the resolver saw the row, and every aluminium line came back as a copper
product -- `matched`, at high confidence, on all 27 rows.
"""

from __future__ import annotations

import unittest

from rfq_parser.file_parser import (
    build_structured_xlsx_item,
    canonical_conductor_material,
    header_role,
)
from rfq_parser.pipeline import match_source_line
from rfq_parser.table_shape import _is_improvement


CAIRNS_HEADERS = [
    "Cable Ref",
    "Size (mm2)",
    "Material",
    "Cores",
    "Length +5% Contingency (m)",
    "Cuts (Mains)",
]


def cairns_row(material: str = "Al"):
    return build_structured_xlsx_item(
        ["PVDB-01", "150", material, "1", "69", "2"], CAIRNS_HEADERS
    )


class MaterialRoleTests(unittest.TestCase):
    """§7 item 3: the conductor metal gets a role and reaches the resolver."""

    def test_a_material_column_is_no_longer_dropped(self):
        self.assertEqual(header_role("Material"), "conductor_material")
        self.assertEqual(header_role("Conductor Material"), "conductor_material")

    def test_a_sized_conductor_column_is_still_a_size_not_a_metal(self):
        # Exact matching is what keeps these apart.
        self.assertNotEqual(header_role("Conductor (mm2)"), "conductor_material")

    def test_a_bare_conductor_column_is_not_claimed(self):
        # As a table column "Conductor" holds a size at least as often as a metal, so
        # claiming it would lose the size on those sheets. Deliberately left alone, even
        # though transposed_row_role accepts it in a context where it is unambiguous.
        self.assertNotEqual(header_role("Conductor"), "conductor_material")

    def test_aluminium_no_longer_resolves_to_a_copper_product(self):
        item = cairns_row("Al")
        self.assertIn("AL", item["text"].upper().split())
        self.assertEqual(item["metadata"]["conductor_material"], "AL")
        row = match_source_line(
            item["text"], {"line_number": 1}, metadata=item["metadata"], allow_llm_fallback=False
        )[0]
        # Was XL-PAXN/1C150OR -- a copper product, `matched`, on an aluminium row.
        self.assertIn("PAXA", str(row.get("selected_product_code")))

    def test_copper_is_unchanged(self):
        item = cairns_row("Cu")
        row = match_source_line(
            item["text"], {"line_number": 1}, metadata=item["metadata"], allow_llm_fallback=False
        )[0]
        self.assertEqual(row.get("selected_product_code"), "XL-PAXN/1C150OR")

    def test_the_material_vocabulary_is_a_closed_set(self):
        for spelling in ("Al", "AL", "aluminium", "Aluminum"):
            self.assertEqual(canonical_conductor_material(spelling), "AL")
        for spelling in ("Cu", "copper"):
            self.assertEqual(canonical_conductor_material(spelling), "CU")
        for spelling in ("TC", "tinned", "Tinned Copper"):
            self.assertEqual(canonical_conductor_material(spelling), "TINNED")
        self.assertEqual(canonical_conductor_material("PVC"), "")

    def test_a_sheath_in_the_material_column_is_not_folded_into_the_text(self):
        # "PVC" is not a conductor metal. Folding it into the row text would change how
        # the row resolves, so it goes back to unmapped_columns under its own header.
        item = cairns_row("PVC")
        self.assertNotIn("PVC", item["text"].upper())
        self.assertNotIn("conductor_material", item["metadata"])
        self.assertEqual(item["metadata"]["unmapped_columns"].get("Material"), "PVC")


class CutAliasTests(unittest.TestCase):
    """§7 item 5: a pure alias gap -- the roles already existed."""

    def test_a_mains_qualified_cut_count_is_a_cut_count(self):
        for spelling in ("Cuts (Mains)", "Cuts/Mains", "No. of Cuts (Mains)", "Mains Cuts"):
            self.assertEqual(header_role(spelling), "cuts", spelling)

    def test_an_earth_qualified_cut_count_belongs_to_the_earth(self):
        self.assertEqual(header_role("Cuts (Earth)"), "earth_cuts")

    def test_a_per_cut_length_is_still_a_quantity(self):
        # Why the rule matches only the mains-qualified spellings: this is a LENGTH.
        self.assertEqual(header_role("Qty per cut"), "quantity")

    def test_an_earth_cut_length_keeps_its_length_role(self):
        self.assertEqual(header_role("Earth Cut Length (m)"), "earth_length")

    def test_the_cut_count_reaches_the_row(self):
        self.assertEqual(cairns_row()["metadata"]["number_of_lengths"], 2)


class UnmappedColumnTests(unittest.TestCase):
    """§7 item 1: an unrecognised column is a signal, not a silent drop."""

    def test_what_the_parser_did_not_understand_is_kept(self):
        unmapped = cairns_row()["metadata"]["unmapped_columns"]
        self.assertEqual(unmapped.get("Cable Ref"), "PVDB-01")
        self.assertEqual(unmapped.get("Cores"), "1")

    def test_a_recognised_column_is_not_listed_as_unmapped(self):
        unmapped = cairns_row()["metadata"]["unmapped_columns"]
        self.assertNotIn("Material", unmapped)
        self.assertNotIn("Cuts (Mains)", unmapped)

    def test_a_row_with_nothing_unrecognised_carries_no_key(self):
        item = build_structured_xlsx_item(["4C 16mm2 XLPE", "100"], ["Description", "Length"])
        self.assertNotIn("unmapped_columns", item.get("metadata") or {})


class RemapImprovementTests(unittest.TestCase):
    """§7 item 2: a real comparison, not a count of one field."""

    def _item(self, text, **metadata):
        return {"text": text, "metadata": dict(metadata)}

    def test_a_mapping_that_gains_a_quantity_and_loses_the_size_is_rejected(self):
        # The §3 regression. The old check counted structured quantities only, so this
        # passed: flash-lite gained a quantity on every row and dropped the conductor
        # size, which lives in the text where nothing was looking.
        old = [self._item("120mm2 XLPE"), self._item("95mm2 XLPE")]
        new = [
            self._item("XLPE", table_quantity=50),
            self._item("XLPE", table_quantity=60),
        ]
        self.assertFalse(_is_improvement(old, new))

    def test_a_mapping_that_keeps_everything_and_adds_a_quantity_is_accepted(self):
        old = [self._item("120mm2 XLPE"), self._item("95mm2 XLPE")]
        new = [
            self._item("120mm2 XLPE", table_quantity=50),
            self._item("95mm2 XLPE", table_quantity=60),
        ]
        self.assertTrue(_is_improvement(old, new))

    def test_a_mapping_that_trades_one_field_for_another_is_not_an_improvement(self):
        old = [self._item("120mm2", reference="C-01")]
        new = [self._item("120mm2", table_quantity=50)]
        self.assertFalse(_is_improvement(old, new))

    def test_a_mapping_that_understands_fewer_columns_is_rejected(self):
        old = [self._item("120mm2", table_quantity=50)]
        new = [
            self._item(
                "120mm2", table_quantity=50, reference="C-01", unmapped_columns={"A": "1", "B": "2"}
            )
        ]
        self.assertFalse(_is_improvement(old, new))

    def test_an_empty_remap_is_rejected(self):
        self.assertFalse(_is_improvement([self._item("120mm2")], []))


if __name__ == "__main__":
    unittest.main()
