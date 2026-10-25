<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;

final class ReferenceDataCacheRebuildLockTest extends TestCase
{
    private const KEY = 'catalogue:tree:abc';

    public function test_a_cached_value_is_returned_without_building(): void
    {
        $store = new ArrayApplicationCache();
        $store->set(self::KEY, ['data' => ['cached']], 300);
        $cache = new ReferenceDataCache($store);

        [$value, $hit] = $cache->rememberCatalogue(self::KEY, static fn (): array => self::fail('must not build'));

        self::assertSame(['data' => ['cached']], $value);
        self::assertTrue($hit);
    }

    public function test_a_miss_builds_once_stores_and_releases_the_lock(): void
    {
        $store = new ArrayApplicationCache();
        $cache = new ReferenceDataCache($store);
        $builds = 0;
        $build = static function () use (&$builds): array {
            ++$builds;

            return ['data' => ['built']];
        };

        self::assertSame([['data' => ['built']], false], $cache->rememberCatalogue(self::KEY, $build));
        self::assertSame([['data' => ['built']], true], $cache->rememberCatalogue(self::KEY, $build));
        self::assertSame(1, $builds);
        self::assertNull($store->get(ReferenceDataCache::CATALOGUE_REBUILD_LOCK_PREFIX . self::KEY));
    }

    public function test_a_waiting_request_uses_the_other_requests_result_instead_of_rebuilding(): void
    {
        $inner = new ArrayApplicationCache();
        $inner->add(ReferenceDataCache::CATALOGUE_REBUILD_LOCK_PREFIX . self::KEY, 1, 60);
        $gets = 0;
        $store = new HookedApplicationCache($inner, static function (string $key) use ($inner, &$gets): void {
            if ($key === self::KEY && ++$gets === 3) {
                $inner->set(self::KEY, ['data' => ['from-other-request']], 300);
            }
        });
        $cache = new ReferenceDataCache($store, rebuildWaitMs: 2000, rebuildPollMs: 1);

        [$value, $hit] = $cache->rememberCatalogue(self::KEY, static fn (): array => self::fail('must not build'));

        self::assertSame(['data' => ['from-other-request']], $value);
        self::assertTrue($hit);
    }

    public function test_a_waiting_request_builds_itself_when_the_other_build_ends_without_a_value(): void
    {
        $inner = new ArrayApplicationCache();
        $lockKey = ReferenceDataCache::CATALOGUE_REBUILD_LOCK_PREFIX . self::KEY;
        $inner->add($lockKey, 1, 60);
        $store = new HookedApplicationCache($inner, static function (string $key) use ($inner, $lockKey): void {
            if ($key === self::KEY) {
                $inner->delete($lockKey);
            }
        });
        $cache = new ReferenceDataCache($store, rebuildWaitMs: 5000, rebuildPollMs: 1);

        $started = hrtime(true);
        [$value, $hit] = $cache->rememberCatalogue(self::KEY, static fn (): array => ['data' => ['own']]);

        self::assertSame(['data' => ['own']], $value);
        self::assertFalse($hit);
        self::assertLessThan(1000, (hrtime(true) - $started) / 1e6, 'does not wait out the full timeout');
    }

    public function test_waiting_is_bounded_when_the_lock_is_never_released(): void
    {
        $store = new ArrayApplicationCache();
        $store->add(ReferenceDataCache::CATALOGUE_REBUILD_LOCK_PREFIX . self::KEY, 1, 60);
        $cache = new ReferenceDataCache($store, rebuildWaitMs: 30, rebuildPollMs: 5);

        $started = hrtime(true);
        [$value, $hit] = $cache->rememberCatalogue(self::KEY, static fn (): array => ['data' => ['own']]);
        $elapsedMs = (hrtime(true) - $started) / 1e6;

        self::assertSame(['data' => ['own']], $value);
        self::assertFalse($hit);
        self::assertGreaterThanOrEqual(30, $elapsedMs);
        self::assertLessThan(1000, $elapsedMs);
    }

    public function test_a_build_overtaken_by_invalidation_is_returned_but_not_cached(): void
    {
        $store = new ArrayApplicationCache();
        $cache = new ReferenceDataCache($store);

        [$value] = $cache->rememberCatalogue(self::KEY, static function () use ($cache): array {
            $cache->invalidateCatalogue();

            return ['data' => ['stale']];
        });

        self::assertSame(['data' => ['stale']], $value);
        self::assertNull($store->get(self::KEY));
    }

