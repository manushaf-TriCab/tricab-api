<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Analytics;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Analytics\CacheActivityThrottle;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;

final class CacheActivityThrottleTest extends TestCase
{
    public function test_denied_attempts_do_not_refresh_the_window(): void
    {
        $cache = new ArrayApplicationCache();
        $throttle = new CacheActivityThrottle($cache);

        self::assertTrue($throttle->allow('read:1', 2, 60));
        self::assertTrue($throttle->allow('read:1', 2, 60));
        self::assertFalse($throttle->allow('read:1', 2, 60));
        self::assertFalse($throttle->allow('read:1', 2, 60));
        self::assertNotNull($cache->get('analytics-signal:read:1'));
    }
}
