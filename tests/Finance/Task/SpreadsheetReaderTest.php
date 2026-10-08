<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Task;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\TestCase;
use TriCab\Api\Finance\Task\CsvSpreadsheetReader;
use TriCab\Api\Finance\Task\FormatSpreadsheetReader;
use TriCab\Api\Finance\Task\OpenSpoutXlsxReader;
use TriCab\Core\Finance\Task\Contract\SpreadsheetReader;
use TriCab\Core\Finance\Task\Domain\TaskImportFailed;
use TriCab\Core\Finance\Task\Domain\TaskUnavailable;

/**
 * The two file readers (T2.8). CSV always runs. Excel needs PHP's `zip` extension, which the machine's own
 * `php.ini` leaves off, so those tests skip without it (and run under the Phase 2 test ini); the "zip is
 * not loaded" failure is tested by pretending it is not.
 */
final class SpreadsheetReaderTest extends TestCase
{
    private CsvSpreadsheetReader $csv;

    protected function setUp(): void
    {
        $this->csv = new CsvSpreadsheetReader();
    }

    /** @return list<list<mixed>> */
    private function csvRows(string $bytes): array
    {
        $sheets = $this->csv->read(SpreadsheetReader::FORMAT_CSV, $bytes);
        self::assertCount(1, $sheets);
        self::assertNull($sheets[0]->title);

        return $sheets[0]->rows;
    }

    private function requireZip(): void
    {
        if (!extension_loaded('zip') || !extension_loaded('xmlreader')) {
            self::markTestSkipped('The zip extension is not loaded (enable extension=zip, as Phase 2 asks of the local php.ini).');
        }
    }

