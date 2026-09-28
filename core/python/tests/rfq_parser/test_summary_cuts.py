# Generated from the RFQ parser's tests/test_summary_cuts.py by tools/sync_rfq_parser.py.
# Change the source or the tool's declared patches, never this file.
"""Cut breakdowns on a consolidated Product Summary line.

`docs/SALES_GRID_VIEW_ORDER.md` §3. Syncing from the Product Summary panel turns each
product into one synthetic line, so the per-line circuit labels and cut breakdowns the
normal push sends to 4js are gone entirely. Sales hit that consequence and asked for the
cut information back.

Built server-side, on the summary entry, so the summary table, the CSV and the 4js
comment render from one source instead of three that drift.

Two rules differ from the per-line `_format_cuts`, deliberately: a single cut must show,
and identical cuts are never merged.

The comment half of this is NOT wired up: §3.1 blocks shipping cuts into 4js until
somebody who can exercise 4js establishes its real line-comment limit. `format_summary_cuts`
and its `+N more` tail are built and tested here so that turning it on is one line.
"""

from __future__ import annotations

import json
import unittest
from pathlib import Path

from rfq_parser.pipeline import (
    _format_cuts,
    build_product_summary,
    format_summary_cuts,
    summary_cut,
)

ROOT = Path(__file__).resolve().parent.parent


def cable(code, cuts, each, **overrides):
    row = {
        'final_product_code': code, 'final_status': 'matched', 'item_type': 'cable',
        'length': {'number_of_lengths': cuts, 'length_each': each,
                   'total_length': cuts * each},
    }
    row.update(overrides)
    return row


class OneLinesCuts(unittest.TestCase):
    def test_a_multi_cut_line(self):
        self.assertEqual(summary_cut({'number_of_lengths': 6, 'length_each': 35})['text'],
                         '(6 x 35)')

    def test_a_single_cut_shows_here_and_is_suppressed_per_line(self):
        # `_format_cuts` drops `1 x 884` because on one line it is identical to the total
        # sitting beside it. In a consolidated list, dropping it means the cuts stop
        # accounting for the product's total.
        length = {'number_of_lengths': 1, 'length_each': 884, 'total_length': 884}
        self.assertEqual(_format_cuts(length), '')
        self.assertEqual(summary_cut(length)['text'], '(1 x 884)')

    def test_a_total_with_no_per_cut_length_is_one_cut_of_the_total(self):
        self.assertEqual(summary_cut({'total_length': 250})['text'], '(1 x 250)')

    def test_a_line_with_no_quantity_at_all_contributes_nothing(self):
        self.assertIsNone(summary_cut({}))
        self.assertIsNone(summary_cut(None))

    def test_a_fractional_length_keeps_its_decimals(self):
        self.assertEqual(summary_cut({'number_of_lengths': 2, 'length_each': 12.5})['text'],
                         '(2 x 12.5)')

    def test_the_structured_numbers_travel_with_the_text(self):
        cut = summary_cut({'number_of_lengths': 6, 'length_each': 35})
        self.assertEqual(cut['number_of_lengths'], 6)
        self.assertEqual(cut['length_each'], 35)


class NeverMerged(unittest.TestCase):
    """Sales reconciles against the customer's BoQ line by line."""

    def test_two_identical_lines_render_twice(self):
        summary = build_product_summary([cable('KL-A', 6, 35), cable('KL-A', 6, 35)])
        self.assertEqual(summary[0]['cuts_text'], '(6 x 35), (6 x 35)')
        self.assertNotIn('(12 x 35)', summary[0]['cuts_text'])

    def test_cuts_stay_in_the_order_the_lines_were_quoted(self):
        summary = build_product_summary([cable('KL-A', 1, 40), cable('KL-A', 1, 745)])
        self.assertEqual(summary[0]['cuts_text'], '(1 x 40), (1 x 745)')

    def test_each_product_keeps_only_its_own_cuts(self):
        summary = {entry['product_code']: entry for entry in
                   build_product_summary([cable('KL-A', 2, 10), cable('KL-B', 3, 20)])}
        self.assertEqual(summary['KL-A']['cuts_text'], '(2 x 10)')
        self.assertEqual(summary['KL-B']['cuts_text'], '(3 x 20)')

    def test_the_cuts_account_for_the_quoted_total(self):
        summary = build_product_summary([cable('KL-A', 6, 35), cable('KL-A', 1, 90)])[0]
        total = sum(cut['number_of_lengths'] * cut['length_each'] for cut in summary['cuts'])
        self.assertEqual(total, summary['quoted_total_length'])


