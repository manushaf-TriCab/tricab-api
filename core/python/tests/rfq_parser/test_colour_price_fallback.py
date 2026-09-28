# Generated from the RFQ parser's tests/test_colour_price_fallback.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""When the catalogue's colour cannot be priced, the style's default colour is tried.

The case this is written against, from a live RFQ: "VSD FLEX 120MM 3C&3E LSZH FLEXIBLE
VSD/VFD DRIVE CABLE" resolved to `EU-PAZN/4C120OR` and then could not be priced. The
catalogue extract carries that product in orange; the cost API only knows it in black; and
the catalogue's own `Default Clr` for EU is BK. Three sources say black, one says orange,
and the odd one out is the only one the resolver could select from.

23 EU codes and ~625 catalogue-wide share that shape. What is asserted here is not that
the swap is always right -- it is that it never happens against a customer who asked for a
colour, never happens when the API is merely down, and is never silent.
"""

from __future__ import annotations

import unittest
from unittest.mock import patch

from rfq_parser import pipeline
from tricab_cable_rules.extractor import FIELD_SOURCE_CUSTOMER, FIELD_SOURCE_DEFAULT


def _row(colour="OR", source=FIELD_SOURCE_DEFAULT, style="EU", code="EU-PAZN/4C120OR"):
    return {
        "status": "matched",
        "final_product_code": code,
        "selected_product_code": code,
        "selected_product": {"style": style, "colour": colour, "product_code": code},
        "field_sources": {"colour": source},
        "length": {"total_length": 100},
    }


def _priced(code):
    return {"status": "available", "product_code": code, "unit_price": 42.0,
            "price_uom": "m", "default_length": None, "cost_status": "STOCK"}


def _not_found(code):
    return {"status": "not_found", "product_code": code, "unit_price": None, "price_uom": "m"}


def _error(code):
    return {"status": "error", "product_code": code, "unit_price": None, "price_uom": "m",
            "error": "Cost API timed out"}


class DefaultColourTwinTests(unittest.TestCase):
    def test_the_twin_is_the_same_code_in_the_styles_default_colour(self):
        self.assertEqual(
            pipeline._default_colour_twin(_row(), "EU-PAZN/4C120OR"), "EU-PAZN/4C120BK"
        )

    def test_a_colour_the_customer_stated_is_never_swapped(self):
        """The guard that matters. Shipping a different colour than the one asked for is a
        worse failure than a quote that stops."""
        row = _row(source=FIELD_SOURCE_CUSTOMER)
        self.assertEqual(pipeline._default_colour_twin(row, "EU-PAZN/4C120OR"), "")

    def test_a_code_already_in_the_default_colour_has_no_twin(self):
        row = _row(colour="BK", code="EU-PAZN/4C120BK")
        self.assertEqual(pipeline._default_colour_twin(row, "EU-PAZN/4C120BK"), "")

    def test_no_twin_when_the_style_is_unknown(self):
        row = _row(style="ZZ")
        self.assertEqual(pipeline._default_colour_twin(row, "EU-PAZN/4C120OR"), "")

    def test_no_twin_when_the_code_does_not_end_in_the_selected_colour(self):
        """Never reshape a code by string surgery it does not support."""
        row = _row()
        self.assertEqual(pipeline._default_colour_twin(row, "EU-PAZN/4C120"), "")

    def test_the_real_eu_style_default_is_black(self):
        """Not a fixture: the catalogue's own `Default Clr` column."""
        self.assertEqual(pipeline.catalog.styles["EU"].default_colour, "BK")


