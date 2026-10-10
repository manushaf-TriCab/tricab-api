<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Dates the register stores and reads, as `yyyy-MM-dd` text.
 *
 * {@see self::normalize()} is `extraction.py`'s `normalize_date`: day first for slashes, dashes and dots, written months
 * in English, anything unreadable dropped. The platform columns are `DATE`, so this is also the boundary parse.
 * {@see self::strict()} is the query-string parser: `yyyy-MM-dd` only (the source's `date.fromisoformat` also took
 * `20261001` and ISO week forms; the platform does not).
 */
final class ApDates
{
    private const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    /**
     * A `yyyy-MM-dd` string for a date a person or a model wrote, or null when it cannot be read.
     *
     * Accepted: `2026-10-01`, `2026-9-1`, `2026-10-01T...` and `2026-10-01 ...` (the rest is ignored); `01/10/2026`,
     * `1/10/26` (two digits are 2000 plus), `30.09.2026`, `30-09-2026` (day first); `3 Aug 2026`, `3 August 2026`,
     * `Aug 3 2026`, `August 3, 2026`, `3rd August 2026`, `3 Aug 26`. Impossible dates (`31/02/2026`), words, empty text
     * and anything that is not text give null.
     */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $raw = ApRules::strip($value);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:$|[T ])/', $raw, $m) === 1) {
            return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2}|\d{4})$/', $raw, $m) === 1) {
            $year = (int) $m[3];

            return self::make($year < 100 ? $year + 2000 : $year, (int) $m[2], (int) $m[1]);
        }

        $cleaned = preg_replace('/(\d)(st|nd|rd|th)\b/i', '$1', str_replace(',', ' ', $raw)) ?? $raw;
        $cleaned = trim(preg_replace('/\s+/u', ' ', $cleaned) ?? $cleaned);
        // %d %b %Y, %d %B %Y, %d %b %y, %d %B %y
        if (preg_match('/^(\d{1,2}) ([A-Za-z]+) (\d{4}|\d{2})$/', $cleaned, $m) === 1) {
            $month = self::MONTHS[strtolower($m[2])] ?? null;

            return $month === null ? null : self::make(self::year($m[3]), $month, (int) $m[1]);
        }
        // %b %d %Y, %B %d %Y
        if (preg_match('/^([A-Za-z]+) (\d{1,2}) (\d{4})$/', $cleaned, $m) === 1) {
            $month = self::MONTHS[strtolower($m[1])] ?? null;

            return $month === null ? null : self::make((int) $m[3], $month, (int) $m[2]);
        }

        return null;
    }

    /** `yyyy-MM-dd` that is a real calendar date, nothing else; null otherwise. */
    public static function strict(string $value): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1) {
            return null;
        }

        return self::make((int) $m[1], (int) $m[2], (int) $m[3]);
    }

    /** Midnight UTC of the day, moved by whole days (the period filter's widening). */
    public static function utcMidnight(string $day, int $plusDays): DateTimeImmutable
    {
        return (new DateTimeImmutable($day . ' 00:00:00', new DateTimeZone('UTC')))->modify(sprintf('%+d days', $plusDays));
    }

    /** Python's `strptime("%y")`: 00 to 68 are 2000 to 2068, 69 to 99 are 1969 to 1999; four digits are the year. */
    private static function year(string $digits): int
    {
        $year = (int) $digits;
        if (strlen($digits) === 4) {
            return $year;
        }

        return $year < 69 ? 2000 + $year : 1900 + $year;
    }

    private static function make(int $year, int $month, int $day): ?string
    {
        if ($year < 1 || $year > 9999 || !checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