    public function test_invalidation_keeps_a_held_rebuild_lock(): void
    {
        $store = new ArrayApplicationCache();
        $lockKey = ReferenceDataCache::CATALOGUE_REBUILD_LOCK_PREFIX . self::KEY;
        $store->add($lockKey, 1, 60);

        (new ReferenceDataCache($store))->invalidateCatalogue();

        self::assertSame(1, $store->get($lockKey));
    }

    public function test_an_invalidation_racing_the_cache_write_removes_the_stale_entry(): void
    {
        $inner = new ArrayApplicationCache();
        $store = new HookedApplicationCache(
            $inner,
            static function (string $key): void {
            },
            static function (string $key) use ($inner): void {
                if ($key === self::KEY) {
                    // Another request's invalidation: generation bumped, entry deletion not yet run.
                    $inner->set(ReferenceDataCache::CATALOGUE_GENERATION_KEY, 'newer', 86400);
                }
            },
        );
        $cache = new ReferenceDataCache($store);

        [$value] = $cache->rememberCatalogue(self::KEY, static fn (): array => ['data' => ['stale']]);

        self::assertSame(['data' => ['stale']], $value);
        self::assertNull($inner->get(self::KEY));
    }

    public function test_a_build_that_outlives_its_lock_does_not_release_the_next_holders_lock(): void
    {
        $store = new ArrayApplicationCache();
        $lockKey = ReferenceDataCache::CATALOGUE_REBUILD_LOCK_PREFIX . self::KEY;
        $cache = new ReferenceDataCache($store);

        $cache->rememberCatalogue(self::KEY, static function () use ($store, $lockKey): array {
            $store->delete($lockKey);
            $store->add($lockKey, 'next-holder', 60);

            return ['data' => ['slow']];
        });

        self::assertSame('next-holder', $store->get($lockKey));
    }

    public function test_a_waiter_that_takes_over_the_lock_rechecks_for_a_result_before_rebuilding(): void
    {
        $inner = new ArrayApplicationCache();
        $lockKey = ReferenceDataCache::CATALOGUE_REBUILD_LOCK_PREFIX . self::KEY;
        $inner->add($lockKey, 'holder', 60);
        $reads = 0;
        $store = new HookedApplicationCache($inner, static function (string $key) use ($inner, $lockKey, &$reads): void {
            if ($key !== self::KEY) {
                return;
            }
            ++$reads;
            if ($reads === 2) {
                // Holder releases right after this waiter's poll read missed.
                $inner->delete($lockKey);
            }
            if ($reads === 3) {
                // ...and its result became visible just before the waiter took the lock.
                $inner->set(self::KEY, ['data' => ['holder-result']], 300);
            }
        });
        $cache = new ReferenceDataCache($store, rebuildWaitMs: 2000, rebuildPollMs: 1);

        [$value, $hit] = $cache->rememberCatalogue(self::KEY, static fn (): array => self::fail('must not rebuild'));

        self::assertSame(['data' => ['holder-result']], $value);
        self::assertTrue($hit);
        self::assertNull($inner->get($lockKey));
    }

    public function test_the_lock_is_released_when_the_build_fails(): void
    {
        $store = new ArrayApplicationCache();
        $cache = new ReferenceDataCache($store);

        try {
            $cache->rememberCatalogue(self::KEY, static fn (): array => throw new RuntimeException('not found'));
            self::fail('expected the build exception');
        } catch (RuntimeException) {
        }

        self::assertNull($store->get(ReferenceDataCache::CATALOGUE_REBUILD_LOCK_PREFIX . self::KEY));
        self::assertNull($store->get(self::KEY));
    }
}

/**
 * Runs a hook before every get(), to simulate another request acting while this one waits.
 *
 * @internal
 */
final class HookedApplicationCache implements ApplicationCache
{
    /**
     * @param Closure(string): void $beforeGet
     * @param (Closure(string): void)|null $afterSet
     */
    public function __construct(
        private readonly ApplicationCache $inner,
        private readonly Closure $beforeGet,
        private readonly ?Closure $afterSet = null,
    ) {
    }

    public function get(string $key): mixed
    {
        ($this->beforeGet)($key);

        return $this->inner->get($key);
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->inner->set($key, $value, $ttlSeconds);
        if ($this->afterSet !== null) {
            ($this->afterSet)($key);
        }
    }

    public function delete(string $key): void
    {
        $this->inner->delete($key);
    }

    public function deleteByPrefix(string $prefix): void
    {
        $this->inner->deleteByPrefix($prefix);
    }

    public function add(string $key, mixed $value, int $ttlSeconds): bool
    {
        return $this->inner->add($key, $value, $ttlSeconds);
    }

    public function driver(): string
    {
        return $this->inner->driver();
    }

    public function isCrossRequest(): bool
    {
        return $this->inner->isCrossRequest();
    }
}
