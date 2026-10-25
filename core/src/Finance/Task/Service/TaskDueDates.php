<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Turns the words people use for a deadline into `yyyy-MM-dd`, from a given "today" (Melbourne's, see TaskTime).
 * The model is told today's date but is not trusted with the arithmetic: the API resolves the phrase the person
 * wrote, and only falls back to a date the model gave when the phrase is not one it understands.
 *
 * Understood: today, tomorrow, a weekday ("Friday", "by Friday": that day, this week or the next if it has
 * passed), "next Friday" (that day in the following Monday to Sunday week), end of the week (Friday), end of the
 * month / next month, end of the quarter, end of the financial year (30 June), "in 3 days / 2 weeks / 1 month",
 * the end of a named month ("end of January"), and dates written out ("30 June", "June 30", "15/11", "15/11/2026", "2026-11-15"; day before month, as in
 * Australia; a date with no year is its next occurrence). Anything else is null: no guess.
 */
final class TaskDueDates
{
    private const WEEKDAYS = ['monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 7];
    private const MONTHS = [
        'jan' => 1, 'january' => 1, 'feb' => 2, 'february' => 2, 'mar' => 3, 'march' => 3, 'apr' => 4, 'april' => 4,
        'may' => 5, 'jun' => 6, 'june' => 6, 'jul' => 7, 'july' => 7, 'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'september' => 9, 'oct' => 10, 'october' => 10, 'nov' => 11, 'november' => 11,
        'dec' => 12, 'december' => 12,
    ];

    private readonly DateTimeImmutable $today;

    public function __construct(string $today)
    {
        $this->today = new DateTimeImmutable($today . ' 00:00:00', new DateTimeZone('UTC'));
    }

    public function today(): string
    {
        return $this->today->format('Y-m-d');
    }

    public function resolve(string $phrase): ?string
    {
        $text = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $phrase) ?? ''));
        $text = trim(preg_replace('/^(by|before|due|on|until|till|no later than|nlt)\s+(the\s+)?/u', '', $text) ?? $text, " .,;");
        $text = preg_replace('/^(close of business|cob|eod|end of day)\s+/u', '', $text) ?? $text;
        if ($text === '') {
            return null;
        }

        return $this->relative($text) ?? $this->written($text);
    }

    private function relative(string $text): ?string
    {
        if (in_array($text, ['today', 'eod', 'end of day', 'cob', 'close of business', 'tonight'], true)) {
            return $this->fmt($this->today);
        }
        if ($text === 'tomorrow') {
            return $this->fmt($this->today->modify('+1 day'));
        }
        if (preg_match('/^(?:(next|this)\s+)?(monday|tuesday|wednesday|thursday|friday|saturday|sunday)$/', $text, $m) === 1) {
            return $this->weekday(self::WEEKDAYS[$m[2]], $m[1] === 'next');
        }
        if (in_array($text, ['end of week', 'end of the week', 'eow', 'this week', 'end of this week'], true)) {
            return $this->weekday(5, false);
        }
        if (in_array($text, ['end of next week', 'next week'], true)) {
            return $this->weekday(5, true);
        }
        if (in_array($text, ['end of month', 'end of the month', 'eom', 'end of this month', 'month end', 'month-end'], true)) {
            return $this->fmt($this->today->modify('last day of this month'));
        }
        if (in_array($text, ['end of next month', 'next month'], true)) {
            return $this->fmt($this->today->modify('last day of next month'));
        }
        if (in_array($text, ['end of quarter', 'end of the quarter', 'eoq', 'end of this quarter', 'quarter end', 'quarter-end'], true)) {
            $month = (int) $this->today->format('n');
            $quarterEnd = (int) (ceil($month / 3) * 3);

            return $this->fmt($this->today->setDate((int) $this->today->format('Y'), $quarterEnd, 1)->modify('last day of this month'));
        }
        if (in_array($text, ['end of financial year', 'end of the financial year', 'end of fy', 'eofy', 'end of the fy', 'end of tax year'], true)) {
            return $this->nextOccurrence(6, 30);
        }
        if (preg_match('/^in (\d{1,3}|a|an|one|two|three|four|five|six) (day|week|month)s?$/', $text, $m) === 1) {
            $n = match ($m[1]) { 'a', 'an', 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, default => (int) $m[1] };
            if ($n < 1) {
                return null;
            }

            return $this->fmt($this->today->modify('+' . $n . ' ' . $m[2] . ($n === 1 ? '' : 's')));
        }

        return null;
    }

    /** A weekday this week or the next (today counts), or with `$following` the one in the next Monday-to-Sunday week. */
    private function weekday(int $target, bool $following): string
    {
        $current = (int) $this->today->format('N');
        if ($following) {
            $days = (8 - $current) + ($target - 1);
        } else {
            $days = ($target - $current + 7) % 7;
        }

        return $this->fmt($this->today->modify('+' . $days . ' days'));
    }

    private function written(string $text): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) === 1) {
            return $this->real((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})(?:[\/.\-](\d{2}|\d{4}))?$/', $text, $m) === 1) {
            return $this->dated((int) $m[1], (int) $m[2], isset($m[3]) ? $this->year($m[3]) : null);
        }
        // end of January, end of Oct: the last day of that month, this year or the next if it is past
        if (preg_match('/^(?:the )?end of ([a-z]+)$/', $text, $m) === 1 && isset(self::MONTHS[$m[1]])) {
            $month = self::MONTHS[$m[1]];
            $year = (int) $this->today->format('Y');
            $last = $this->real($year, $month, (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t'));

            return $last !== null && $last >= $this->today() ? $last : $this->real($year + 1, $month, (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $year + 1, $month)))->format('t'));
        }
        // 30 June, 30th of June 2026
        if (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?(?: of)? ([a-z]+)(?:,? (\d{4}))?$/', $text, $m) === 1 && isset(self::MONTHS[$m[2]])) {
            return $this->dated((int) $m[1], self::MONTHS[$m[2]], isset($m[3]) ? (int) $m[3] : null);
        }
        // June 30, June 30th 2026
        if (preg_match('/^([a-z]+) (\d{1,2})(?:st|nd|rd|th)?(?:,? (\d{4}))?$/', $text, $m) === 1 && isset(self::MONTHS[$m[1]])) {
            return $this->dated((int) $m[2], self::MONTHS[$m[1]], isset($m[3]) ? (int) $m[3] : null);
        }

        return null;
    }

    private function year(string $year): int
    {
        return strlen($year) === 2 ? 2000 + (int) $year : (int) $year;
    }

    private function dated(int $day, int $month, ?int $year): ?string
    {
        return $year !== null ? $this->real($year, $month, $day) : $this->nextOccurrence($month, $day);
    }

    private function nextOccurrence(int $month, int $day): ?string
    {
        $year = (int) $this->today->format('Y');
        $thisYear = $this->real($year, $month, $day);
        if ($thisYear !== null && $thisYear >= $this->today()) {
            return $thisYear;
        }

        return $this->real($year + 1, $month, $day);
    }

    private function real(int $year, int $month, int $day): ?string
    {
        if ($year < 2000 || $year > 2100 || !checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function fmt(DateTimeImmutable $date): string
    {
        return $date->format('Y-m-d');
    }
}