class LookupPriceFallbackTests(unittest.TestCase):
    def test_an_unpriced_catalogue_code_is_quoted_in_the_default_colour(self):
        row = _row()
        calls = []

        def lookup(code, length, region=None):
            calls.append(code)
            return _priced(code) if code.endswith("BK") else _not_found(code)

        with patch.object(pipeline.cost_api, "lookup", lookup):
            pricing = pipeline._lookup_price([row], "EU-PAZN/4C120OR", 100)

        self.assertEqual(pricing["status"], "available")
        self.assertEqual(calls, ["EU-PAZN/4C120OR", "EU-PAZN/4C120BK"])
        self.assertEqual(row["final_product_code"], "EU-PAZN/4C120BK")

    def test_the_swap_is_recorded_and_warned_about(self):
        row = _row()
        with patch.object(pipeline.cost_api, "lookup",
                          lambda c, l, r=None: _priced(c) if c.endswith("BK") else _not_found(c)):
            pipeline._lookup_price([row], "EU-PAZN/4C120OR", 100)

        self.assertEqual(row["colour_priced_fallback"]["catalog_code"], "EU-PAZN/4C120OR")
        self.assertEqual(row["colour_priced_fallback"]["priced_code"], "EU-PAZN/4C120BK")
        self.assertTrue(any("Confirm the colour" in w for w in row["warnings"]))

    def test_a_customer_stated_colour_is_left_to_fail(self):
        row = _row(source=FIELD_SOURCE_CUSTOMER)
        calls = []

        def lookup(code, length, region=None):
            calls.append(code)
            return _not_found(code)

        with patch.object(pipeline.cost_api, "lookup", lookup):
            pricing = pipeline._lookup_price([row], "EU-PAZN/4C120OR", 100)

        self.assertEqual(pricing["status"], "not_found")
        self.assertEqual(calls, ["EU-PAZN/4C120OR"], "no second call for a stated colour")
        self.assertEqual(row["final_product_code"], "EU-PAZN/4C120OR")

    def test_an_api_outage_does_not_double_the_traffic(self):
        """`error` is the API being unreachable. Retrying a second code through an outage
        answers nothing and doubles every call."""
        row = _row()
        calls = []

        def lookup(code, length, region=None):
            calls.append(code)
            return _error(code)

        with patch.object(pipeline.cost_api, "lookup", lookup):
            pricing = pipeline._lookup_price([row], "EU-PAZN/4C120OR", 100)

        self.assertEqual(pricing["status"], "error")
        self.assertEqual(calls, ["EU-PAZN/4C120OR"])

    def test_when_neither_colour_prices_the_original_failure_is_reported(self):
        row = _row()
        with patch.object(pipeline.cost_api, "lookup", lambda c, l, r=None: _not_found(c)):
            pricing = pipeline._lookup_price([row], "EU-PAZN/4C120OR", 100)

        self.assertEqual(pricing["product_code"], "EU-PAZN/4C120OR")
        self.assertEqual(row["final_product_code"], "EU-PAZN/4C120OR")
        self.assertNotIn("colour_priced_fallback", row)

    def test_every_row_in_a_make_moq_group_is_swapped_together(self):
        """One lookup prices the whole group. A swap on the first row only would push the
        priced code on one line and the unpriceable one on its neighbours."""
        rows = [_row(), _row(), _row()]
        with patch.object(pipeline.cost_api, "lookup",
                          lambda c, l, r=None: _priced(c) if c.endswith("BK") else _not_found(c)):
            pipeline._lookup_price(rows, "EU-PAZN/4C120OR", 300)

        self.assertEqual({r["final_product_code"] for r in rows}, {"EU-PAZN/4C120BK"})

    def test_pricing_a_row_twice_warns_once(self):
        """The prepack path looks a row up again after snapping its length."""
        row = _row()
        with patch.object(pipeline.cost_api, "lookup",
                          lambda c, l, r=None: _priced(c) if c.endswith("BK") else _not_found(c)):
            pipeline._lookup_price([row], "EU-PAZN/4C120OR", 100)
            pipeline._lookup_price([row], "EU-PAZN/4C120OR", 200)

        self.assertEqual(len(row["warnings"]), 1)

    def test_a_priced_code_is_never_looked_up_twice(self):
        row = _row(colour="BK", code="EU-PAZN/4C120BK")
        calls = []

        def lookup(code, length, region=None):
            calls.append(code)
            return _priced(code)

        with patch.object(pipeline.cost_api, "lookup", lookup):
            pipeline._lookup_price([row], "EU-PAZN/4C120BK", 100)

        self.assertEqual(calls, ["EU-PAZN/4C120BK"])


if __name__ == "__main__":
    unittest.main()