class TheCharacterCap(unittest.TestCase):
    """§3.1. The 200 is our number, not 4js's, and was never verified -- but whatever it
    turns out to be, an over-long list has to end with an explicit `+N more`. A cutting
    instruction truncated mid-list is worse than no list."""

    def test_a_list_that_fits_is_untouched(self):
        cuts = [{'text': '(6 x 35)'}] * 3
        self.assertEqual(format_summary_cuts(cuts, 200), '(6 x 35), (6 x 35), (6 x 35)')

    def test_no_cap_means_no_cap(self):
        self.assertEqual(len(format_summary_cuts([{'text': '(6 x 35)'}] * 22).split(', ')), 22)

    def test_22_cuts_overrun_the_200_we_truncate_at_today(self):
        # The case Sales was looking at: KL-PAXN/5C16OR on 22 lines. Today's `[:200]`
        # would drop the tail silently and the comment would still look complete in 4js.
        self.assertGreater(len(format_summary_cuts([{'text': '(6 x 35)'}] * 22)), 200)

    def test_an_over_long_list_says_how_many_it_dropped(self):
        capped = format_summary_cuts([{'text': '(6 x 35)'}] * 22, 200)
        self.assertLessEqual(len(capped), 200)
        self.assertTrue(capped.endswith('+3 more'), capped)
        self.assertEqual(capped.count('(6 x 35)'), 19)

    def test_it_never_ends_mid_cut(self):
        for limit in range(10, 220):
            with self.subTest(limit=limit):
                capped = format_summary_cuts([{'text': '(6 x 35)'}] * 22, limit)
                self.assertEqual(capped.count('('), capped.count(')'))

    def test_a_budget_too_small_for_even_one_cut_reports_the_count_alone(self):
        self.assertEqual(format_summary_cuts([{'text': '(6 x 35)'}] * 22, 5), '+22 more')

    def test_an_empty_list_is_an_empty_string(self):
        self.assertEqual(format_summary_cuts([]), '')
        self.assertEqual(format_summary_cuts(None, 200), '')


class NotYetShippedTo4js(unittest.TestCase):
    """§3.1: "Do not ship the cuts into 4js before (1) is answered." (1) needs someone
    who can exercise 4js; local builds must not call it. Until then the summary line
    comment is unchanged, and this test is what says that is deliberate."""

    @unittest.skip('The 4js push is plan Phase 5; the platform handoff must keep the 200-character comment cap.')
    def test_the_pushed_line_comment_is_still_capped_at_200(self):
        source = (ROOT / 'matcher/pipeline.py').read_text(encoding='utf-8')
        self.assertIn('row.get("line_note", "")[:200]', source)

    def test_the_summary_entry_carries_the_cuts_regardless(self):
        # The table can show them today; only the 4js comment is gated.
        summary = build_product_summary([cable('KL-A', 6, 35)])[0]
        self.assertEqual(summary['cuts_text'], '(6 x 35)')


class TheProofQuote(unittest.TestCase):
    def test_every_summary_line_accounts_for_its_total(self):
        draft = json.loads((ROOT / 'rfq_parser/fixtures/go_live/draft-proof-redacted.json').read_text())['draft']
        summary = build_product_summary(draft['rows'])
        self.assertTrue(summary)
        for entry in summary:
            with self.subTest(code=entry['product_code']):
                self.assertEqual(len(entry['cuts']), entry['line_count'])
                total = sum(cut['number_of_lengths'] * cut['length_each']
                            for cut in entry['cuts'])
                self.assertAlmostEqual(total, float(entry['quoted_total_length']), places=3)

    def test_the_two_line_product_lists_both_cuts(self):
        draft = json.loads((ROOT / 'rfq_parser/fixtures/go_live/draft-proof-redacted.json').read_text())['draft']
        summary = {entry['product_code']: entry for entry in build_product_summary(draft['rows'])}
        self.assertEqual(summary['LP-XXXN/1C70EA']['cuts_text'], '(1 x 40), (1 x 745)')


if __name__ == '__main__':
    unittest.main()
