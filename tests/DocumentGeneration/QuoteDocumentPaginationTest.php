<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\DocumentGeneration;

use PHPUnit\Framework\TestCase;
use TriCab\Api\DocumentGeneration\Rendering\DompdfPdfRenderer;
use TriCab\Api\DocumentGeneration\Rendering\QuoteDocumentPagination;
use TriCab\Api\DocumentGeneration\Rendering\QuoteItemPageAllocator;
use TriCab\Core\DocumentGeneration\Application\DTO\LegacyDocumentData;
use TriCab\Core\DocumentGeneration\Legacy\LegacyRecord;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStream;

final class QuoteDocumentPaginationTest extends TestCase
{
    public function test_quote_html_reserves_footer_and_atomic_items(): void
    {
        $html = $this->renderQuoteHtml($this->manyItems(12));

        self::assertStringContainsString('quote-page-footer', $html);
        self::assertStringContainsString('quote-item', $html);
        self::assertStringContainsString('page-break-inside: avoid', $html);
        self::assertStringContainsString('quote-closing', $html);
        self::assertStringContainsString('quote-final-block', $html);
        self::assertStringContainsString('margin-bottom: 20mm', $html);
        self::assertStringContainsString('display: table-header-group', $html);
        self::assertStringContainsString('Itm</th>', $html);
        self::assertStringContainsString('TOTAL', $html);
        self::assertStringContainsString('conductor-comparison', $html);
        self::assertStringContainsString('quote-item-table-close', $html);
        self::assertStringContainsString('quote-company-footer', $html);
        self::assertStringNotContainsString('Page 1 of 1', $html);
        self::assertStringContainsString('TriCab (Australia) Pty. Ltd.', $html);
    }

    public function test_multi_page_html_uses_closed_table_segments(): void
    {
        $html = $this->renderQuoteHtml($this->manyItems(80));
        self::assertStringContainsString('quote-items-continued', $html);
        self::assertGreaterThanOrEqual(2, substr_count($html, '<table class="items'));
        self::assertStringContainsString('quote-final-block', $html);
    }

    public function test_allocator_orphan_rule_keeps_item_with_closing(): void
    {
        $items = [];
        for ($i = 1; $i <= 60; ++$i) {
            $items[] = [
                'record' => new LegacyRecord('S009', 19, [
                    '1', (string) $i, 'G07-AAXX/001', '', '1', '1 pack', '3.10', '3.10',
                    'Black', '', '', '', '3.10', '', '', '', '', '', '',
                ], $i),
                'comments' => [],
            ];
        }

        $chunks = QuoteItemPageAllocator::allocate($items, true);
        self::assertGreaterThanOrEqual(2, count($chunks));
        $last = $chunks[count($chunks) - 1];
        self::assertNotSame([], $last, 'Final page chunk must contain at least one item');
    }

    public function test_multi_page_pdf_shows_continuation_only_on_non_final_pages(): void
    {
        $renderer = new DompdfPdfRenderer(
            dirname(__DIR__, 2) . '/resources/document-generation/templates',
            dirname(__DIR__, 2) . '/resources/document-generation/assets',
        );
        $pdf = $renderer->render($this->quoteStream($this->manyItems(80)));
        self::assertStringStartsWith('%PDF', $pdf);

        $text = $this->extractTextFromPdf($pdf);
        self::assertMatchesRegularExpression('/Page \d+ of (\d+)/', $text, $text);
        preg_match_all('/Page (\d+) of (\d+)/', $text, $matches);
        $pageTotals = array_map('intval', $matches[2]);
        self::assertArrayHasKey(0, $pageTotals, 'Expected at least one Page N of M label');
        $pageCount = $pageTotals[0];
        foreach ($pageTotals as $total) {
            if ($total > $pageCount) {
                $pageCount = $total;
            }
        }
        self::assertGreaterThanOrEqual(2, $pageCount, 'Expected a multi-page quotation');

        $continuationCount = substr_count($text, 'Continues to next page ->');
        self::assertGreaterThanOrEqual(1, $continuationCount);
        self::assertSame(
            $pageCount - 1,
            $continuationCount,
            sprintf(
                'Continuation text must appear on every non-final page only (pages=%d continues=%d)',
                $pageCount,
                $continuationCount,
            ),
        );
        self::assertStringContainsString('TOTAL', $text);
        self::assertStringContainsString('TriCab (Australia) Pty. Ltd.', $text);
    }

    public function test_single_page_pdf_has_no_continuation(): void
    {
        $renderer = new DompdfPdfRenderer(
            dirname(__DIR__, 2) . '/resources/document-generation/templates',
            dirname(__DIR__, 2) . '/resources/document-generation/assets',
        );
        $pdf = $renderer->render($this->quoteStream($this->manyItems(2)));
        $text = $this->extractTextFromPdf($pdf);
        self::assertStringContainsString('Page 1 of 1', $text);
        self::assertStringNotContainsString('Continues to next page', $text);
    }

