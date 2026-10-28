<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Contract;

use DateTimeImmutable;

/**
 * Last successful sign-in only: one timestamp per user, overwritten on each login.
 * No platform and no module. Counts are "users whose most recent login fell in this bucket".
 */
interface LastLoginAnalyticsRepository
{
    public function firstLoginDate(): ?string;

    /**
     * @return array{total: int, web: int|null, mobile: int|null, staff: int, customer: int}
     */
    public function liveTotals(DateTimeImmutable $sinceUtc): array;

    /**
     * @return list<array{bucket: string, web: int|null, mobile: int|null, total: int, staff: int, customer: int}>
     */
    public function dailyLastLogins(string $fromDate, string $toDate): array;

    /**
     * @return list<array{bucket: string, web: int|null, mobile: int|null, total: int, staff: int, customer: int}>
     */
    public function hourlyLastLogins(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array;
}
