<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Application\Service;

use DateTimeZone;
use TriCab\Core\Analytics\Contract\UserActivityRepository;
use TriCab\Core\Analytics\Domain\ReportingCalendar;
use TriCab\Core\Shared\Time\Clock;

/**
 * Retention for activity data. Sessions and hourly buckets are only needed for live figures and today's hourly
 * chart; daily aggregates keep the 90-day chart and a year of history.
 */
final class PurgeUserActivityService
{
    public const SESSION_RETENTION_DAYS = 30;
    public const HOURLY_RETENTION_DAYS = 14;
    public const DAILY_RETENTION_DAYS = 400;

    public function __construct(
        private readonly UserActivityRepository $activity,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{dryRun: bool, sessionsBeforeUtc: string, hourlyBeforeUtc: string, dailyBeforeDate: string, removed: array{sessions: int, hourly: int, daily: int, moduleDaily: int}}
     */
    public function purge(bool $dryRun): array
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $sessionsBefore = $now->modify(sprintf('-%d days', self::SESSION_RETENTION_DAYS));
        $hourlyBefore = ReportingCalendar::hourStartUtc($now->modify(sprintf('-%d days', self::HOURLY_RETENTION_DAYS)));
        $dailyBefore = ReportingCalendar::lastDates($now, self::DAILY_RETENTION_DAYS)[0];

        return [
            'dryRun' => $dryRun,
            'sessionsBeforeUtc' => $sessionsBefore->format(DATE_ATOM),
            'hourlyBeforeUtc' => $hourlyBefore->format(DATE_ATOM),
            'dailyBeforeDate' => $dailyBefore,
            'removed' => $this->activity->purge($sessionsBefore, $hourlyBefore, $dailyBefore, $dryRun),
        ];
    }
}
