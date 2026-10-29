<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Analytics\Contract\LivePresenceStore;

final class InMemoryLivePresenceStore implements LivePresenceStore
{
    /** @var array<int, array{isStaff: bool, platform: string, module: string, at: DateTimeImmutable}> */
    private array $users = [];

    public function touch(int $userId, bool $isStaff, string $platform, string $module, DateTimeImmutable $atUtc): void
    {
        $this->users[$userId] = [
            'isStaff' => $isStaff,
            'platform' => $platform,
            'module' => $module,
            'at' => $atUtc,
        ];
    }

    public function forget(int $userId): void
    {
        unset($this->users[$userId]);
    }

    public function liveTotals(DateTimeImmutable $activeSinceUtc): array
    {
        return $this->counts($this->active($activeSinceUtc));
    }

    public function liveByModule(DateTimeImmutable $activeSinceUtc): array
    {
        $byModule = [];
        foreach ($this->active($activeSinceUtc) as $row) {
            $byModule[$row['module']] = ($byModule[$row['module']] ?? 0) + 1;
        }

        return $byModule;
    }

    /**
     * @return list<array{isStaff: bool, platform: string, module: string, at: DateTimeImmutable}>
     */
    private function active(DateTimeImmutable $since): array
    {
        $rows = [];
        foreach ($this->users as $row) {
            if ($row['at'] >= $since) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<array{isStaff: bool, platform: string, module: string, at: DateTimeImmutable}> $rows
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
