<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Melbourne reporting days and hours. Activity is stored against UTC instants; a reporting day is the
 * Melbourne calendar day. Melbourne's offset is always a whole number of hours (+10 or +11), so every UTC
 * hour is exactly one Melbourne hour, and a daylight-saving day simply has 23 or 25 hourly buckets.
 */
final class ReportingCalendar
{
    public const TIMEZONE = 'Australia/Melbourne';

    public static function zone(): DateTimeZone
    {
        return new DateTimeZone(self::TIMEZONE);
    }

    public static function localDate(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(self::zone())->format('Y-m-d');
    }

    public static function hourStartUtc(DateTimeImmutable $instant): DateTimeImmutable
    {
        $utc = $instant->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), 0);
    }

    /**
     * The last $days Melbourne dates ending today, oldest first.
     *
     * @return list<string>
     */
    public static function lastDates(DateTimeImmutable $now, int $days): array
    {
        $today = $now->setTimezone(self::zone())->setTime(0, 0);
        $dates = [];
        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $dates[] = $today->modify(sprintf('-%d days', $offset))->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * UTC hour starts from Melbourne midnight today up to and including the current hour.
     *
     * @return list<DateTimeImmutable>
     */
    public static function todayHoursUtc(DateTimeImmutable $now): array
    {
        $utc = new DateTimeZone('UTC');
        $hour = $now->setTimezone(self::zone())->setTime(0, 0)->setTimezone($utc);
        $current = self::hourStartUtc($now);
        $hours = [];
        while ($hour <= $current) {
            $hours[] = $hour;
            $hour = $hour->modify('+1 hour');
        }

        return $hours;
    }
}
