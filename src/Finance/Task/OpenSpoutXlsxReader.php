<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use Closure;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;
use TriCab\Core\Finance\Task\Contract\SpreadsheetReader;
use TriCab\Core\Finance\Task\Domain\SpreadsheetSheet;
use TriCab\Core\Finance\Task\Domain\TaskImportFailed;
use TriCab\Core\Finance\Task\Domain\TaskUnavailable;

/**
 * `.xlsx` and `.xlsm` through `openspout/openspout` (question Q2). It needs PHP's `zip` extension (and
 * `xmlreader`); without it this says so plainly, with the way round it (a CSV), instead of a fatal error.
 *
 * Every row is kept, empty ones included, so a row's position is the row number in Excel. Dates arrive as
 * `DateTimeImmutable` where the cell is formatted as a date, and as a number (a serial) where it is not,
 * which the importer reads either way. The bytes are written to a temporary file for the reader, which
 * works on a path, and the file is removed afterwards.
 */
final class OpenSpoutXlsxReader implements SpreadsheetReader
{
    /** @var Closure(string): bool */
    private readonly Closure $extensionLoaded;

    /**
     * @param (callable(string): bool)|null $extensionLoaded `extension_loaded` unless a test pretends an extension is missing
     */
    public function __construct(?callable $extensionLoaded = null)
    {
        $this->extensionLoaded = Closure::fromCallable($extensionLoaded ?? 'extension_loaded');
    }

    public function read(string $format, string $content): array
    {
        if ($format !== self::FORMAT_XLSX) {
            throw new TaskImportFailed('This reader reads Excel workbooks only.');
        }
        if (!($this->extensionLoaded)('zip') || !($this->extensionLoaded)('xmlreader')) {
            throw new TaskUnavailable(
                'This server cannot read Excel files yet (the PHP zip and xmlreader extensions are not both loaded). Save the sheet as CSV and import that instead.',
            );
        }
        $path = tempnam(sys_get_temp_dir(), 'tt-import-');
        if ($path === false) {
            throw new TaskImportFailed('The file could not be read');
        }
        try {
            if (file_put_contents($path, $content) === false) {
                throw new TaskImportFailed('The file could not be read');
            }
            $options = new Options();
            $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
            $reader = new Reader($options);
            $sheets = [];
            // A damaged archive makes the zip extension warn as well as fail; the failure is what we report.
            set_error_handler(static fn (): bool => true, E_WARNING | E_NOTICE | E_DEPRECATED);
            try {
                $reader->open($path);
                foreach ($reader->getSheetIterator() as $sheet) {
                    $rows = [];
                    foreach ($sheet->getRowIterator() as $row) {
                        $rows[] = $row->toArray();
                    }
                    $sheets[] = new SpreadsheetSheet($sheet->getName(), $rows);
                }
            } catch (Throwable) {
                // Any unreadable workbook is the same answer; the reason is the file's, never shown.
                throw new TaskImportFailed('That file could not be opened as an Excel workbook');
            } finally {
                try {
                    $reader->close();
                } catch (Throwable) {
                    // never opened
                }
                restore_error_handler();
            }

            return $sheets;
        } finally {
            @unlink($path);
        }
    }
}
