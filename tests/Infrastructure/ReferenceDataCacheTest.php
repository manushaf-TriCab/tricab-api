<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Infrastructure;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;

final class ReferenceDataCacheTest extends TestCase
{
    public function test_lookups_round_trip_and_invalidation(): void
    {
        $cache = new ReferenceDataCache(new ArrayApplicationCache());
        $cache->setLookups(['families' => ['a']]);

        self::assertSame(['families' => ['a']], $cache->getLookups());
        $cache->invalidateLookups();
        self::assertNull($cache->getLookups());
    }

    public function test_catalogue_keys_differ_by_region_and_include_products(): void
    {
        $cache = new ReferenceDataCache(new ArrayApplicationCache());
        $au = $cache->cataloguePanelKey(['tabSlug' => 'products', 'regionCode' => 'AU', 'includeProducts' => '0']);
        $nz = $cache->cataloguePanelKey(['tabSlug' => 'products', 'regionCode' => 'NZ', 'includeProducts' => '0']);
        $withProducts = $cache->cataloguePanelKey(['tabSlug' => 'products', 'regionCode' => 'AU', 'includeProducts' => '1']);

        self::assertNotSame($au, $nz);
        self::assertNotSame($au, $withProducts);
        self::assertStringStartsWith('catalogue:panel:', $au);

        $cache->setCatalogue($au, ['etag' => '"a"', 'data' => ['tabs' => []]]);
        $cache->setCatalogue($nz, ['etag' => '"b"', 'data' => ['tabs' => ['nz']]]);
        self::assertSame(['etag' => '"a"', 'data' => ['tabs' => []]], $cache->getCatalogue($au));

        $cache->invalidateCatalogue();
        self::assertNull($cache->getCatalogue($au));
        self::assertNull($cache->getCatalogue($nz));
    }

    public function test_node_and_tree_keys_share_catalogue_invalidation_with_the_panel(): void
    {
        $cache = new ReferenceDataCache(new ArrayApplicationCache());
        $node = $cache->catalogueKey('node', ['node' => 'control-cables', 'tabSlug' => null, 'regionCode' => 'AU']);
        $nodeNz = $cache->catalogueKey('node', ['node' => 'control-cables', 'tabSlug' => null, 'regionCode' => 'NZ']);
        $tree = $cache->catalogueKey('tree', ['tabSlug' => 'products', 'regionCode' => 'AU', 'includeProducts' => '0']);
        $panel = $cache->cataloguePanelKey(['tabSlug' => 'products', 'regionCode' => 'AU', 'includeProducts' => '0']);

        self::assertStringStartsWith('catalogue:node:', $node);
        self::assertStringStartsWith('catalogue:tree:', $tree);
        self::assertNotSame($node, $nodeNz);
        self::assertNotSame($tree, $panel);

        foreach ([$node, $nodeNz, $tree, $panel] as $key) {
            $cache->setCatalogue($key, ['data' => ['key' => $key]]);
        }
        $cache->invalidateCatalogue();
        foreach ([$node, $nodeNz, $tree, $panel] as $key) {
            self::assertNull($cache->getCatalogue($key));
        }
    }

    public function test_expired_items_are_not_returned(): void
    {
        $store = new ArrayApplicationCache();
        $store->set('k', 'v', 1);
        self::assertSame('v', $store->get('k'));
        $store->delete('k');
        self::assertNull($store->get('k'));
    }

    public function test_lookups_key_is_versioned_admin_namespace(): void
    {
        self::assertSame('admin:product-lookups:v2', ReferenceDataCache::LOOKUPS_KEY);
    }
}
