<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Contract;

use DateTimeImmutable;
use TriCab\Core\Analytics\Application\DTO\ActivityWrite;

/**
 * Activity persistence. Reads return aggregates computed by the store; no method returns a user identity.
 * Dates are Melbourne reporting dates (Y-m-d); instants are UTC.
 *
 * A session belongs to the user who opened it: a signal naming another user's session id changes nothing.
 */
interface UserActivityRepository
{
    public function record(ActivityWrite $write): void;

    /**
     * Unique users with an active session whose latest qualifying activity is at or after $activeSinceUtc.
     *
     * @return array{total: int, web: int, mobile: int, staff: int, customer: int}
     */
    public function liveTotals(DateTimeImmutable $activeSinceUtc): array;

    /**
     * Live users per module, each user counted once against the module of their most recent activity.
     *
     * @return array<string, int>
     */
    public function liveByModule(DateTimeImmutable $activeSinceUtc): array;

    /**
     * Rows only for dates that have activity.
     *
     * @return list<array{bucket: string, web: int, mobile: int, total: int, staff: int, customer: int}>
     */
    public function dailyActiveUsers(string $fromDate, string $toDate): array;

    /**
     * Rows only for hours that have activity; bucket is the UTC hour start as Y-m-d\TH:i:s.
     *
     * @return list<array{bucket: string, web: int, mobile: int, total: int, staff: int, customer: int}>
     */
    public function hourlyActiveUsers(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array;

    /**
     * Unique users and view events per module over the date range.
     *
     * @return list<array{module: string, users: int, webUsers: int, mobileUsers: int, events: int}>
     */
    public function moduleActivity(string $fromDate, string $toDate): array;

    /** The first Melbourne date with recorded activity, or null when nothing has been collected. */
    public function firstActivityDate(): ?string;

    /**
     * Counts what is (or, unless $dryRun, was) removed by the retention policy.
     *
     * @return array{sessions: int, hourly: int, daily: int, moduleDaily: int}
     */
    public function purge(
        DateTimeImmutable $sessionsBeforeUtc,
        DateTimeImmutable $hourlyBeforeUtc,
        string $dailyBeforeDate,
        bool $dryRun,
    ): array;
}
