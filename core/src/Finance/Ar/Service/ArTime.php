<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Service;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Shared\Time\Clock;

/**
 * "Today", "overdue" and the register's local days are decided in one time zone,
 * `Australia/Melbourne` unless `FINANCE_AR_TIMEZONE` says otherwise (decisions D17, Q8), never
 * against the browser's clock or UTC. Overdue is derived, never stored.
 */
final class ArTime
{
    private readonly DateTimeZone $zone;

    public function __construct(private readonly Clock $clock, string $timezone = 'Australia/Melbourne')
    {
        $this->zone = new DateTimeZone($timezone);
    }

    public function zoneName(): string
    {
        return $this->zone->getName();
    }

    /** Today in the zone as `yyyy-MM-dd`. */
    public function today(): string
    {
        return $this->clock->now()->setTimezone($this->zone)->format('Y-m-d');
    }

    /** Now, in UTC, which is how every timestamp is stored. */
    public function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The UTC instants that bracket the local calendar days `[$from, $to]` (the source's
     * `_local_day_bounds`): the start of `$from`, and the START OF THE DAY AFTER `$to`, to be used
     * with `<`. Either may be null. Daylight saving is the zone's.
     *
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    public function dayBounds(?string $from, ?string $to): array
    {
        $utc = new DateTimeZone('UTC');
        $start = $from === null ? null : (new DateTimeImmutable($from . ' 00:00:00', $this->zone))->setTimezone($utc);
        $end = $to === null ? null : (new DateTimeImmutable($to . ' 00:00:00', $this->zone))->add(new DateInterval('P1D'))->setTimezone($utc);

        return [$start, $end];
    }
}
