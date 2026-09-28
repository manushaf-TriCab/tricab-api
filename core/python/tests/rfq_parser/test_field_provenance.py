# Generated from the RFQ parser's tests/test_field_provenance.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Who set each value in the resolver's `fields` dict.

`explicit_fields` used to be `set(fields)` -- every *populated* key, not every key the
customer *stated* -- so a house default and an LLM guess carried the same authority as a
fact the customer typed, and `violates_hard_constraints` turned all three into hard
filters alike. These tests pin the four sources and the one rule that reads them.
"""

import unittest

from rfq_parser.pipeline import catalog
from tricab_cable_rules import resolver
from tricab_cable_rules.extractor import (
    FIELD_SOURCE_AGENT,
    FIELD_SOURCE_CUSTOMER,
    FIELD_SOURCE_DEFAULT,
)
from tricab_cable_rules.resolver import match_line


def sources(text, **kwargs):
    return match_line(text, catalog, **kwargs)["field_sources"]


class FieldSourceAttributionTests(unittest.TestCase):
    def test_a_regex_read_of_the_rows_own_text_is_the_customers(self):
        got = sources("4C 35mm2 XLPE PVC 0.6/1kV pink")
        for key in ("count", "cross_section", "colour", "voltage_set", "pvc"):
            self.assertEqual(got[key], FIELD_SOURCE_CUSTOMER, key)

    def test_an_agent_override_is_attributed_to_the_agent(self):
        got = sources("4C 2.5mm2 cable", field_overrides={"conductor": "Cu", "count": 4})
        self.assertEqual(got["conductor"], FIELD_SOURCE_AGENT)
        self.assertEqual(got["count"], FIELD_SOURCE_AGENT)
        # The row's own reading is untouched where the agent said nothing.
        self.assertEqual(got["cross_section"], FIELD_SOURCE_CUSTOMER)

    def test_an_agent_override_wins_the_attribution_even_over_a_regex_read(self):
        # The agent's value is the one that survives into `fields`, so the agent owns it.
        got = sources("4C 2.5mm2 cable", field_overrides={"count": 7})
        self.assertEqual(got["count"], FIELD_SOURCE_AGENT)

    def test_a_house_default_is_attributed_to_the_default(self):
        self.assertEqual(sources("1C 25mm2 switchboard cable")["colour"], FIELD_SOURCE_DEFAULT)
        self.assertEqual(sources("1C 16mm2 earth cable")["colour"], FIELD_SOURCE_DEFAULT)

    def test_a_stated_colour_on_a_switchboard_row_stays_the_customers(self):
        self.assertEqual(sources("1C 25mm2 pink switchboard cable")["colour"], FIELD_SOURCE_CUSTOMER)

    def test_resolver_enrichment_is_a_default(self):
        got = sources("4C 35mm2 XLPE PVC 0.6/1kV")
        self.assertEqual(got["style"], FIELD_SOURCE_DEFAULT)
        self.assertEqual(got["colour"], FIELD_SOURCE_DEFAULT)


class OnlyTheAgentIsSoftTests(unittest.TestCase):
    """The rule `explicit_fields` now applies, and the two ways it could be got wrong.

    Narrower than "only `customer` is hard" on purpose -- see the comment at the
    `explicit_fields` assignment. Excluding defaults too was measured and regressed the
    switchboard orange, so both directions are pinned here.
    """

    def test_an_agent_proposed_colour_never_synthesises_a_code(self):
        """§1's whole reason for blocking: `resolver.py:1179` synthesises a product code in
        a stated colour even when nothing is catalogued in it. Right for a customer
        statement; for an agent guess it invents a code for a cable that does not exist and
        returns it `matched`. Patched in rather than waiting for the schema step, so the
        guarantee is pinned before the field that needs it exists."""
        original = resolver.LLM_OVERRIDE_KEYS
        resolver.LLM_OVERRIDE_KEYS = frozenset(original | {"colour"})
        try:
            agent_colour = match_line(
                "4C 35mm2 XLPE PVC 0.6/1kV", catalog, field_overrides={"colour": "PI"}
            )
        finally:
            resolver.LLM_OVERRIDE_KEYS = original

        self.assertEqual(agent_colour["field_sources"]["colour"], FIELD_SOURCE_AGENT)
        code = agent_colour["selected_product_code"]
        self.assertNotIn("PI", code, f"agent colour reached the synthesis branch: {code}")
        self.assertFalse(
            [w for w in agent_colour["warnings"] if "requested colour" in w],
            "agent colour was treated as a firm customer requirement",
        )

    def test_a_customer_stated_colour_still_synthesises_a_code(self):
        """The other direction: this behaviour is correct and must not be lost."""
        stated = match_line("4C 35mm2 XLPE PVC 0.6/1kV pink", catalog)
        self.assertEqual(stated["selected_product_code"], "XL-PAXN/4C35PI")
        self.assertTrue([w for w in stated["warnings"] if "requested colour PI" in w])

    def test_a_defaulted_switchboard_colour_still_filters(self):
        """Excluding FIELD_SOURCE_DEFAULT from `explicit_fields` picked a stocked black at
        25mm and the EA earth jacket at 6mm -- an earth colour on a switchboard wire."""
        self.assertEqual(
            match_line("1C 25mm2 switchboard cable", catalog)["selected_product_code"],
            "LS-XXXN/1C25OR",
        )
        self.assertEqual(
            match_line("1C 6mm2 switchboard wire", catalog)["selected_product_code"],
            "LS-XXXD/1C6OR",
        )

    def test_the_switchboard_default_is_still_named_in_the_warning(self):
        """`switchboard_colour_defaulted` was a local boolean because `fields` had nowhere
        to record it. It now reads off the provenance map, and must still fire."""
        warnings = match_line("1C 6mm2 switchboard wire", catalog)["warnings"]
        self.assertTrue(
            [w for w in warnings if w.startswith("Switchboard colour not stated")],
            warnings,
        )


if __name__ == "__main__":
    unittest.main()
