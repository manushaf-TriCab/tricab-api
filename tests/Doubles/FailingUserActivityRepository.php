<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Doubles;

use DateTimeImmutable;
use RuntimeException;
use TriCab\Core\Analytics\Application\DTO\ActivityWrite;
use TriCab\Core\Analytics\Contract\UserActivityRepository;

/** An activity store whose tables are missing: every call fails the way Azure SQL does. */
final class FailingUserActivityRepository implements UserActivityRepository
{
    public function record(ActivityWrite $write): void
    {
        throw new RuntimeException('Invalid object name analytics.ActivitySessions');
    }

    public function liveTotals(DateTimeImmutable $activeSinceUtc): array
    {
        throw new RuntimeException('Invalid object name analytics.ActivitySessions');
    }

    public function liveByModule(DateTimeImmutable $activeSinceUtc): array
    {
        throw new RuntimeException('Invalid object name analytics.ActivitySessions');
    }

    public function dailyActiveUsers(string $fromDate, string $toDate): array
    {
        throw new RuntimeException('Invalid object name analytics.DailyUserActivity');
    }

    public function hourlyActiveUsers(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        throw new RuntimeException('Invalid object name analytics.HourlyUserActivity');
    }

    public function moduleActivity(string $fromDate, string $toDate): array
    {
        throw new RuntimeException('Invalid object name analytics.DailyModuleActivity');
    }

    public function firstActivityDate(): ?string
    {
        throw new RuntimeException('Invalid object name analytics.DailyUserActivity');
    }

    public function purge(DateTimeImmutable $sessionsBeforeUtc, DateTimeImmutable $hourlyBeforeUtc, string $dailyBeforeDate, bool $dryRun): array
    {
        throw new RuntimeException('Invalid object name analytics.ActivitySessions');
    }
}
