<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Analytics\Application\DTO\ActivityWrite;
use TriCab\Core\Analytics\Contract\UserActivityRepository;

/** Same semantics as the Azure SQL store, for development and tests. */
final class InMemoryUserActivityRepository implements UserActivityRepository
{
    /** @var array<string, array{userId: int, platform: string, isStaff: bool, module: string, state: string, lastActivity: DateTimeImmutable, lastSignal: DateTimeImmutable, order: int}> */
    private array $sessions = [];

    /** @var array<string, array{date: string, userId: int, platform: string, isStaff: bool}> */
    private array $daily = [];

    /** @var array<string, array{hour: DateTimeImmutable, userId: int, platform: string, isStaff: bool}> */
    private array $hourly = [];

    /** @var array<string, array{date: string, module: string, userId: int, platform: string, isStaff: bool, events: int}> */
    private array $moduleDaily = [];

    private int $order = 0;

    public function record(ActivityWrite $write): void
    {
        $platform = $write->platform->value;

        if ($write->writeSession) {
            $existing = $this->sessions[$write->sessionId] ?? null;
            if ($existing !== null && $existing['userId'] !== $write->userId) {
                return;
            }
            $this->sessions[$write->sessionId] = [
                'userId' => $write->userId,
                'platform' => $existing['platform'] ?? $platform,
                'isStaff' => $write->isStaff,
                'module' => $write->moduleCode,
                'state' => $write->sessionState(),
                'lastActivity' => $write->type->isQualifying() || $existing === null
                    ? $write->atUtc
                    : $existing['lastActivity'],
                'lastSignal' => $write->atUtc,
                'order' => ++$this->order,
            ];
        }

        if ($write->writeDaily) {
            $key = implode('|', [$write->localDate, $platform, $write->userId]);
            $this->daily[$key] ??= [
                'date' => $write->localDate,
                'userId' => $write->userId,
                'platform' => $platform,
                'isStaff' => $write->isStaff,
            ];
        }

        if ($write->writeHourly) {
            $key = implode('|', [$write->hourStartUtc->format('YmdH'), $platform, $write->userId]);
            $this->hourly[$key] ??= [
                'hour' => $write->hourStartUtc,
                'userId' => $write->userId,
                'platform' => $platform,
                'isStaff' => $write->isStaff,
            ];
        }

        if ($write->writeModuleDaily) {
            $key = implode('|', [$write->localDate, $write->moduleCode, $platform, $write->userId]);
            $this->moduleDaily[$key] ??= [
                'date' => $write->localDate,
                'module' => $write->moduleCode,
                'userId' => $write->userId,
                'platform' => $platform,
                'isStaff' => $write->isStaff,
                'events' => 0,
            ];
            $this->moduleDaily[$key]['events'] += $write->moduleViewIncrement();
        }
    }

    public function liveTotals(DateTimeImmutable $activeSinceUtc): array
    {
        return self::uniqueCounts($this->liveSessions($activeSinceUtc));
    }

    public function liveByModule(DateTimeImmutable $activeSinceUtc): array
    {
        $latest = [];
        foreach ($this->liveSessions($activeSinceUtc) as $session) {
            $current = $latest[$session['userId']] ?? null;
            if (
                $current === null
                || $session['lastActivity'] > $current['lastActivity']
                || ($session['lastActivity'] == $current['lastActivity'] && $session['order'] > $current['order'])
            ) {
                $latest[$session['userId']] = $session;
            }
        }

        $byModule = [];
        foreach ($latest as $session) {
            $byModule[$session['module']] = ($byModule[$session['module']] ?? 0) + 1;
        }

        return $byModule;
    }

    public function dailyActiveUsers(string $fromDate, string $toDate): array
    {
        $grouped = [];
        foreach ($this->daily as $row) {
            if ($row['date'] >= $fromDate && $row['date'] <= $toDate) {
                $grouped[$row['date']][] = $row;
            }
        }
        ksort($grouped);

        $rows = [];
        foreach ($grouped as $date => $members) {
            $rows[] = ['bucket' => (string) $date] + self::uniqueCounts($members);
        }

        return $rows;
    }