    public function test_bottom_margin_constant(): void
    {
        self::assertSame(20.0, QuoteDocumentPagination::BOTTOM_MARGIN_MM);
        self::assertStringContainsString('20mm', QuoteDocumentPagination::quotePaginationCss());
        self::assertStringContainsString('margin-bottom: 20mm', $this->renderQuoteHtml($this->manyItems(2)));
        self::assertStringContainsString('border-collapse: separate', QuoteDocumentPagination::quotePaginationCss());
        self::assertStringContainsString('quote-item-table-close', QuoteDocumentPagination::quotePaginationCss());
        self::assertStringContainsString('quote-item-table-close', $this->renderQuoteHtml($this->manyItems(2)));
    }

    /**
     * @param list<array{no:string,desc:string,code:string,qty:string,unit:string,price:string,total:string}> $items
     */
    private function renderQuoteHtml(array $items): string
    {
        $renderer = new DompdfPdfRenderer(
            dirname(__DIR__, 2) . '/resources/document-generation/templates',
            dirname(__DIR__, 2) . '/resources/document-generation/assets',
        );
        $method = new \ReflectionMethod(DompdfPdfRenderer::class, 'renderTemplate');
        $method->setAccessible(true);

        /** @var string $html */
        $html = $method->invoke(
            $renderer,
            'quote.php',
            new LegacyDocumentData($this->quoteStream($items)),
        );

        return $html;
    }

    /**
     * @return list<array{no:string,desc:string,code:string,qty:string,unit:string,price:string,total:string}>
     */
    private function manyItems(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; ++$i) {
            $items[] = [
                'no' => (string) $i,
                'desc' => 'Black',
                'code' => sprintf('G07-AAXX/%03d', $i),
                'qty' => '1',
                'unit' => '1 pack',
                'price' => '3.10',
                'total' => '3.10',
            ];
        }

        return $items;
    }

    /**
     * @param list<array{no:string,desc:string,code:string,qty:string,unit:string,price:string,total:string}> $items
     */
    private function quoteStream(array $items): LegacyStream
    {
        $docId = '100146136';
        $records = [
            new LegacyRecord('S006', 21, [
                $docId, $docId, '22/09/26', '', '', 'ACN 006 373 824', '', '', '', 'ACME01', '', 'AUD', '1,227.43',
                '', '30 days', '', '24/09/26', 'N', '', '', '',
            ], 1),
            new LegacyRecord('S026', 7, [$docId, '14037', '21010', 'TONN', 'LME', '1657', '13'], 2),
            new LegacyRecord('S011', 4, [$docId, 'Alex', 'alex@tricab.com', ''], 3),
            new LegacyRecord('S007', 8, [
                $docId, 'TriCab (Australia) Pty. Ltd.', '31 Liberty Road', 'Huntingwood', 'NSW 2148 AU',
                'T  +61 2 8662 0098', 'sydney@tricab.com', '',
            ], 4),
            new LegacyRecord('S012', 6, [$docId, 'Jordan', '', '', '', ''], 5),
            new LegacyRecord('S008', 7, [$docId, 'Acme Pty', '1 Street', 'SYDNEY NSW AU', '', '', ''], 6),
        ];
        $line = 7;
        foreach ($items as $item) {
            $records[] = new LegacyRecord('S009', 19, [
                $docId, $item['no'], $item['code'], '', $item['qty'], $item['unit'], $item['price'], $item['total'],
                $item['desc'], '', '', '', $item['total'], '', '', '', '', '', '',
            ], $line);
            ++$line;
        }

        return new LegacyStream('.RET./l/ocr/ps//tcqu.ocr', $records, [], [
            ['sizeMm2' => '50', 'tricab' => '658', 'class5' => '378', 'class6' => '662'],
            ['sizeMm2' => '630', 'tricab' => '8784', 'class5' => '2155', 'class6' => null],
        ]);
    }

    private function extractTextFromPdf(string $pdf): string
    {
        $chunks = [];
        if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $pdf, $matches) === false) {
            return '';
        }
        foreach ($matches[1] as $stream) {
            $chunks[] = $this->decodePdfStreamText($stream);
        }

        return implode("\n", $chunks);
    }

    private function decodePdfStreamText(string $stream): string
    {
        $decoded = @gzuncompress($stream);
        if ($decoded === false) {
            $decoded = @gzinflate($stream);
        }
        if ($decoded === false) {
            $decoded = $stream;
        }
        if (!preg_match('/[\x20-\x7E]{8,}/', $decoded)) {
            return '';
        }
        $texts = [];
        if (preg_match_all('/\((?:\\\\.|[^\\\\)])*\)/', $decoded, $matches) === false) {
            return '';
        }
        foreach ($matches[0] as $token) {
            $raw = substr($token, 1, -1);
            $raw = str_replace(['\\(', '\\)', '\\\\'], ['(', ')', '\\'], $raw);
            if (trim($raw) !== '' && preg_match('/[\x20-\x7E]/', $raw)) {
                $texts[] = $raw;
            }
        }

        return implode(' ', $texts);
    }
}
