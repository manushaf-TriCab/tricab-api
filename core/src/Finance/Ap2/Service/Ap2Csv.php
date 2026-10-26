<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

/**
 * The prototype's `csv()`: a UTF-8 BOM, CRLF lines, every cell quoted with quotes doubled, and the formula-injection
 * guard: a TEXT cell that starts (after any spaces) with `=`, `+`, `-` or `@`, or with a tab or carriage return, gets a
 * leading apostrophe so a spreadsheet shows it as text instead of running it. A NUMBER is never altered, so a credit
 * of -5.00 stays -5.00. (The tab and carriage return are an addition to the prototype's list, from OWASP's.)
 */
final class Ap2Csv
{
    /**
     * @param list<string>                      $headers
     * @param list<list<string|int|float|null>> $rows
     */
    public static function build(array $headers, array $rows): string
    {
        $lines = [];
        foreach ([$headers, ...$rows] as $row) {
            $lines[] = implode(',', array_map(self::cell(...), $row));
        }

        return "\xEF\xBB\xBF" . implode("\r\n", $lines);
    }

    public static function cell(string|int|float|null $value): string
    {
        if (is_float($value)) {
            $text = number_format($value, 2, '.', '');
        } elseif (is_int($value)) {
            $text = (string) $value;
        } else {
            $text = $value ?? '';
            if (preg_match('/^(\s*[=+@-]|[\t\r])/', $text) === 1) {
                $text = "'" . $text;
            }
        }

        return '"' . str_replace('"', '""', $text) . '"';
    }
}
