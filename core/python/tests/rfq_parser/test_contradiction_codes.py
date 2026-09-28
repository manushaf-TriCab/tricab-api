# Generated from the RFQ parser's tests/test_contradiction_codes.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Structured contradiction codes, instead of the browser re-reading parser statuses.

`docs/SALES_REVIEW_AND_SYNC_TASKS.md` Batch 2: *"Add structured technical/quantity
contradiction codes to replace reliance on parser status."*

What was there before: `matcher/pipeline.py` defined the warning prefixes, `static/app.js`
repeated them as string literals under a comment reading *"change both or neither"*, and
the editor decided what a row meant by prefix-matching prose and switching on the
parser's status string. Two copies of a rule, in two languages, kept together by a
comment. This project has been bitten by that shape three times (the `.sql` mirror, the
Azure SQL schema, `_next_quote_version`).

Now the server classifies once and emits a code. The last class here is the drift guard:
while `static/app.js` still carries the prefixes as a pre-round-trip fallback, a test
fails if they stop matching the server's.
"""

from __future__ import annotations

import re
import unittest
from pathlib import Path

from rfq_parser import pipeline
from rfq_parser.quote_readiness import (
    COUNT_INFERENCE_WARNING_PREFIX,
    DOUBLE_COUNT_WARNING_PREFIX,
    MAKE_MOQ_WARNING_PREFIXES,
    PREPACK_WARNING_PREFIXES,
    QUANTITY_WARNING_PREFIXES,
    WARNING_CODES,
    quote_readiness,
    row_contradictions,
    warning_code,
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


def codes_for(**overrides):
    states = quote_readiness([row(**overrides)], QUOTE_DETAILS, reviewed=True)['row_states']
    return {entry['code']: entry for entry in states[0]['codes']}


class SeverityTests(unittest.TestCase):
    def test_a_clean_row_has_no_codes(self):
        self.assertEqual(codes_for(), {})

    def test_a_routine_default_is_a_note_and_does_not_block(self):
        readiness = quote_readiness(
            [row(warnings=[f'{PREPACK_WARNING_PREFIXES[0]} 3 x 100m'])],
            QUOTE_DETAILS, reviewed=True,
        )
        self.assertTrue(readiness['ready'], 'a routine default must not block a sync')
        state = readiness['row_states'][0]
        self.assertEqual(state['state'], 'note')
        self.assertEqual([entry['severity'] for entry in state['codes']], ['note'])

    def test_moq_rounding_is_a_note(self):
        for prefix in MAKE_MOQ_WARNING_PREFIXES:
            with self.subTest(prefix=prefix):
                codes = codes_for(warnings=[f'{prefix} 100m'])
                self.assertEqual([e['severity'] for e in codes.values()], ['note'])

    def test_an_inferred_count_is_a_contradiction_not_a_note(self):
        # Nothing re-checked it: `attachment_validate` can re-open a cell and cannot
        # re-derive an inference about one. A wrong quantity is the most expensive
        # error this system makes.
        codes = codes_for(warnings=[f'{COUNT_INFERENCE_WARNING_PREFIX} x4'])
        self.assertEqual(codes['quantity_count_inferred']['severity'], 'fix_required')

    def test_a_double_counted_cell_is_a_contradiction(self):
        codes = codes_for(warnings=[f'{DOUBLE_COUNT_WARNING_PREFIX} B7'])
        self.assertEqual(codes['quantity_double_counted']['severity'], 'fix_required')

    def test_an_assumption_is_a_note(self):
        self.assertEqual(codes_for(inferred_fields=['colour'])['assumed_field']['severity'],
                         'note')

    def test_an_ungrounded_source_is_a_note_on_a_reviewed_row(self):
        codes = codes_for(source={'input_type': 'vision_ocr'})
        self.assertEqual(codes['source_ungrounded']['severity'], 'note')
        self.assertIn('Compare this line', codes['source_ungrounded']['detail'])


class TechnicalCodeTests(unittest.TestCase):
    def test_each_known_status_gets_its_own_code(self):
        expected = {
            'unresolved': 'technical_unresolved',
            'needs_review': 'technical_needs_review',
            'ambiguous': 'technical_ambiguous',
            'no_match': 'technical_no_match',
        }
        for status, code in expected.items():
            with self.subTest(status=status):
                codes = codes_for(final_status=status)
                self.assertIn(code, codes)
                self.assertEqual(codes[code]['severity'], 'fix_required')
                self.assertNotIn(status, codes[code]['detail'],
                                 "the rep gets a sentence, not the parser's status string")

    def test_an_unknown_status_is_a_contradiction_not_an_absence_of_one(self):
        # The direction that matters. A status this code has never heard of must degrade
        # to "there is something to fix", never to "looks fine".
        codes = codes_for(final_status='some_status_added_in_2027')
        self.assertIn('technical_unknown_status', codes)
        self.assertEqual(codes['technical_unknown_status']['severity'], 'fix_required')

    def test_a_missing_product_is_named(self):
        self.assertIn('product_unresolved', codes_for(final_product_code=''))

    def test_an_invalid_quantity_and_price_are_named_separately(self):
        codes = codes_for(length={'total_length': 0}, final_unit_price=0,
                          pricing={'unit_price': 0})
        self.assertIn('quantity_invalid', codes)
        self.assertIn('price_invalid', codes)

    def test_pricing_still_loading_is_named(self):
        codes = codes_for(pricing={'status': 'pending', 'unit_price': 7.0})
        self.assertIn('pricing_pending', codes)

    def test_an_excluded_row_carries_no_codes(self):
        state = quote_readiness([row(disposition='excluded'), row()],
                                QUOTE_DETAILS, reviewed=True)['row_states'][0]
        self.assertEqual(state['state'], 'excluded')
        self.assertEqual(state['codes'], [])

    def test_contradictions_come_before_notes(self):
        state = quote_readiness(
            [row(final_status='unresolved', inferred_fields=['colour'])],
            QUOTE_DETAILS, reviewed=True,
        )['row_states'][0]
        severities = [entry['severity'] for entry in state['codes']]
        self.assertEqual(severities, sorted(severities, key=lambda s: s != 'fix_required'))

    def test_a_repeated_warning_is_reported_once(self):
        prefix = MAKE_MOQ_WARNING_PREFIXES[0]
        state = quote_readiness([row(warnings=[f'{prefix} 100m', f'{prefix} 100m'])],
                                QUOTE_DETAILS, reviewed=True)['row_states'][0]
        self.assertEqual(len(state['codes']), 1)

    def test_an_unaccounted_blocker_still_reaches_the_browser(self):
        # A blocking rule added later must be visible to a browser reading codes, not
        # silently absent from it.
        codes = row_contradictions(row(), [{'issue': 'a_rule_added_later',
                                           'reason': 'Something new blocks this line.'}])
        self.assertEqual([entry['code'] for entry in codes], ['blocked_other'])
        self.assertEqual(codes[0]['detail'], 'Something new blocks this line.')

    def test_accounted_blockers_are_not_repeated_as_blocked_other(self):
        state = quote_readiness([row(final_product_code='', final_status='unresolved',
                                     length={'total_length': 0})],
                                QUOTE_DETAILS, reviewed=True)['row_states'][0]
        self.assertNotIn('blocked_other', [entry['code'] for entry in state['codes']])


class WarningCodeTests(unittest.TestCase):
    def test_prose_with_no_known_prefix_is_not_a_code(self):
        self.assertIsNone(warning_code('Colour defaulted to BK.'))
        self.assertIsNone(warning_code(''))
        self.assertIsNone(warning_code(None))

    def test_every_registered_prefix_resolves(self):
        for prefix, code, severity in WARNING_CODES:
            with self.subTest(code=code):
                self.assertEqual(warning_code(f'{prefix} 123'), (code, severity))

    def test_the_codes_registry_covers_every_prefix_the_pipeline_writes(self):
        registered = {prefix for prefix, _code, _severity in WARNING_CODES}
        for prefix in (*QUANTITY_WARNING_PREFIXES, COUNT_INFERENCE_WARNING_PREFIX,
                       DOUBLE_COUNT_WARNING_PREFIX):
            with self.subTest(prefix=prefix):
                self.assertIn(prefix, registered,
                              'a warning the pipeline writes with no code is a row the '
                              'browser cannot classify')


class OneDefinitionTests(unittest.TestCase):
    """The prefixes exist once. These are the checks that keep it that way."""

    def test_the_pipeline_re_exports_rather_than_redefines(self):
        self.assertIs(pipeline.QUANTITY_WARNING_PREFIXES, QUANTITY_WARNING_PREFIXES)
        self.assertIs(pipeline.MAKE_MOQ_WARNING_PREFIXES, MAKE_MOQ_WARNING_PREFIXES)
        self.assertEqual(pipeline.COUNT_INFERENCE_WARNING_PREFIX,
                         COUNT_INFERENCE_WARNING_PREFIX)
        self.assertEqual(pipeline.DOUBLE_COUNT_WARNING_PREFIX, DOUBLE_COUNT_WARNING_PREFIX)

    def test_the_pipeline_no_longer_defines_them_itself(self):
        source = (ROOT.parent / 'rfq_parser' / 'pipeline.py').read_text(encoding='utf-8')
        for name in ('MAKE_MOQ_WARNING_PREFIXES', 'PREPACK_WARNING_PREFIXES',
                     'QUANTITY_WARNING_PREFIXES', 'COUNT_INFERENCE_WARNING_PREFIX',
                     'DOUBLE_COUNT_WARNING_PREFIX'):
            with self.subTest(name=name):
                self.assertNotRegex(source, rf'^{name} = ', )

    @unittest.skip("The old app's browser code. The platform web app replaces it and carries its own check that it speaks the server's vocabulary.")
    def test_the_browsers_fallback_prefixes_still_match_the_servers(self):
        """`static/app.js` keeps these for the window before the next readiness answer.

        The server is authoritative -- the editor switches on `row_states[].codes` -- but
        the browser still recomputes locally between a keystroke and the round trip. While
        those literals exist they must be the same strings, and this is the check that
        makes "change both or neither" an actual failure rather than a comment.

        Delete this test when app.js stops carrying the strings.
        """
        source = (ROOT / 'static' / 'app.js').read_text(encoding='utf-8')

        def literals(const_name):
            match = re.search(rf'const {const_name} = \[(.*?)\];', source, re.S)
            self.assertIsNotNone(match, f'{const_name} is gone from static/app.js')
            return tuple(re.findall(r'"((?:[^"\\]|\\.)*)"', match.group(1)))

        def unescape(values):
            return tuple(value.replace("\\'", "'").replace('\\"', '"') for value in values)

        self.assertEqual(unescape(literals('QUANTITY_WARNING_PREFIXES')),
                         QUANTITY_WARNING_PREFIXES)
        self.assertEqual(unescape(literals('READER_INFERENCE_WARNING_PREFIXES')),
                         (COUNT_INFERENCE_WARNING_PREFIX, DOUBLE_COUNT_WARNING_PREFIX))


if __name__ == '__main__':
    unittest.main()
