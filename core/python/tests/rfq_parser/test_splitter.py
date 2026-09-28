# Generated from the RFQ parser's tests/test_splitter.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
import unittest

from rfq_parser.splitter import split_compound_line


class SeparateEarthSplitTests(unittest.TestCase):
    def _count(self, text):
        return len(split_compound_line(text))

    def test_attached_earth_stays_one_cable(self):
        # The cable's own earth, however written, is one construction.
        self.assertEqual(self._count("4C + E"), 1)
        self.assertEqual(self._count("3C + 3E"), 1)
        self.assertEqual(self._count("2C+E 25mm2"), 1)

    def test_cable_size_after_earth_is_not_a_separate_earth(self):
        # "4C+E 300mm2": the 300mm2 is the cable's conductor size, not a separate earth.
        self.assertEqual(self._count("4C+E 300mm2"), 1)
        self.assertEqual(self._count("SDI Flex 0.6/1K 4C + E 300mm2 Cu R-HF-110 HF-110-R"), 1)

    def test_green_yellow_earth_adjective_does_not_split(self):
        self.assertEqual(self._count("4C+E green/yellow earth 25mm2"), 1)

    def test_sized_earth_splits_into_two_cables(self):
        self.assertEqual(self._count("4C 16mm2 Cu XLPE + 1C 16mm2 Earth"), 2)
        self.assertEqual(self._count("4C 16mm2 + 1C 10mm2 E"), 2)

    def test_spelled_out_earth_item_splits(self):
        self.assertEqual(self._count("1 x 4C 16mm2 cable + 1 Earth"), 2)


class ProseAndSplitTests(unittest.TestCase):
    """An RFQ typed as a sentence joins its cables with "and", not "+"."""

    def _count(self, text):
        return len(split_compound_line(text))

    def test_prose_and_splits_two_cables(self):
        # The reported line: this used to come back as a single row -- the 400mm size
        # wearing the earth's attributes, quoted as one 400mm earth cable.
        parts = split_compound_line(
            "Can you please quote me 4 drums of 400mm copper flex xlpe at 75 meters "
            "each drum and 1 drum of 120mm flex Earth"
        )
        self.assertEqual(len(parts), 2)
        self.assertIn("400mm", parts[0].text)
        self.assertNotIn("120mm", parts[0].text)
        self.assertIn("120mm", parts[1].text)
        self.assertEqual(parts[0].split_reason, "compound_and")

    def test_ampersand_splits(self):
        self.assertEqual(self._count("100m of 3C 25mm2 XLPE & 50m of 1C 10mm2 earth"), 2)

    def test_with_splits_a_counted_mains_and_earth_sentence(self):
        parts = split_compound_line(
            "24* 400mm 110 degree AL @ 110M with 6* 185mm AL earth @ 110M"
        )

        self.assertEqual(len(parts), 2)
        self.assertEqual(parts[0].text, "24* 400mm 110 degree AL @ 110M")
        self.assertEqual(parts[1].text, "6* 185mm AL earth @ 110M")
        self.assertEqual(parts[0].split_reason, "compound_with")

    def test_descriptive_with_does_not_split(self):
        self.assertEqual(self._count("1C 400mm2 flexible cable with LSZH sheath"), 1)

    def test_descriptive_and_does_not_split(self):
        # "and" joining adjectives is not a compound line: both sides must independently
        # look like a cable before the separator is trusted.
        self.assertEqual(self._count("4C+E 300mm2 XLPE and PVC sheathed"), 1)
        self.assertEqual(self._count("3C 240mm2 XLPE armoured and unarmoured"), 1)
        self.assertEqual(self._count("Supply and install 240mm2 cable"), 1)
        self.assertEqual(self._count("1C 400mm2 flexible and fine stranded"), 1)


if __name__ == "__main__":
    unittest.main()
