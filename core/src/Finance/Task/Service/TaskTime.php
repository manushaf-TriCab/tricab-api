<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Shared\Time\Clock;

/**
 * "Today" and "overdue" are decided in one time zone, `Australia/Melbourne` unless
 * `FINANCE_TASK_TIMEZONE` says otherwise (decision D17), never against the browser's
 * clock or UTC: at 23:30 UTC it is already tomorrow in Melbourne. Overdue is derived,
 * never stored.
 */
final class TaskTime
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

    /** The date at an instant in the zone, as `yyyy-MM-dd`: the daily summary asks about the instant the runner is running at. */
    public function todayAt(DateTimeImmutable $at): string
    {
        return $at->setTimezone($this->zone)->format('Y-m-d');
    }

    /** Now, in UTC, which is how every timestamp is stored. */
    public function now(): DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
    }
}
