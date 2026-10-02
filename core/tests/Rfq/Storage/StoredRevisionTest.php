<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Storage\StoredRevision;
use TriCab\Core\Tests\Rfq\Fixtures\FixtureRfqParserClient;

/**
 * Plan 3.7: a stored source row read as `rfq.line.v1`, from the real parser's
 * answer to a paste (`parser-v1-paste.json`).
 */
final class StoredRevisionTest extends TestCase
{
    public function test_the_parsers_rows_read_as_lines(): void
    {
        $rows = FixtureRfqParserClient::lines();
        foreach ($rows as $i => $row) {
            $rows[$i]['line_uid'] = 'uid-' . $i;
        }
        $revision = StoredRevision::project(1, new DateTimeImmutable('2026-09-28T01:00:00Z'), ['author' => 'MANUSHAF'], $rows);
        $lines = $revision->lines;

        self::assertSame(1, $revision->revision);
        self::assertNull($revision->basedOnRevision);
        self::assertSame('created', $revision->cause);
        self::assertSame('MANUSHAF', $revision->header->authorCode);
        self::assertCount(3, $lines);

        // A cable by the metre.
        self::assertSame('uid-0', $lines[0]->lineUid);
        self::assertSame('4C 16mm2 XLPE/PVC 120m', $lines[0]->requestedText);
        self::assertSame('XL-PAXN/4C16OR', $lines[0]->productCode);
        self::assertSame('120', $lines[0]->quantity);
        self::assertSame('m', $lines[0]->unit);
        self::assertNull($lines[0]->cuts);
        self::assertSame('DB-1', $lines[0]->comment, 'the circuit label folded into it');
        self::assertSame('deterministic', $lines[0]->inputType->toString());
        self::assertSame('unreviewed', $lines[0]->approval->toString());
        self::assertSame('included', $lines[0]->disposition->toString());
        self::assertContains('colour', $lines[0]->inferredFields);

        // In cuts: 4 × 55 m survives as that, not only as 220 m (invariant 4).
        self::assertSame('220', $lines[1]->quantity);
        self::assertSame('55', $lines[1]->cutLengthMetres);
        self::assertSame(4, $lines[1]->cuts);
        self::assertSame('(4 x 55)', $lines[1]->comment);

        // Nothing matched: no product and no invented quantity.
        self::assertSame('unresolved', $lines[2]->matchStatus->toString());
        self::assertNull($lines[2]->productCode);
        self::assertNull($lines[2]->quantity);
        self::assertSame(['Core count was not found.', 'Conductor cross-section was not found.'], $lines[2]->warnings);
    }

    public function test_a_persons_decisions_read_as_theirs(): void
    {
        $row = FixtureRfqParserClient::lines()[0];
        $row['final_status'] = 'approved';
        $row['final_product_code'] = 'XL-PAXN/4C16BK';
        $line = StoredRevision::line($row);

        self::assertTrue($line->approval->isApproved());
        self::assertSame($row['status'], $line->matchStatus->toString(), 'approval is not a match status');
        self::assertSame('XL-PAXN/4C16BK', $line->productCode);

        $row['disposition'] = 'excluded';
        self::assertSame('Intentionally excluded from this quote', StoredRevision::line($row)->exclusionReason);
        $row['directive_excluded'] = true;
        $row['directive_exclusion_reason'] = 'by customer';
        self::assertSame('excluded by a directive: by customer', StoredRevision::line($row)->exclusionReason);
    }

    public function test_the_row_detail_reads_status_reason_note_and_normalised_text(): void
    {
        $row = FixtureRfqParserClient::lines()[0];
        $line = StoredRevision::line($row)->toArray();
        self::assertSame([$row['status'], '', ''], [$line['status'], $line['correctionReason'], $line['reviewerNote']], "unreviewed: the parser's status");

        $row['final_status'] = 'flagged';
        $row['correction_reason'] = 'wrong_series';
        $row['reviewer_note'] = 'check';
        $row['source'] = ['original_text' => '4C 16MM2', 'normalized_text' => '4c 16mm2'];
        $line = StoredRevision::line($row)->toArray();
        self::assertSame(['flagged', 'wrong_series', 'check', '4c 16mm2'], [$line['status'], $line['correctionReason'], $line['reviewerNote'], $line['normalizedText']]);
    }

    public function test_an_accessory_counts_in_units(): void
    {
        $line = StoredRevision::line([
            'line_uid' => 'a', 'raw_text' => '20 glands', 'status' => 'accessory', 'item_type' => 'accessory',
            'selected_product_code' => 'GL-20', 'length' => ['number_of_lengths' => 20, 'uom' => null],
        ]);
        self::assertSame('20', $line->quantity);
        self::assertSame('U', $line->unit);
    }

    public function test_a_later_revision_says_it_was_saved(): void
    {
        $revision = StoredRevision::project(3, new DateTimeImmutable('2026-09-28T01:00:00Z'), [], []);
        self::assertSame(2, $revision->basedOnRevision);
        self::assertSame('saved', $revision->cause);
    }

    public function test_the_grid_reads_cuts_hints_alternatives_and_the_trace(): void
    {
        $line = StoredRevision::line([
            'line_uid' => 'u',
            'raw_text' => 'HV Power 70mm2',
            'source' => ['original_text' => 'HV Power 70mm2', 'file_name' => 'email body', 'split_count' => 2, 'entry_index' => 2],
            'length' => ['number_of_lengths' => 26, 'length_each' => 52.885, 'total_length' => 1375.01],
            'table_quantity' => ['header' => 'table', 'value' => 1375, 'unit' => 'm'],
            'candidates' => [['product_code' => 'A'], ['product_code' => 'B'], ['product_code' => 'C']],
            'directive_excluded' => true,
            'directive_exclusion_reason' => 'supplied by others',
            'directive_notes' => ['Deliver to site'],
        ]);
        $entry = $line->entry?->toArray();

        self::assertNotNull($entry);
        self::assertSame('26', $entry['cuts']);
        self::assertSame('52.885', $entry['lengthEach']);
        self::assertSame(['header' => 'table', 'value' => '1375', 'unit' => 'm'], $entry['quantityHint']);
        self::assertSame(['A', 'B', 'C'], $entry['alternatives']);
        self::assertSame(['fileName' => 'email body', 'sheet' => null, 'rowNumber' => null, 'entryIndex' => 2, 'splitCount' => 2], $entry['trace']);
        self::assertSame('supplied by others', $entry['directiveExclusion']);
        self::assertSame(['Deliver to site'], $entry['directiveNotes']);
        self::assertFalse($entry['accessory']);
    }

    public function test_a_stale_multiplied_length_is_read_from_the_parsers_pair(): void
    {
        self::assertSame(['4', '55'], StoredRevision::lengthAsEntered(['number_of_lengths' => 4, 'length_each' => 220, 'length_each_m' => 55.0, 'total_length_m' => 220.0]));
        self::assertSame(['1', null], StoredRevision::lengthAsEntered([]));
    }

    public function test_one_candidate_is_no_alternatives(): void
    {
        self::assertSame([], StoredRevision::line(['line_uid' => 'u', 'candidates' => [['product_code' => 'A']]])->entry?->alternatives);
    }
}
