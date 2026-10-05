<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Export\SpreadsheetCsv;

/** Parent plan 5.5: escape spreadsheet formula payloads in exports. */
final class SpreadsheetCsvTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cells(): iterable
    {
        yield 'equals' => ['=1+1', "'=1+1"];
        yield 'plus' => ['+1+1', "'+1+1"];
        yield 'minus formula' => ['-2+3', "'-2+3"];
        yield 'minus text' => ['-cmd|calc', "'-cmd|calc"];
        yield 'at' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tab' => ["\t=1", "'\t=1"];
        yield 'carriage return' => ["\r=1", "'\r=1"];
        yield 'line feed' => ["\n=1", "'\n=1"];
        yield 'dde' => ['=cmd|\' /C calc\'!A0', "'=cmd|' /C calc'!A0"];
        yield 'plus number is not a plain number' => ['+5', "'+5"];
        yield 'plain text' => ['4C 16mm2', '4C 16mm2'];
        yield 'equals inside' => ['a=b', 'a=b'];
        yield 'empty' => ['', ''];
        yield 'integer' => ['100', '100'];
        yield 'decimal' => ['12.5', '12.5'];
        yield 'negative number stays a number' => ['-5', '-5'];
        yield 'negative decimal' => ['-0.25', '-0.25'];
        yield 'number with trailing text' => ['-5x', "'-5x"];
        yield 'exponent' => ['1e-05', '1e-05'];
    }

    #[DataProvider('cells')]
    public function test_a_cell_that_could_run_as_a_formula_gets_a_leading_apostrophe(string $cell, string $expected): void
    {
        self::assertSame($expected, SpreadsheetCsv::neutralise($cell));
    }

    public function test_the_file_is_utf8_with_a_bom_crlf_and_quotes_only_where_needed(): void
    {
        $csv = SpreadsheetCsv::encode(['A', 'B'], [['x', 'Café, "big"'], ['line' . "\n" . 'two', '']]);

        self::assertSame("\xEF\xBB\xBFA,B\r\nx,\"Café, \"\"big\"\"\"\r\n\"line\ntwo\",\r\n", $csv);
    }

    public function test_a_neutralised_cell_is_still_quoted_when_it_needs_to_be(): void
    {
        $csv = SpreadsheetCsv::encode(['A'], [['=HYPERLINK("u","x")']]);

        self::assertSame("\xEF\xBB\xBFA\r\n\"'=HYPERLINK(\"\"u\"\",\"\"x\"\")\"\r\n", $csv);
    }

    public function test_a_payload_cannot_start_a_cell_after_the_encoding(): void
    {
        foreach (['=1', '+1', '-a', '@a', "\t=1", "\r=1", "\n=1"] as $payload) {
            $csv = substr(SpreadsheetCsv::encode(['A'], [[$payload]]), 3 + 3);
            $cell = ltrim($csv, '"');
            self::assertSame("'", $cell[0], json_encode($payload) . ' starts a cell unescaped');
        }
    }
}
