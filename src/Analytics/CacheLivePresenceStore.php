<?php

declare(strict_types=1);

namespace TriCab\Api\Analytics;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Core\Analytics\Application\Service\ActivityAnalyticsService;
use TriCab\Core\Analytics\Contract\LivePresenceStore;

/** Shared live-presence map so php -S workers can see who is sending activity signals. */
final class CacheLivePresenceStore implements LivePresenceStore
{
    private const KEY = 'analytics-live-presence';

    public function __construct(private readonly ApplicationCache $cache)
    {
    }

    public function touch(int $userId, bool $isStaff, string $platform, string $module, DateTimeImmutable $atUtc): void
    {
        $users = $this->users();
        $users[$userId] = [
            'isStaff' => $isStaff,
            'platform' => $platform,
            'module' => $module,
            'at' => $atUtc->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
        ];
        $this->cache->set(self::KEY, $users, ActivityAnalyticsService::LIVE_WINDOW_SECONDS);
    }

    public function forget(int $userId): void
    {
        $users = $this->users();
        unset($users[$userId]);
        $this->cache->set(self::KEY, $users, ActivityAnalyticsService::LIVE_WINDOW_SECONDS);
    }

    public function liveTotals(DateTimeImmutable $activeSinceUtc): array
    {
        return $this->counts($this->active($activeSinceUtc));
    }

    public function liveByModule(DateTimeImmutable $activeSinceUtc): array
    {
        $byModule = [];
        foreach ($this->active($activeSinceUtc) as $row) {
            $module = $row['module'];
            $byModule[$module] = ($byModule[$module] ?? 0) + 1;
        }

        return $byModule;
    }

    /**
     * @return array<int, array{isStaff: bool, platform: string, module: string, at: string}>
     */
    private function users(): array
    {
        $stored = $this->cache->get(self::KEY);
        if (!is_array($stored)) {
            return [];
        }

        $users = [];
        foreach ($stored as $userId => $row) {
            if (!is_numeric($userId) || !is_array($row)) {
                continue;
            }
            $at = $row['at'] ?? null;
            $platform = $row['platform'] ?? null;
            $module = $row['module'] ?? null;
            if (!is_string($at) || !is_string($platform) || !is_string($module)) {
                continue;
            }
            $users[(int) $userId] = [
                'isStaff' => (bool) ($row['isStaff'] ?? false),
                'platform' => $platform,
                'module' => $module,
                'at' => $at,
            ];
        }

        return $users;
    }

    /**
     * @return list<array{isStaff: bool, platform: string, module: string, at: string}>
     */
    private function active(DateTimeImmutable $since): array
    {
        $cutoff = $since->getTimestamp();
        $rows = [];
        foreach ($this->users() as $row) {
            $at = strtotime($row['at']);
            if ($at !== false && $at >= $cutoff) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array{isStaff: bool, platform: string, module: string, at: string}> $rows
     * @return array{total: int, web: int, mobile: int, staff: int, customer: int}
     */
    private function counts(array $rows): array
    {
        $web = 0;
        $mobile = 0;
        $staff = 0;
        foreach ($rows as $row) {
            if ($row['platform'] === 'web') {
                $web++;
            } else {
                $mobile++;
            }
            if ($row['isStaff']) {
                $staff++;
            }
        }

        return [
            'total' => count($rows),
            'web' => $web,
            'mobile' => $mobile,
            'staff' => $staff,
            'customer' => count($rows) - $staff,
        ];
    }
}
