<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Application\DTO\RfqReadinessView;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\QuoteReadiness;

/** Parity plan E3.5: the source's row states, named by the lines they are about. */
final class RfqReadinessViewTest extends TestCase
{
    public function test_rows_are_matched_by_position_not_by_the_sources_row_id(): void
    {
        $id = CorrelationId::fromHeaderOrGenerate('corr-ready-00001');
        $readiness = QuoteReadiness::fromPayload([
            'contract' => 'rfq-parser.v1',
            'correlationId' => 'corr-ready-00001',
            'readiness' => [
                'ready' => false,
                'blocking_issues' => [
                    ['issue' => 'missing_header', 'field' => 'contact', 'reason' => 'Missing required field: contact'],
                    ['row_number' => 2, 'issue' => 'missing_product', 'reason' => 'Choose a product code for this line.'],
                ],
                'row_states' => [
                    ['row_number' => 1, 'row_id' => 'r7', 'severity' => 'ready', 'codes' => [['code' => 'assumed_field', 'severity' => 'note', 'detail' => 'Assumed colour', 'display' => 'note']]],
                    ['row_number' => 2, 'row_id' => 'r9', 'severity' => 'fix_required', 'codes' => [['code' => 'product_unresolved', 'severity' => 'fix_required', 'detail' => 'Choose a product code for this line.', 'display' => 'fix_required']]],
                ],
            ],
        ], $id);

        $view = RfqReadinessView::from($readiness, [['line_uid' => 'aaa', '_id' => 'r7'], ['line_uid' => 'bbb', '_id' => 'r9']]);

        self::assertFalse($view->ready);
        self::assertSame([
            ['lineUid' => null, 'reason' => 'Missing required field: contact', 'field' => 'contact'],
            ['lineUid' => 'bbb', 'reason' => 'Choose a product code for this line.', 'field' => null],
        ], $view->blockingIssues);
        self::assertSame(['aaa', 'bbb'], array_column($view->lines, 'lineUid'));
        self::assertSame('fix_required', $view->lines[1]['severity']);
        self::assertSame([['code' => 'assumed_field', 'display' => 'note', 'detail' => 'Assumed colour']], $view->lines[0]['codes']);
    }
}
