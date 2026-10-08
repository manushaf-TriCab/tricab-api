<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use TriCab\Core\Finance\Task\Contract\SpreadsheetReader;
use TriCab\Core\Finance\Task\Domain\SpreadsheetSheet;
use TriCab\Core\Finance\Task\Domain\TaskImportFailed;

/**
 * A CSV as one sheet of text cells: UTF-8 with or without a BOM, falling back to Windows-1252 (what Excel's
 * "CSV (Comma delimited)" writes), comma-separated, double quotes with `""` for a quote, newlines allowed
 * inside quotes. The source used Python's `csv.reader` with its default dialect; there is no escape
 * character here either. A blank line is an empty row, so a row's position stays its line number.
 */
final class CsvSpreadsheetReader implements SpreadsheetReader
{
    public function read(string $format, string $content): array
    {
        if ($format !== self::FORMAT_CSV) {
            throw new TaskImportFailed('This reader reads CSV only.');
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new TaskImportFailed('The file could not be read');
        }
        try {
            fwrite($handle, $content);
            rewind($handle);
            $rows = [];
            while (($record = fgetcsv($handle, null, ',', '"', '')) !== false) {
                if ($record === [null]) {
                    $rows[] = [];   // a blank line
                    continue;
                }
                $rows[] = array_map(static fn (?string $cell): string => $cell ?? '', $record);
            }
        } finally {
            fclose($handle);
        }

        return [new SpreadsheetSheet(null, $rows)];
    }
}