    /**
     * An .xlsx built here with the same library, two sheets, an empty row between data and a date cell.
     *
     * @param list<array{0: string, 1: list<list<mixed>>}> $sheets
     */
    private function workbook(array $sheets): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tt-test-');
        self::assertIsString($path);
        try {
            $writer = new Writer();
            $writer->openToFile($path);
            foreach ($sheets as $i => [$title, $rows]) {
                $sheet = $i === 0 ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
                $sheet->setName($title);
                foreach ($rows as $row) {
                    $cells = [];
                    foreach ($row as $value) {
                        if ($value instanceof DateTimeInterface) {
                            $cells[] = Cell::fromValue($value, (new Style())->setFormat('yyyy-mm-dd'));
                        } else {
                            self::assertTrue($value === null || is_scalar($value));
                            $cells[] = Cell::fromValue($value);
                        }
                    }
                    $writer->addRow(new Row($cells));
                }
            }
            $writer->close();
            $bytes = file_get_contents($path);
            self::assertIsString($bytes);

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    // ==== CSV ==================================================================================

    public function test_a_csv_is_one_sheet_of_text_cells_with_crlf_and_a_trailing_newline(): void
    {
        self::assertSame(
            [['Entity', 'Task Name'], ['Example Trading', 'Bank rec'], ['', 'only second']],
            $this->csvRows("Entity,Task Name\r\nExample Trading,Bank rec\r\n,only second\r\n"),
        );
    }

    public function test_a_utf8_bom_is_dropped_and_utf8_text_is_kept(): void
    {
        self::assertSame([['Société', 'Clôture — ✓']], $this->csvRows("\xEF\xBB\xBFSociété,Clôture — ✓\n"));
    }

    public function test_windows_1252_is_the_fallback_for_excels_csv(): void
    {
        // "Café, naïve – “quoted”" as Excel's "CSV (Comma delimited)" writes it.
        $bytes = "Caf\xE9,na\xEFve \x96 \x93quoted\x94\r\n";

        self::assertSame([['Café', 'naïve – “quoted”']], $this->csvRows($bytes));
    }

    public function test_quotes_commas_doubled_quotes_and_newlines_inside_quotes_are_one_cell(): void
    {
        self::assertSame(
            [['a', "line one\nline two", 'says "hi", ok', 'b']],
            $this->csvRows("a,\"line one\nline two\",\"says \"\"hi\"\", ok\",b\n"),
        );
    }

    public function test_a_backslash_is_not_an_escape_character(): void
    {
        // Python's csv.reader has no escape character by default, and neither does this reader.
        self::assertSame([['C:\\temp\\', 'x'], ['say "q"', 'y']], $this->csvRows("C:\\temp\\,x\n\"say \"\"q\"\"\",y\n"));
    }

    public function test_a_blank_line_is_an_empty_row_so_row_numbers_stay_the_files(): void
    {
        $rows = $this->csvRows("h1,h2\n\nx,y\n");

        self::assertSame([['h1', 'h2'], [], ['x', 'y']], $rows);
    }

    public function test_an_empty_file_has_no_rows_and_the_csv_reader_refuses_another_format(): void
    {
        self::assertSame([], $this->csvRows(''));
        $this->expectException(TaskImportFailed::class);
        $this->csv->read(SpreadsheetReader::FORMAT_XLSX, 'x');
    }

    public function test_the_format_reader_hands_each_file_to_its_own_reader(): void
    {
        $reader = new FormatSpreadsheetReader($this->csv, new OpenSpoutXlsxReader(static fn (): bool => false));

        self::assertSame([['a']], $reader->read(SpreadsheetReader::FORMAT_CSV, "a\n")[0]->rows);
        try {
            $reader->read(SpreadsheetReader::FORMAT_XLSX, 'x');
            self::fail('Expected the Excel reader to refuse.');
        } catch (TaskUnavailable) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(TaskImportFailed::class);
        $reader->read('ods', 'x');
    }

    // ==== Excel ================================================================================

    public function test_without_the_zip_extension_the_reader_says_so_plainly_instead_of_a_fatal_error(): void
    {
        foreach (['zip', 'xmlreader'] as $missing) {
            $reader = new OpenSpoutXlsxReader(static fn (string $ext): bool => $ext !== $missing);
            try {
                $reader->read(SpreadsheetReader::FORMAT_XLSX, 'PK-anything');
                self::fail('Expected a clear failure.');
            } catch (TaskUnavailable $e) {
                self::assertStringContainsString('cannot read Excel files yet', $e->getMessage());
                self::assertStringContainsString('CSV', $e->getMessage());
                self::assertSame(503, $e->httpStatus());
            }
        }
    }

    public function test_a_workbook_is_read_sheet_by_sheet_keeping_empty_rows_and_dates(): void
    {
        $this->requireZip();
        $bytes = $this->workbook([
            ['Project import template', [['Project name', 'Status'], ['Month End', 'Active']]],
            ['Task import template', [
                ['Task Name', 'Due Date', 'Progress %', 'Mark as Urgent'],
                ['Bank rec', new DateTimeImmutable('2026-10-09', new DateTimeZone('UTC')), 40, true],
                [],
                ['Accruals', '9 Oct 2026', 12.5, false],
            ]],
        ]);

        $sheets = (new OpenSpoutXlsxReader())->read(SpreadsheetReader::FORMAT_XLSX, $bytes);

        self::assertSame(['Project import template', 'Task import template'], array_map(static fn ($s): ?string => $s->title, $sheets));
        self::assertSame([['Project name', 'Status'], ['Month End', 'Active']], $sheets[0]->rows);
        $tasks = $sheets[1]->rows;
        self::assertCount(4, $tasks, 'the empty row is kept, so a row\'s position is its Excel row number');
        self::assertSame('Bank rec', $tasks[1][0]);
        self::assertInstanceOf(DateTimeInterface::class, $tasks[1][1]);
        self::assertSame('2026-10-09', $tasks[1][1]->format('Y-m-d'));
        self::assertEquals(40, $tasks[1][2]);
        self::assertTrue($tasks[1][3]);
        self::assertSame('Accruals', $tasks[3][0]);
        self::assertSame('9 Oct 2026', $tasks[3][1]);
        self::assertEquals(12.5, $tasks[3][2]);
    }

    public function test_bytes_that_are_not_a_workbook_are_the_same_plain_refusal(): void
    {
        $this->requireZip();

        foreach (['not a workbook', '', "PK\x03\x04garbage"] as $bytes) {
            try {
                (new OpenSpoutXlsxReader())->read(SpreadsheetReader::FORMAT_XLSX, $bytes);
                self::fail('Expected a refusal.');
            } catch (TaskImportFailed $e) {
                self::assertSame('That file could not be opened as an Excel workbook', $e->getMessage());
            }
        }
    }

    public function test_the_temporary_file_is_removed_even_when_the_file_is_refused(): void
    {
        $this->requireZip();
        $before = glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-import-*') ?: [];

        try {
            (new OpenSpoutXlsxReader())->read(SpreadsheetReader::FORMAT_XLSX, 'not a workbook');
        } catch (TaskImportFailed) {
            // expected
        }

        self::assertSame(count($before), count(glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tt-import-*') ?: []));
    }
}
