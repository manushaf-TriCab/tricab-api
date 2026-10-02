<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Storage;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Exception\InvalidRfqValue;
use TriCab\Core\Rfq\Storage\DraftLineEdits;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;

/**
 * Plan 3.7: a review save applies a person's decisions to the stored rows on
 * the server, with the source editor's rules, and touches nothing else.
 */
final class DraftLineEditsTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function stored(): array
    {
        $rows = FixtureRfqParserClient::lines();
        foreach ($rows as $i => $row) {
            $rows[$i]['line_uid'] = 'uid-' . $i;
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{lineUid: string, approved: bool, comment: string}
     */
    private static function keep(array $row, bool $approved = false, ?string $comment = null): array
    {
        $uid = $row['line_uid'] ?? null;
        $note = $row['line_note'] ?? '';
        self::assertIsString($uid);
        self::assertIsString($note);

        return ['lineUid' => $uid, 'approved' => $approved, 'comment' => $comment ?? $note];
    }

    public function test_keeping_every_line_unchanged_changes_nothing(): void
    {
        $stored = self::stored();
        self::assertSame($stored, DraftLineEdits::apply($stored, array_map(static fn (array $r): array => self::keep($r), $stored)));
    }

    public function test_approval_is_the_source_editors(): void
    {
        $stored = self::stored();
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[0], true), self::keep($stored[1]), self::keep($stored[2])]);

        self::assertSame('approved', $rows[0]['final_status']);
        self::assertSame($stored[0]['status'], $rows[0]['status'], "the parser's status is kept");
        $inferred = $stored[0]['inferred_fields'];
        $warnings = $stored[0]['warnings'];
        self::assertIsArray($inferred);
        self::assertIsArray($warnings);
        $expected = [];
        foreach ([...$inferred, ...$warnings] as $value) {
            if (!in_array($value, $expected, true)) {
                $expected[] = $value;
            }
        }
        self::assertSame($expected, $rows[0]['confirmed_fields']);
        self::assertSame($stored[1], $rows[1]);

        // Withdrawn: back to the parser's status.
        $again = DraftLineEdits::apply($rows, [self::keep($rows[0], false), self::keep($rows[1]), self::keep($rows[2])]);
        self::assertArrayNotHasKey('final_status', $again[0]);
    }

    public function test_the_row_details_status_reason_and_note_are_saved(): void
    {
        $stored = self::stored();
        $edit = self::keep($stored[1]) + ['status' => 'flagged', 'correctionReason' => 'wrong_series', 'reviewerNote' => '  ask the customer  '];
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[0]), $edit, self::keep($stored[2])]);

        self::assertSame('flagged', $rows[1]['final_status']);
        self::assertSame($stored[1]['status'], $rows[1]['status'], "the parser's status is kept");
        self::assertSame('wrong_series', $rows[1]['correction_reason']);
        self::assertSame('ask the customer', $rows[1]['reviewer_note']);

        // The status the line already shows changes nothing.
        $same = DraftLineEdits::apply($stored, [self::keep($stored[0]) + ['status' => (string) $stored[0]['status']], self::keep($stored[1]), self::keep($stored[2])]);
        self::assertSame($stored[0], $same[0]);
    }

    public function test_choosing_approved_as_the_status_is_approve(): void
    {
        $stored = self::stored();
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[0]) + ['status' => 'approved'], self::keep($stored[1]), self::keep($stored[2])]);

        self::assertSame('approved', $rows[0]['final_status']);
        self::assertArrayHasKey('confirmed_fields', $rows[0]);

        $stored[0]['final_product_code'] = '';
        $this->expectException(InvalidRfqValue::class);
        DraftLineEdits::apply($stored, [self::keep($stored[0]) + ['status' => 'approved'], self::keep($stored[1]), self::keep($stored[2])]);
    }

    public function test_a_line_with_no_product_cannot_be_approved(): void
    {
        $stored = self::stored();
        $this->expectException(InvalidRfqValue::class);
        DraftLineEdits::apply($stored, [self::keep($stored[2], true)]);
    }

    public function test_an_excluded_line_cannot_be_approved(): void
    {
        $stored = self::stored();
        $stored[0]['disposition'] = 'excluded';
        $this->expectException(InvalidRfqValue::class);
        DraftLineEdits::apply($stored, [self::keep($stored[0], true)]);
    }

    public function test_a_comment_is_the_line_note(): void
    {
        $stored = self::stored();
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[0], false, '  Deliver to site B  ')]);
        self::assertSame('Deliver to site B', $rows[0]['line_note']);
    }

    public function test_a_line_left_out_is_deleted_and_the_order_is_the_one_sent(): void
    {
        $stored = self::stored();
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[2]), self::keep($stored[0])]);
        self::assertSame(['uid-2', 'uid-0'], array_column($rows, 'line_uid'));
    }

    public function test_a_product_code_is_upper_cased_resolves_the_line_and_waits_for_pricing(): void
    {
        $stored = self::stored();
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[0]) + ['productCode' => ' xl-paxn/4c25or ']], 'newzealand');

        self::assertSame('XL-PAXN/4C25OR', $rows[0]['final_product_code']);
        self::assertSame('matched', $rows[0]['final_status']);
        self::assertSame(['status' => 'pending', 'source' => 'tricab_cost_api', 'region' => 'newzealand'], $rows[0]['pricing']);
        self::assertSame($stored[0]['selected_product_code'], $rows[0]['selected_product_code'], "the parser's pick is kept");
    }

    public function test_the_same_code_sent_back_changes_nothing(): void
    {
        $stored = self::stored();
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[0]) + ['productCode' => 'XL-PAXN/4C16OR', 'cuts' => '1', 'lengthEach' => '120']]);
        self::assertSame($stored[0], $rows[0]);
    }

    public function test_a_cleared_code_stays_cleared(): void
    {
        $stored = self::stored();
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[0]) + ['productCode' => '']]);

        self::assertSame('', $rows[0]['final_product_code']);
        self::assertSame('', DraftLineEdits::productCode($rows[0]), 'readiness reads it as no product');
    }

    public function test_a_quantity_edit_is_cuts_times_length_with_the_cut_note_rewritten(): void
    {
        $stored = self::stored();
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[1]) + ['cuts' => '3', 'lengthEach' => '40.5']]);
        $length = $rows[0]['length'];
        self::assertIsArray($length);

        self::assertSame(3, $length['number_of_lengths']);
        self::assertSame(40.5, $length['length_each']);
        self::assertSame(121.5, $length['total_length']);
        self::assertTrue($rows[0]['length_locked']);
        self::assertFalse($rows[0]['length_defaulted']);
        self::assertSame('(3 x 40.5)', $rows[0]['line_note'], 'the old "(4 x 55)" is replaced');
        $pricing = $rows[0]['pricing'] ?? null;
        self::assertIsArray($pricing);
        self::assertSame('pending', $pricing['status'] ?? null);
    }

    public function test_one_cut_drops_the_cut_note_and_keeps_the_label(): void
    {
        $stored = self::stored();
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[1], false, 'DB-4 (4 x 55)') + ['cuts' => '1', 'lengthEach' => '220']]);
        self::assertSame('DB-4', $rows[0]['line_note']);
    }

    public function test_a_quantity_edit_clears_moq_and_prepack_adjustments(): void
    {
        $stored = self::stored();
        $stored[0]['warnings'] = ['Prepack cable quoted as whole spools: 120m -> 1 x 200m.', 'Colour not stated.'];
        $stored[0]['make_moq'] = ['source' => 'prepack_size'];
        $stored[0]['line_note'] = 'DB-1 - Defaulted to prepack length 200m';
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[0]) + ['lengthEach' => '150']]);

        self::assertSame(['Colour not stated.'], $rows[0]['warnings']);
        self::assertArrayNotHasKey('make_moq', $rows[0]);
        self::assertSame('DB-1', $rows[0]['line_note']);
    }

    public function test_accessory_units_are_whole_packs(): void
    {
        $stored = self::stored();
        $stored[0]['line_kind'] = 'accessory';
        $rows = DraftLineEdits::apply($stored, [self::keep($stored[0]) + ['cuts' => '2.2']]);
        $length = $rows[0]['length'];
        self::assertIsArray($length);

        self::assertSame(3, $length['number_of_lengths']);
        self::assertArrayNotHasKey('length_locked', $rows[0]);
    }

    public function test_a_new_line_is_the_sources_blank_row_in_its_place(): void
    {
        $stored = self::stored();
        $uid = str_repeat('a', 32);
        $rows = DraftLineEdits::apply($stored, [
            self::keep($stored[0]),
            ['lineUid' => $uid, 'approved' => null, 'comment' => 'Glands', 'productCode' => 'cg20', 'cuts' => '4', 'lengthEach' => '', 'isNew' => true],
            ['lineUid' => str_repeat('b', 32), 'approved' => null, 'comment' => '', 'isNew' => true],
            self::keep($stored[1]),
        ]);

        self::assertSame(['uid-0', $uid, 'uid-1'], array_column($rows, 'line_uid'), 'the blank one is not saved');
        self::assertSame('CG20', $rows[1]['final_product_code']);
        self::assertTrue($rows[1]['manual_entry']);
        self::assertSame(['original_text' => '', 'input_type' => 'manual_entry'], $rows[1]['source']);
        self::assertSame('Glands', $rows[1]['line_note']);
    }

    public function test_a_new_line_needs_a_fresh_id(): void
    {
        $stored = self::stored();
        $this->expectException(InvalidRfqValue::class);
        DraftLineEdits::apply($stored, [['lineUid' => 'short', 'approved' => null, 'comment' => 'x', 'isNew' => true]]);
    }

    public function test_a_line_that_is_not_on_the_rfq_is_refused(): void
    {
        $this->expectException(InvalidRfqValue::class);
        DraftLineEdits::apply(self::stored(), [['lineUid' => 'somebody-elses', 'approved' => false, 'comment' => '']]);
    }

    public function test_a_line_named_twice_is_refused(): void
    {
        $stored = self::stored();
        $this->expectException(InvalidRfqValue::class);
        DraftLineEdits::apply($stored, [self::keep($stored[0]), self::keep($stored[0])]);
    }
}
