<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Export;

/**
 * The text of an RFQ export (parity plan E7.1, parent plan 5.5).
 *
 * The file is what the source's `/api/quote-csv` writes: UTF-8 with a byte
 * order mark (Excel needs it to read accented characters), CRLF line ends, and
 * a field quoted only when it holds a comma, a quote or a line break.
 *
 * ## Formula injection
 *
 * A customer's text reaches these cells (descriptions, codes, serials), and a
 * rep opens the file in a spreadsheet. A cell that begins `=`, `+`, `-`, `@`,
 * a tab, a carriage return or a line feed can be run as a formula, so it gets
 * a leading apostrophe, which a spreadsheet shows as nothing and treats as
 * text. The one exception is a plain number such as `-5` or `12.5`, which
 * cannot be a payload and which a rep expects to stay a number. The source
 * does no escaping; this is the platform's addition and the only difference
 * in the cells.
 */
final class SpreadsheetCsv
{
    public const BOM = "\xEF\xBB\xBF";

    private const TRIGGERS = ['=', '+', '-', '@', "\t", "\r", "\n"];

    /**
     * @param list<string>       $columns
     * @param list<list<string>> $rows
     */
    public static function encode(array $columns, array $rows): string
    {
        $out = self::BOM . self::line($columns);
        foreach ($rows as $row) {
            $out .= self::line($row);
        }

        return $out;
    }

    /** One cell made safe to open in a spreadsheet. */
    public static function neutralise(string $cell): string
    {
        if ($cell === '' || !in_array($cell[0], self::TRIGGERS, true)) {
            return $cell;
        }
        if (preg_match('/^-?\d+(\.\d+)?\z/', $cell) === 1) {
            return $cell;
        }

        return "'" . $cell;
    }

    /** @param list<string> $cells */
    private static function line(array $cells): string
    {
        return implode(',', array_map(self::field(...), $cells)) . "\r\n";
    }

    private static function field(string $cell): string
    {
        $cell = self::neutralise($cell);
        if (strpbrk($cell, ",\"\r\n") === false) {
            return $cell;
        }

        return '"' . str_replace('"', '""', $cell) . '"';
    }
}
