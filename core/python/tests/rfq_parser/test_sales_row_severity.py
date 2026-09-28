# Generated from the RFQ parser's tests/test_sales_row_severity.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""What colour the sales grid paints a row, and why an assumption never earns one.

`docs/SALES_GRID_VIEW_ORDER.md` §1. The view used to take its colour from a row's
`state`, which is derived only from quote-level blockers, while the reason boxes under
the line took theirs from `codes[].severity`. The two disagreed in public: a line could
show a `Note` pill directly above a red "Fix required" box, which is the screenshot
Sales complained about.

Measured on the 18 rows of `tests/fixtures/go_live/draft-proof-redacted.json`: every row was `note`, and
119 of the 123 coded reasons were `assumed_field`. Colouring from `state` colours every
row, which is the same as colouring none.

So `severity` is computed once, server-side, and the pill and the tint both read it.
"""

from __future__ import annotations

import json
import unittest
from collections import Counter
from pathlib import Path

from rfq_parser.quote_readiness import (
    SALES_CHECK_CODES,
    SALES_SILENT_CODES,
    quote_readiness,
    sales_display,
    sales_severity,
)

ROOT = Path(__file__).resolve().parent.parent
QUOTE_DETAILS = {
    'customer_id': '1', 'contact': 'c', 'author': 'a',
    'salesoffice': 'PER', 'payterms': '30D', 'region': 'australia',
}


def row(**overrides):
    base = {
        'final_product_code': 'CODE-A', 'final_status': 'matched',
        'length': {'total_length': 100}, 'final_unit_price': 10.0,
        'pricing': {'unit_price': 7.0},
    }
    base.update(overrides)
    return base


def state_for(**overrides):
    states = quote_readiness([row(**overrides)], QUOTE_DETAILS, reviewed=True)['row_states']
    return states[0]


class AssumptionsAreNotAColour(unittest.TestCase):
    def test_any_number_of_assumed_fields_still_reads_ready(self):
        state = state_for(inferred_fields=['voltage', 'colour', 'armour', 'sheath'])
        self.assertEqual(state['severity'], 'ready')
        self.assertEqual({entry['display'] for entry in state['codes']}, {'note'})

    def test_an_assumption_is_still_reported__it_is_only_not_painted(self):
        state = state_for(inferred_fields=['voltage'])
        self.assertEqual([entry['code'] for entry in state['codes']], ['assumed_field'])
        self.assertEqual(state['codes'][0]['detail'], 'Assumed voltage')


class Amber(unittest.TestCase):
    def test_a_quantity_read_out_of_prose_is_check_not_fix_required(self):
        # `fix_required` for blocking purposes -- a wrong quantity is the most expensive
        # error this system makes -- and amber on screen, because the rep's job is to
        # check a number, not to unblock a sync.
        state = state_for(warnings=["Quantity multiplied by a count read from the row's text: x4"])
        self.assertEqual(state['severity'], 'check')
        entry, = [c for c in state['codes'] if c['code'] == 'quantity_count_inferred']
        self.assertEqual(entry['severity'], 'fix_required')
        self.assertEqual(entry['display'], 'check')

    def test_prepack_spools_is_check(self):
        state = state_for(warnings=['Prepack cable quoted as whole spools: RQ 342m -> 400m'])
        self.assertEqual(state['severity'], 'check')

    def test_a_line_read_from_a_scan_is_check(self):
        state = state_for(source={'input_type': 'vision_ocr'}, final_status='approved')
        self.assertEqual(state['severity'], 'check')

    def test_every_check_code_paints_amber(self):
        for code in SALES_CHECK_CODES:
            with self.subTest(code=code):
                self.assertEqual(sales_display(code, 'fix_required'), 'check')
                self.assertEqual(sales_display(code, 'note'), 'check')


class Red(unittest.TestCase):
    def test_no_product_code(self):
        self.assertEqual(state_for(final_product_code='')['severity'], 'fix_required')

    def test_an_unresolved_technical_selection(self):
        self.assertEqual(state_for(final_status='unresolved')['severity'], 'fix_required')

    def test_an_invalid_quantity(self):
        self.assertEqual(state_for(length={'total_length': 0})['severity'], 'fix_required')

    def test_a_blocking_rule_nobody_has_named_yet_still_paints_red(self):
        # `blocked_other` is the catch-all. An unrecognised blocker must degrade to
        # "there is something to fix here", never to "looks fine".
        self.assertEqual(sales_display('blocked_other', 'fix_required'), 'fix_required')


class PricingNeverColoursASalesRow(unittest.TestCase):
    """The view hides cost, margin and sell price by decision (SIMPLE_VIEW_HANDOFF §2),
    so the rep has no control that clears either of these. Flagging one queues work
    nobody in that view can do."""

    def test_a_row_waiting_on_pricing_reads_ready(self):
        state = state_for(pricing={'status': 'pending'})
        self.assertEqual(state['severity'], 'ready')
        self.assertIn('pricing_pending', {entry['code'] for entry in state['codes']})

    def test_a_row_with_no_price_reads_ready(self):
        state = state_for(final_unit_price=0, pricing={})
        self.assertEqual(state['severity'], 'ready')
        self.assertIn('price_invalid', {entry['code'] for entry in state['codes']})

    def test_the_sync_is_still_blocked__only_the_colour_changed(self):
        # The whole point is that the rep is not asked to do anything about it. The
        # preflight must go on refusing the quote.
        report = quote_readiness([row(pricing={'status': 'pending'})], QUOTE_DETAILS, reviewed=True)
        self.assertFalse(report['ready'])
        self.assertEqual(report['row_states'][0]['state'], 'fix_required')
        self.assertEqual(report['row_states'][0]['severity'], 'ready')

    def test_every_silent_code_is_a_note(self):
        for code in SALES_SILENT_CODES:
            with self.subTest(code=code):
                self.assertEqual(sales_display(code, 'fix_required'), 'note')


class WorstReasonWins(unittest.TestCase):
    def test_red_outranks_amber_outranks_plain(self):
        self.assertEqual(sales_severity([
            {'code': 'assumed_field', 'severity': 'note', 'display': 'note'},
            {'code': 'quantity_prepack_spools', 'severity': 'note', 'display': 'check'},
            {'code': 'product_unresolved', 'severity': 'fix_required', 'display': 'fix_required'},
        ]), 'fix_required')

    def test_amber_survives_a_pile_of_assumptions(self):
        state = state_for(
            inferred_fields=['voltage', 'colour', 'armour'],
            warnings=['Prepack cable quoted as whole spools: RQ 342m -> 400m'],
        )
        self.assertEqual(state['severity'], 'check')

    def test_the_reason_the_rep_must_act_on_sorts_first(self):
        # An amber reason is rendered inline and a note is collapsed, so an amber one
        # must not sort below a silent price code.
        state = state_for(
            inferred_fields=['voltage'],
            final_unit_price=0, pricing={},
            warnings=['Prepack cable quoted as whole spools: RQ 342m -> 400m'],
        )
        self.assertEqual(state['codes'][0]['display'], 'check')

    def test_no_codes_is_ready(self):
        self.assertEqual(sales_severity([]), 'ready')

    def test_an_excluded_row_says_so_rather_than_taking_a_colour(self):
        state = state_for(disposition='excluded', exclusion_reason='Customer withdrew it')
        self.assertEqual(state['severity'], 'excluded')
        self.assertEqual(state['codes'], [])


class TheProofQuote(unittest.TestCase):
    """The gate in §1: 14 plain, 4 amber, 0 red on the 18 rows we measured."""

    def test_the_18_rows_paint_14_plain_4_amber_0_red(self):
        draft = json.loads((ROOT / 'rfq_parser/fixtures/go_live/draft-proof-redacted.json').read_text())['draft']
        states = quote_readiness(draft['rows'], draft['quote_details'])['row_states']
        self.assertEqual(len(states), 18)
        self.assertEqual(
            Counter(entry['severity'] for entry in states),
            Counter({'ready': 14, 'check': 4}),
        )

    def test_colouring_from_state_would_have_painted_every_row(self):
        # The measurement the decision rests on, kept executable: if this ever stops
        # being true the argument in §1 needs re-making, not the code quietly following.
        draft = json.loads((ROOT / 'rfq_parser/fixtures/go_live/draft-proof-redacted.json').read_text())['draft']
        states = quote_readiness(draft['rows'], draft['quote_details'])['row_states']
        self.assertEqual({entry['state'] for entry in states}, {'note'})
        codes = Counter(code['code'] for entry in states for code in entry['codes'])
        self.assertEqual(codes['assumed_field'], 119)


@unittest.skip("The old app's browser code. The platform web app replaces it and carries its own check that it speaks the server's vocabulary.")
class TheBrowserSpeaksTheSameVocabulary(unittest.TestCase):
    """The server classifies once and the browser switches on the answer. While the
    browser carries the vocabulary as literals, they have to be the same words.

    This is the shape that has bitten this project repeatedly -- two copies of a rule in
    two languages, kept together by a comment. Here the failure would be quiet: an
    unrecognised severity renders as "Checking…" forever, on a row that is actually
    blocked, and nothing else says so.
    """

    #: every value `sales_severity` can return, plus the browser's own "no answer yet"
    SEVERITIES = {'fix_required', 'check', 'ready', 'excluded'}

    def app_js(self, which):
        if which == 'deploy':
            path = ROOT / 'deploy/rfq-website-upload/theme-root/rfq-parser-assets/rfq-app.js'
        else:
            path = ROOT / 'static/app.js'
        return path.read_text(encoding='utf-8')

    def labelled(self, source):
        import re
        match = re.search(r'const SALES_STATUS_LABEL = \{(.*?)\};', source, re.S)
        self.assertIsNotNone(match, 'SALES_STATUS_LABEL is gone from the editor')
        return set(re.findall(r'^\s*(\w+):', match.group(1), re.M))

    def test_the_editor_labels_every_severity_the_server_emits(self):
        for which in ('deploy', 'static'):
            with self.subTest(copy=which):
                labels = self.labelled(self.app_js(which))
                self.assertEqual(
                    self.SEVERITIES - labels, set(),
                    'a severity the server can emit that the browser cannot name renders '
                    'as "Checking…" on a row that may actually be blocked',
                )
                self.assertIn('pending', labels, 'the browser needs its own "no answer yet"')

    def test_the_editor_invents_no_severity_of_its_own(self):
        for which in ('deploy', 'static'):
            with self.subTest(copy=which):
                self.assertEqual(self.labelled(self.app_js(which)) - self.SEVERITIES,
                                 {'pending'})

    def test_the_filter_chips_are_severities(self):
        import re
        for which in ('deploy', 'static'):
            with self.subTest(copy=which):
                match = re.search(r'const SALES_CHIP_STATUSES = \[(.*?)\];',
                                  self.app_js(which), re.S)
                self.assertIsNotNone(match)
                chips = set(re.findall(r'"(\w+)"', match.group(1)))
                self.assertEqual(chips - self.SEVERITIES, set())

    def test_pricing_codes_are_named_once__on_the_server(self):
        """The browser must not carry its own list of which codes are price-only.

        That judgement -- "the rep has no control in this view that clears it" -- is
        `SALES_SILENT_CODES`, and a second copy of it in the editor is how the pill and
        the tint came to disagree in the first place. Prose may discuss them; code may
        not switch on them.
        """
        import re
        for which in ('deploy', 'static'):
            with self.subTest(copy=which):
                source = self.app_js(which)
                code = re.sub(r'//[^\n]*', '', re.sub(r'/\*.*?\*/', '', source, flags=re.S))
                for name in ('pricing_pending', 'price_invalid'):
                    self.assertNotIn(name, code)


if __name__ == '__main__':
    unittest.main()
