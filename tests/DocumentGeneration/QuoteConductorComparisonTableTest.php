<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\DocumentGeneration;

use PHPUnit\Framework\TestCase;
use TriCab\Api\DocumentGeneration\Rendering\ConductorComparisonMatrix;
use TriCab\Api\DocumentGeneration\Rendering\DompdfPdfRenderer;
use TriCab\Core\DocumentGeneration\Legacy\LegacyRecord;
use TriCab\Core\DocumentGeneration\Legacy\LegacyStream;

final class QuoteConductorComparisonTableTest extends TestCase
{
    public function test_matrix_pivots_rows_and_blanks_null_class6(): void
    {
        $matrix = ConductorComparisonMatrix::fromRows([
            ['sizeMm2' => '50', 'tricab' => '658', 'class5' => '378', 'class6' => '662'],
            ['sizeMm2' => '400', 'tricab' => '5368', 'class5' => '1958', 'class6' => null],
            ['sizeMm2' => '10', 'tricab' => '99', 'class5' => '1', 'class6' => '1'], // below legacy range
            ['sizeMm2' => '95', 'tricab' => '1216 x 0.25', 'class5' => '465 x 0.3', 'class6' => ''],
        ]);

        self::assertSame(['50', '95', '400'], $matrix->sizes);
        self::assertSame(['658', '1216', '5368'], $matrix->tricab);
        self::assertSame(['378', '465', '1958'], $matrix->class5);
        self::assertSame(['662', null, null], $matrix->class6);
    }

    public function test_quote_html_uses_full_width_table_not_stranding_image(): void
    {
        $html = $this->renderQuoteHtml([
            ['sizeMm2' => '50', 'tricab' => '658', 'class5' => '378', 'class6' => '662'],
            ['sizeMm2' => '70', 'tricab' => '969', 'class5' => '342', 'class6' => '927'],
            ['sizeMm2' => '630', 'tricab' => '8784', 'class5' => '2155', 'class6' => null],
        ]);

        self::assertStringNotContainsString('conductor-stranding', $html);
        self::assertStringNotContainsString('alt="Conductor stranding table"', $html);
        self::assertStringNotContainsString('<img class="stranding"', $html);
        self::assertStringContainsString('class="conductor-comparison"', $html);
        self::assertStringContainsString('Conductor Comparison - TriCab versus Class 5 &amp; 6', $html);
        self::assertStringContainsString('(approximate no.of wires)', $html);
        self::assertStringContainsString('Size mm²', $html);
        self::assertStringContainsString('>50<', $html);
        self::assertStringContainsString('>70<', $html);
        self::assertStringContainsString('>630<', $html);
        self::assertStringContainsString('>TriCab<', $html);
        self::assertStringContainsString('>Class5<', $html);
        self::assertStringContainsString('>Class6<', $html);
        self::assertStringContainsString('>658<', $html);
        self::assertStringContainsString('>969<', $html);
        self::assertStringContainsString('>8784<', $html);
        self::assertMatchesRegularExpression(
            '/Class6<\/td>\s*<td>662<\/td>\s*<td>927<\/td>\s*<td><\/td>/s',
            $html,
        );
        self::assertStringContainsString('.conductor-comparison {', $html);
        self::assertStringContainsString('width: 100%;', $html);
        self::assertStringContainsString('QUOTATION No', $html);
        self::assertStringContainsString('Payment Terms:', $html);
        self::assertStringContainsString('TOTAL', $html);
    }

    public function test_quote_pdf_renders_without_stranding_image_asset(): void
    {
        $renderer = new DompdfPdfRenderer(
            dirname(__DIR__, 2) . '/resources/document-generation/templates',
            dirname(__DIR__, 2) . '/resources/document-generation/assets',
        );
        $pdf = $renderer->render($this->quoteStream([
            ['sizeMm2' => '50', 'tricab' => '658', 'class5' => '378', 'class6' => '662'],
            ['sizeMm2' => '630', 'tricab' => '8784', 'class5' => '2155', 'class6' => null],
        ]));

        self::assertStringStartsWith('%PDF', $pdf);
        self::assertGreaterThan(8_000, strlen($pdf));
        // JPEG asset for the old stranding image must not be embedded.
        $strandingAsset = file_get_contents(
            dirname(__DIR__, 2) . '/resources/document-generation/assets/conductor-stranding.jpg',
        );
        self::assertNotFalse($strandingAsset);
        self::assertStringNotContainsString($strandingAsset, $pdf);
        self::assertStringNotContainsString('conductor-stranding', $pdf);
    }

    /**
     * @param list<array{sizeMm2: string, tricab: string|null, class5: string|null, class6: string|null}> $conductorRows
     */
    private function renderQuoteHtml(array $conductorRows): string
    {
        $renderer = new DompdfPdfRenderer(
            dirname(__DIR__, 2) . '/resources/document-generation/templates',
            dirname(__DIR__, 2) . '/resources/document-generation/assets',
        );
        $method = new \ReflectionMethod(DompdfPdfRenderer::class, 'renderTemplate');
        $method->setAccessible(true);

        /** @var string $html */
        $html = $method->invoke($renderer, 'quote.php', new \TriCab\Core\DocumentGeneration\Application\DTO\LegacyDocumentData(
            $this->quoteStream($conductorRows),
        ));

        return $html;
    }

    /**
     * @param list<array{sizeMm2: string, tricab: string|null, class5: string|null, class6: string|null}> $conductorRows
     */
    private function quoteStream(array $conductorRows): LegacyStream
    {
        $docId = '100146116';

        return new LegacyStream('.RET./l/ocr/ps//tcqu.ocr', [
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
            new LegacyRecord('S009', 19, [
                $docId, '1', 'KU-PAZN/4C16BK', 'Black', '40', 'm', '12.50', '500.00', 'Black',
                '', '', '', '500.00', '', '', '', '', '', '40m',
            ], 7),
        ], [], $conductorRows);
    }
}
