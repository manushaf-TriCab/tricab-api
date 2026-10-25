<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Infrastructure\Cache\ArrayApplicationCache;
use TriCab\Api\Infrastructure\Cache\ReferenceDataCache;
use TriCab\Api\Product\Persistence\AzureSqlCatalogueNavigationRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;

final class AzureSqlCatalogueTabNodesCacheTest extends TestCase
{
    private const TAB = '00000000-0000-0000-0000-0000000000aa';
    private const PARENT = '00000000-0000-0000-0000-000000000101';
    private const CHILD_A = '00000000-0000-0000-0000-000000000102';
    private const CHILD_B = '00000000-0000-0000-0000-000000000103';

    public function test_nodes_of_one_tab_share_a_single_tab_query(): void
    {
        $connection = new FakeSqlConnection();
        $repository = new AzureSqlCatalogueNavigationRepository(
            $connection,
            new ReferenceDataCache(new ArrayApplicationCache()),
        );

        $connection->queueFetch([self::lookupRow(self::PARENT, null, 'parent')]);
        $connection->queueFetch(self::tabRows());
        $connection->queueFetch([]);
        $parent = $repository->getPublicNodeBySlug('parent');

        $connection->queueFetch([self::lookupRow(self::CHILD_A, self::PARENT, 'child-a')]);
        $connection->queueFetch([]);
        $child = $repository->getPublicNodeBySlug('child-a');

        self::assertNotNull($parent);
        self::assertCount(2, $parent->children);
        self::assertNotNull($child);
        self::assertSame(self::CHILD_A, $child->publicId);
        self::assertSame(1, $parent->children[0]->depth);
        self::assertSame(1, self::tabQueryCount($connection));
    }

    public function test_catalogue_invalidation_rebuilds_the_tab_nodes(): void
    {
        $connection = new FakeSqlConnection();
        $cache = new ReferenceDataCache(new ArrayApplicationCache());
        $repository = new AzureSqlCatalogueNavigationRepository($connection, $cache);

        $connection->queueFetch([self::lookupRow(self::PARENT, null, 'parent')]);
        $connection->queueFetch(self::tabRows());
        $connection->queueFetch([]);
        $repository->getPublicNodeBySlug('parent');

        $cache->invalidateCatalogue();

        $connection->queueFetch([self::lookupRow(self::PARENT, null, 'parent')]);
        $connection->queueFetch([self::tabRow(self::PARENT, null, 'parent', 'Renamed parent')]);
        $connection->queueFetch([]);
        $renamed = $repository->getPublicNodeBySlug('parent');

        self::assertNotNull($renamed);
        self::assertSame('Renamed parent', $renamed->nodeName);
        self::assertSame([], $renamed->children);
        self::assertSame(2, self::tabQueryCount($connection));
    }

    public function test_without_a_cache_every_lookup_queries_the_tab(): void
    {
        $connection = new FakeSqlConnection();
        $repository = new AzureSqlCatalogueNavigationRepository($connection);

        foreach (['parent', 'child-a'] as $slug) {
            $id = $slug === 'parent' ? self::PARENT : self::CHILD_A;
            $connection->queueFetch([self::lookupRow($id, $slug === 'parent' ? null : self::PARENT, $slug)]);
            $connection->queueFetch(self::tabRows());
            $connection->queueFetch([]);
            self::assertNotNull($repository->getPublicNodeBySlug($slug));
        }

        self::assertSame(2, self::tabQueryCount($connection));
    }

    private static function tabQueryCount(FakeSqlConnection $connection): int
    {
        return count(array_filter(
            $connection->calls,
            static fn (array $call): bool => str_contains($call['sql'], 'leafThumb'),
        ));
    }

    /** @return list<array<string, mixed>> */
    private static function tabRows(): array
    {
        return [
            self::tabRow(self::PARENT, null, 'parent', 'Parent'),
            self::tabRow(self::CHILD_A, self::PARENT, 'child-a', 'Child A'),
            self::tabRow(self::CHILD_B, self::PARENT, 'child-b', 'Child B'),
        ];
    }

    /** @return array<string, mixed> */
    private static function tabRow(string $publicId, ?string $parentPublicId, string $slug, string $name): array
    {
        return [
            'PublicId' => $publicId,
            'NodeCode' => strtoupper($slug),
            'NodeName' => $name,
            'Slug' => $slug,
            'Description' => null,
            'NodeTypeCode' => 'category',
            'ImageUrl' => null,
            'ImageAltText' => null,
            'DisplayOrder' => 0,
            'ParentPublicId' => $parentPublicId,
            'ProductCount' => 1,
        ];
    }

    /** @return array<string, mixed> */
    private static function lookupRow(string $publicId, ?string $parentPublicId, string $slug): array
    {
        return [
            ...self::tabRow($publicId, $parentPublicId, $slug, $slug),
            'TabPublicId' => self::TAB,
        ];
    }
}
