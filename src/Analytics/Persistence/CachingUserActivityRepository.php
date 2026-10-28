<?php

declare(strict_types=1);

namespace TriCab\Api\Analytics\Persistence;

use DateTimeImmutable;
use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Core\Analytics\Application\DTO\ActivityWrite;
use TriCab\Core\Analytics\Contract\UserActivityRepository;
use TriCab\Core\Analytics\Domain\ReportingCalendar;
use TriCab\Core\Shared\Time\Clock;

/**
 * Caches aggregates for completed Melbourne days, which no longer change. Anything touching today, live
 * figures and writes always go to the store.
 */
final class CachingUserActivityRepository implements UserActivityRepository
{
    private const TTL_SECONDS = 900;
    private const PREFIX = 'analytics:v1:';

    public function __construct(
        private readonly UserActivityRepository $inner,
        private readonly ApplicationCache $cache,
        private readonly Clock $clock,
    ) {
    }

    public function record(ActivityWrite $write): void
    {
        $this->inner->record($write);
    }

    public function liveTotals(DateTimeImmutable $activeSinceUtc): array
    {
        return $this->inner->liveTotals($activeSinceUtc);
    }

    public function liveByModule(DateTimeImmutable $activeSinceUtc): array
    {
        return $this->inner->liveByModule($activeSinceUtc);
    }

    public function dailyActiveUsers(string $fromDate, string $toDate): array
    {
        if (!$this->isCompleted($toDate)) {
            return $this->inner->dailyActiveUsers($fromDate, $toDate);
        }

        $key = self::PREFIX . 'daily:' . $fromDate . ':' . $toDate;
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            /** @var list<array{bucket: string, web: int, mobile: int, total: int, staff: int, customer: int}> $cached */
            return $cached;
        }

        $rows = $this->inner->dailyActiveUsers($fromDate, $toDate);
        $this->cache->set($key, $rows, self::TTL_SECONDS);

        return $rows;
    }

    public function hourlyActiveUsers(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        return $this->inner->hourlyActiveUsers($fromUtc, $toUtc);
    }

    public function moduleActivity(string $fromDate, string $toDate): array
    {
        if (!$this->isCompleted($toDate)) {
            return $this->inner->moduleActivity($fromDate, $toDate);
        }

        $key = self::PREFIX . 'module:' . $fromDate . ':' . $toDate;
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            /** @var list<array{module: string, users: int, webUsers: int, mobileUsers: int, events: int}> $cached */
            return $cached;
        }

        $rows = $this->inner->moduleActivity($fromDate, $toDate);
        $this->cache->set($key, $rows, self::TTL_SECONDS);

        return $rows;
    }

    public function firstActivityDate(): ?string
    {
        $key = self::PREFIX . 'first-date';
        $cached = $this->cache->get($key);
        if (is_string($cached)) {
            return $cached;
        }

        // Only a found date is cached: until the first write it must be re-read, or "no data" would stick.
        $first = $this->inner->firstActivityDate();
        if ($first !== null) {
            $this->cache->set($key, $first, self::TTL_SECONDS);
        }

        return $first;
    }

    public function purge(
        DateTimeImmutable $sessionsBeforeUtc,
        DateTimeImmutable $hourlyBeforeUtc,
        string $dailyBeforeDate,
        bool $dryRun,
    ): array {
        $removed = $this->inner->purge($sessionsBeforeUtc, $hourlyBeforeUtc, $dailyBeforeDate, $dryRun);
        if (!$dryRun) {
            $this->cache->deleteByPrefix(self::PREFIX);
        }

        return $removed;
    }

    private function isCompleted(string $date): bool
    {
        return $date < ReportingCalendar::localDate($this->clock->now());
    }
}
