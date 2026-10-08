<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use TriCab\Core\Finance\Ar\Contract\ArRateLimiter;

/** A limiter that counts in memory (the tests', with a clock they can move) and never forgets within a window. */
final class InMemoryArRateLimiter implements ArRateLimiter
{
    /** @var array<string, list<int>> attempt times by key */
    private array $hits = [];

    /** @var callable(): int */
    private $clock;

    /**
     * @param (callable(): int)|null $clock seconds
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $now = ($this->clock)();
        $recent = array_values(array_filter($this->hits[$key] ?? [], static fn (int $at): bool => $at > $now - $windowSeconds));
        $recent[] = $now;
        $this->hits[$key] = $recent;

        return count($recent) <= $limit;
    }
}
