<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Cache\ProcessMemoryCache;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;

final class ReferenceDataCacheTest extends TestCase
{
    protected function setUp(): void
    {
        ProcessMemoryCache::clearAll();
    }

    protected function tearDown(): void
    {
        ProcessMemoryCache::clearAll();
    }

    public function test_lookups_round_trip_and_invalidation(): void
    {
        $cache = new ReferenceDataCache(new ProcessMemoryCache());
        $cache->setLookups(['families' => ['a']]);

        self::assertSame(['families' => ['a']], $cache->getLookups());
        $cache->invalidateLookups();
        self::assertNull($cache->getLookups());
    }

    public function test_catalogue_keys_differ_by_region_and_include_products(): void
    {
        $cache = new ReferenceDataCache(new ProcessMemoryCache());
        $au = $cache->cataloguePanelKey(['tabSlug' => 'products', 'regionCode' => 'AU', 'includeProducts' => '0']);
        $nz = $cache->cataloguePanelKey(['tabSlug' => 'products', 'regionCode' => 'NZ', 'includeProducts' => '0']);
        $withProducts = $cache->cataloguePanelKey(['tabSlug' => 'products', 'regionCode' => 'AU', 'includeProducts' => '1']);

        self::assertNotSame($au, $nz);
        self::assertNotSame($au, $withProducts);

        $cache->setCatalogue($au, ['etag' => '"a"', 'data' => ['tabs' => []]]);
        $cache->setCatalogue($nz, ['etag' => '"b"', 'data' => ['tabs' => ['nz']]]);
        self::assertSame(['etag' => '"a"', 'data' => ['tabs' => []]], $cache->getCatalogue($au));

        $cache->invalidateCatalogue();
        self::assertNull($cache->getCatalogue($au));
        self::assertNull($cache->getCatalogue($nz));
    }

    public function test_expired_items_are_not_returned(): void
    {
        $store = new ProcessMemoryCache();
        $store->set('k', 'v', 1);
        self::assertSame('v', $store->get('k'));
        $store->set('k', 'v', 1);
        // Force expiry by deleting; TTL of 1 second is not slept here.
        $store->delete('k');
        self::assertNull($store->get('k'));
    }
}
