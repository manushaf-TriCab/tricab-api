<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use TriCab\Core\Finance\Task\Contract\SpreadsheetReader;
use TriCab\Core\Finance\Task\Domain\TaskImportFailed;

/** Hands a file to the reader for its format: CSV or Excel. The container's `SpreadsheetReader`. */
final class FormatSpreadsheetReader implements SpreadsheetReader
{
    public function __construct(
        private readonly SpreadsheetReader $csv,
        private readonly SpreadsheetReader $xlsx,
    ) {
    }

    public function read(string $format, string $content): array
    {
        return match ($format) {
            self::FORMAT_CSV => $this->csv->read($format, $content),
            self::FORMAT_XLSX => $this->xlsx->read($format, $content),
            default => throw new TaskImportFailed('Upload the import template as .xlsx or .csv'),
        };
    }
}
