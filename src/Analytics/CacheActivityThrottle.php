<?php

declare(strict_types=1);

namespace TriCab\Api\Analytics;

use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Core\Analytics\Contract\ActivityThrottle;

/** Activity limiting over the application cache, counted like the guest sign-in and AR form limiters. */
final class CacheActivityThrottle implements ActivityThrottle
{
    private const PREFIX = 'analytics-signal:';

    public function __construct(private readonly ApplicationCache $cache)
    {
    }

    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $cacheKey = self::PREFIX . $key;
        $now = time();
        $stored = $this->cache->get($cacheKey);
        $count = 0;
        $expiresAt = $now + $windowSeconds;

        if (is_array($stored) && isset($stored['count'], $stored['expiresAt'])
            && is_int($stored['count']) && is_int($stored['expiresAt'])
            && $stored['expiresAt'] > $now) {
            $count = $stored['count'];
            $expiresAt = $stored['expiresAt'];
        } elseif (is_int($stored)) {
            $count = $stored;
        }

        if ($count >= $limit) {
            return false;
        }

        $this->cache->set($cacheKey, [
            'count' => $count + 1,
            'expiresAt' => $expiresAt,
        ], max(1, $expiresAt - $now));

        return true;
    }

    public function isRecorded(string $key): bool
    {
        return $this->cache->get(self::PREFIX . $key) !== null;
    }

    public function markRecorded(string $key, int $ttlSeconds): void
    {
        $this->cache->set(self::PREFIX . $key, 1, $ttlSeconds);
    }

    public function forget(string $key): void
    {
        $this->cache->delete(self::PREFIX . $key);
    }
}
