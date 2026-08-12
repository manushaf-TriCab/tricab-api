<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TriCab\Api\Infrastructure\Cache\ApcuApplicationCache;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;
use TriCab\Api\Tests\TestAppFactory;

final class ApplicationCacheTest extends TestCase
{
    public function test_array_cache_is_not_shared_across_containers(): void
    {
        $first = TestAppFactory::create(['APP_CACHE_DRIVER' => 'array']);
        $second = TestAppFactory::create(['APP_CACHE_DRIVER' => 'array']);
        $firstContainer = $first->getContainer();
        $secondContainer = $second->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $firstContainer);
        self::assertInstanceOf(ContainerInterface::class, $secondContainer);

        $firstCache = $firstContainer->get(ReferenceDataCache::class);
        $secondCache = $secondContainer->get(ReferenceDataCache::class);
        self::assertInstanceOf(ReferenceDataCache::class, $firstCache);
        self::assertInstanceOf(ReferenceDataCache::class, $secondCache);

        $firstCache->setLookups(['marker' => 'one']);
        self::assertNull($secondCache->getLookups());
    }

    public function test_apcu_is_shared_across_containers_when_usable(): void
    {
        if (!ApcuApplicationCache::isUsable()) {
            self::markTestSkipped('APCu is not usable in this SAPI (need extension + apc.enable_cli=1 for CLI).');
        }

        $store = new ApcuApplicationCache();
        $store->delete(ReferenceDataCache::LOOKUPS_KEY);

        $first = TestAppFactory::create(['APP_CACHE_DRIVER' => 'apcu']);
        $second = TestAppFactory::create(['APP_CACHE_DRIVER' => 'apcu']);
        $firstContainer = $first->getContainer();
        $secondContainer = $second->getContainer();
        self::assertInstanceOf(ContainerInterface::class, $firstContainer);
        self::assertInstanceOf(ContainerInterface::class, $secondContainer);

        $firstCache = $firstContainer->get(ReferenceDataCache::class);
        $secondCache = $secondContainer->get(ReferenceDataCache::class);
        self::assertInstanceOf(ReferenceDataCache::class, $firstCache);
        self::assertInstanceOf(ReferenceDataCache::class, $secondCache);

        $firstCache->setLookups(['marker' => 'apcu-shared']);
        try {
            self::assertSame(['marker' => 'apcu-shared'], $secondCache->getLookups());
        } finally {
            $store->delete(ReferenceDataCache::LOOKUPS_KEY);
        }
    }

    public function test_array_add_is_atomic_within_instance(): void
    {
        $cache = new ArrayApplicationCache();
        self::assertTrue($cache->add('lock', 1, 5));
        self::assertFalse($cache->add('lock', 1, 5));
        $cache->delete('lock');
        self::assertTrue($cache->add('lock', 1, 5));
    }
}
