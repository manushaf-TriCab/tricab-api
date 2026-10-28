<?php

declare(strict_types=1);

namespace TriCab\Core\Analytics\Contract;

use DateTimeImmutable;

/**
 * Who is interacting right now, from client activity signals. Not historical and not last login.
 */
interface LivePresenceStore
{
    public function touch(int $userId, bool $isStaff, string $platform, string $module, DateTimeImmutable $atUtc): void;

    public function forget(int $userId): void;

    /**
     * @return array{total: int, web: int, mobile: int, staff: int, customer: int}
     */
    public function liveTotals(DateTimeImmutable $activeSinceUtc): array;

    /**
     * @return array<string, int>
     */
    public function liveByModule(DateTimeImmutable $activeSinceUtc): array;
}