    public function hourlyActiveUsers(DateTimeImmutable $fromUtc, DateTimeImmutable $toUtc): array
    {
        $grouped = [];
        foreach ($this->hourly as $row) {
            if ($row['hour'] >= $fromUtc && $row['hour'] <= $toUtc) {
                $grouped[$row['hour']->format('Y-m-d\TH:i:s')][] = $row;
            }
        }
        ksort($grouped);

        $rows = [];
        foreach ($grouped as $bucket => $members) {
            $rows[] = ['bucket' => (string) $bucket] + self::uniqueCounts($members);
        }

        return $rows;
    }

    public function moduleActivity(string $fromDate, string $toDate): array
    {
        $grouped = [];
        foreach ($this->moduleDaily as $row) {
            if ($row['date'] >= $fromDate && $row['date'] <= $toDate) {
                $grouped[$row['module']][] = $row;
            }
        }
        ksort($grouped);

        $rows = [];
        foreach ($grouped as $module => $members) {
            $counts = self::uniqueCounts($members);
            $rows[] = [
                'module' => (string) $module,
                'users' => $counts['total'],
                'webUsers' => $counts['web'],
                'mobileUsers' => $counts['mobile'],
                'events' => array_sum(array_column($members, 'events')),
            ];
        }

        return $rows;
    }

    public function firstActivityDate(): ?string
    {
        $dates = array_column($this->daily, 'date');

        return $dates === [] ? null : min($dates);
    }

    public function purge(
        DateTimeImmutable $sessionsBeforeUtc,
        DateTimeImmutable $hourlyBeforeUtc,
        string $dailyBeforeDate,
        bool $dryRun,
    ): array {
        $sessions = array_filter($this->sessions, static fn (array $s): bool => $s['lastSignal'] < $sessionsBeforeUtc);
        $hourly = array_filter($this->hourly, static fn (array $h): bool => $h['hour'] < $hourlyBeforeUtc);
        $daily = array_filter($this->daily, static fn (array $d): bool => $d['date'] < $dailyBeforeDate);
        $moduleDaily = array_filter($this->moduleDaily, static fn (array $m): bool => $m['date'] < $dailyBeforeDate);

        if (!$dryRun) {
            $this->sessions = array_diff_key($this->sessions, $sessions);
            $this->hourly = array_diff_key($this->hourly, $hourly);
            $this->daily = array_diff_key($this->daily, $daily);
            $this->moduleDaily = array_diff_key($this->moduleDaily, $moduleDaily);
        }

        return [
            'sessions' => count($sessions),
            'hourly' => count($hourly),
            'daily' => count($daily),
            'moduleDaily' => count($moduleDaily),
        ];
    }

    /**
     * @return list<array{userId: int, platform: string, isStaff: bool, module: string, state: string, lastActivity: DateTimeImmutable, lastSignal: DateTimeImmutable, order: int}>
     */
    private function liveSessions(DateTimeImmutable $activeSinceUtc): array
    {
        return array_values(array_filter(
            $this->sessions,
            static fn (array $s): bool => $s['state'] === 'active' && $s['lastActivity'] >= $activeSinceUtc,
        ));
    }

    /**
     * @param iterable<array{userId: int, platform: string, isStaff: bool}> $rows
     * @return array{total: int, web: int, mobile: int, staff: int, customer: int}
     */
    private static function uniqueCounts(iterable $rows): array
    {
        $sets = ['total' => [], 'web' => [], 'mobile' => [], 'staff' => [], 'customer' => []];
        foreach ($rows as $row) {
            $sets['total'][$row['userId']] = true;
            $sets[$row['platform'] === 'web' ? 'web' : 'mobile'][$row['userId']] = true;
            $sets[$row['isStaff'] ? 'staff' : 'customer'][$row['userId']] = true;
        }

        return array_map('count', $sets);
    }
}
