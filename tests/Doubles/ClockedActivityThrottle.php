<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Doubles;

use TriCab\Core\Analytics\Contract\ActivityThrottle;
use TriCab\Core\Shared\Time\Clock;

/** The cache throttle's behaviour on the test clock, so suppression windows follow FixedClock::advance(). */
final class ClockedActivityThrottle implements ActivityThrottle
{
    /** @var array<string, array{value: int, expiresAt: int}> */
    private array $items = [];

    public function __construct(private readonly Clock $clock)
    {
    }

    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $count = $this->current('rate:' . $key) + 1;
        $this->items['rate:' . $key] = ['value' => $count, 'expiresAt' => $this->now() + $windowSeconds];

        return $count <= $limit;
    }

    public function isRecorded(string $key): bool
    {
        return $this->current('rec:' . $key) > 0;
    }

    public function markRecorded(string $key, int $ttlSeconds): void
    {
        $this->items['rec:' . $key] = ['value' => 1, 'expiresAt' => $this->now() + $ttlSeconds];
    }

    public function forget(string $key): void
    {
        unset($this->items['rec:' . $key]);
    }

    private function current(string $key): int
    {
        $item = $this->items[$key] ?? null;
        if ($item === null || $item['expiresAt'] <= $this->now()) {
            return 0;
        }

        return $item['value'];
    }

    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }
}
