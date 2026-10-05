<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Parser;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Application\DTO\RfqExportView;
use TriCab\Core\Rfq\Parser\CorrelationId;
use TriCab\Core\Rfq\Parser\ParserContract;
use TriCab\Core\Rfq\Parser\QuoteExport;
use TriCab\Core\Rfq\Parser\RfqParserFailure;

/**
 * `POST /v1/export` as the Python `quote_export` answers it (the payload is
 * its real output for one priced line), read strictly, and what the API keeps
 * of it (parity plan E7.1).
 */
final class QuoteExportTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function payload(CorrelationId $id): array
    {
        $table = ['columns' => ['Serial Number', 'Product Description', 'Product Code', 'Cuts', 'Cut Length', 'Total Length', 'Price', 'Total Price']];

        return [
            'contract' => ParserContract::VERSION,
            'correlationId' => $id->toString(),
            'summary' => [['product_code' => 'TPS3C25', 'line_count' => 1, 'requested_total_length' => 100, 'quoted_total_length' => 100, 'line_indexes' => [0], 'cuts' => [['number_of_lengths' => 2, 'length_each' => 50, 'text' => '(2 x 50)']], 'cost_status' => 'STOCK', 'unit_price' => 3.0, 'moq' => null, 'moq_applied' => false, 'prepack_applied' => false, 'cuts_text' => '(2 x 50)']],
            'quoteLines' => $table + ['rows' => [['1', 'Site', 'TPS3C25', '2', '50', '100', '5.5', '550']]],
            'summaryLines' => $table + ['rows' => [['1', 'Summary quote - TPS3C25', 'TPS3C25', '1', '100', '100', '5.5', '550']]],
        ];
    }

    public function test_the_parsers_answer_is_read_and_the_view_keeps_no_cost(): void
    {
        $id = CorrelationId::generate();
        $view = RfqExportView::from(QuoteExport::fromPayload(self::payload($id), $id), [['line_uid' => 'uid-1']], null, true);

        self::assertSame(['products' => [[
            'productCode' => 'TPS3C25',
            'lineCount' => 1,
            'requestedTotalLength' => 100,
            'quotedTotalLength' => 100,
            'costStatus' => 'STOCK',
            'moq' => null,
            'moqApplied' => false,
            'prepackApplied' => false,
            'cutsText' => '(2 x 50)',
            'lineUids' => ['uid-1'],
        ]], 'exportableProducts' => 1, 'pricingError' => null], $view->summaryToArray());
        self::assertSame(
            "\xEF\xBB\xBFSerial Number,Product Description,Product Code,Cuts,Cut Length,Total Length,Price,Total Price\r\n1,Site,TPS3C25,2,50,100,5.5,550\r\n",
            $view->csv('quote-lines'),
        );
    }

    public function test_a_malformed_answer_is_a_parser_failure_not_a_file(): void
    {
        $id = CorrelationId::generate();
        $bad = [
            ['summary' => 'x'],
            ['summary' => [['line_indexes' => []]]],
            ['quoteLines' => ['columns' => ['A'], 'rows' => [['1', '2']]]],
            ['summaryLines' => ['columns' => ['A'], 'rows' => [[1]]]],
            ['quoteLines' => null],
        ];
        foreach ($bad as $change) {
            try {
                QuoteExport::fromPayload([...self::payload($id), ...$change], $id);
                self::fail('accepted ' . json_encode($change));
            } catch (RfqParserFailure $failure) {
                self::assertSame(RfqParserFailure::INTERNAL_ERROR, $failure->failureCode);
            }
        }
    }
}
