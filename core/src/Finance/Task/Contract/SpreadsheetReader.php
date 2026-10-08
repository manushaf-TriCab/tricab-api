<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Contract;

use TriCab\Core\Finance\Task\Domain\SpreadsheetSheet;
use TriCab\Core\Finance\Task\Domain\TaskImportFailed;
use TriCab\Core\Finance\Task\Domain\TaskUnavailable;

/**
 * The sheets of a file's bytes, as raw cells (question Q2: the platform reads the import in PHP).
 * Adapters: `openspout` for `.xlsx` and `.xlsm`, a CSV reader (UTF-8 with or without a BOM, falling back
 * to Windows-1252), and an in-memory twin for the importer's tests.
 */
interface SpreadsheetReader
{
    public const FORMAT_XLSX = 'xlsx';

    public const FORMAT_CSV = 'csv';

    /**
     * @param string $format `self::FORMAT_XLSX` or `self::FORMAT_CSV`; anything else is not a file this can read
     * @return list<SpreadsheetSheet> every sheet (a CSV is one); no rows are dropped
     * @throws TaskImportFailed when the bytes are not a file of that format
     * @throws TaskUnavailable when the server cannot read that format at all (no `zip` extension)
     */
    public function read(string $format, string $content): array;
}
