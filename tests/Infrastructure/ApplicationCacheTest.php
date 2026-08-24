<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use TriCab\Api\Infrastructure\Cache\ApcuApplicationCache;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Infrastructure\Cache\FilesystemApplicationCache;
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

    public function test_file_cache_survives_across_instances_and_invalidates_by_prefix(): void
    {
        $directory = sys_get_temp_dir() . '/tricab-app-cache-' . bin2hex(random_bytes(4));
        $first = new FilesystemApplicationCache($directory);
        $first->set('admin:product-lookups:v2', ['marker' => 'file'], 60);
        $first->set('catalogue:panel:one', ['n' => 1], 60);

        $second = new FilesystemApplicationCache($directory);
        $reference = new ReferenceDataCache($second);
        self::assertSame(['marker' => 'file'], $reference->getLookups());

        $reference->invalidateLookups();
        $reference->invalidateCatalogue();
        self::assertNull($second->get('admin:product-lookups:v2'));
        self::assertNull($second->get('catalogue:panel:one'));
    }

    public function test_file_add_is_atomic_across_instances(): void
    {
        $directory = sys_get_temp_dir() . '/tricab-app-lock-' . bin2hex(random_bytes(4));
        $first = new FilesystemApplicationCache($directory);
        $second = new FilesystemApplicationCache($directory);
        self::assertTrue($first->add('lock', 1, 5));
        self::assertFalse($second->add('lock', 1, 5));
        $first->delete('lock');
        self::assertTrue($second->add('lock', 1, 5));
        $second->delete('lock');
    }
}
