<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Core\Finance\Ar\Contract\ArRateLimiter;

/**
 * The public form's limiter over the platform's application cache, as the guest sign-in limiter is: a counter per key
 * that lives for the window from its last attempt. It counts every attempt, allowed or not.
 */
final class CacheArRateLimiter implements ArRateLimiter
{
    public function __construct(private readonly ApplicationCache $cache)
    {
    }

    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $stored = $this->cache->get('finance-ar:' . $key);
        $count = (is_int($stored) ? $stored : 0) + 1;
        $this->cache->set('finance-ar:' . $key, $count, $windowSeconds);

        return $count <= $limit;
    }
}
