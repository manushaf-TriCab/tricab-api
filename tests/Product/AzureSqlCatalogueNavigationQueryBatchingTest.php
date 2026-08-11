<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\AzureSqlCatalogueNavigationRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;

final class AzureSqlCatalogueNavigationQueryBatchingTest extends TestCase
{
    public function test_list_nodes_for_tab_uses_fixed_query_count_not_per_node(): void
    {
        $connection = new FakeSqlConnection();
        $rows = [];
        $parentId = null;
        for ($i = 1; $i <= 97; ++$i) {
            $publicId = sprintf('00000000-0000-0000-0000-%012d', $i);
            $rows[] = self::nodeRow($publicId, $parentId, $i === 1 ? 0 : 1);
            if ($i === 1) {
                $parentId = $publicId;
            }
        }
        $connection->queueFetch($rows);
        $connection->queueFetch([
            ['NodePublicId' => '00000000-0000-0000-0000-000000000001', 'RegionCode' => 'AU'],
        ]);

        $repository = new AzureSqlCatalogueNavigationRepository($connection);
        $nodes = $repository->listNodesForTab('tab-1');

        self::assertCount(97, $nodes);
        self::assertSame(0, $nodes[0]->depth);
        self::assertSame(1, $nodes[1]->depth);
        self::assertSame(['AU'], $nodes[0]->regionCodes);
        self::assertSame([], $nodes[1]->regionCodes);
        self::assertCount(2, $connection->calls);
        self::assertLessThan(5, count($connection->calls));
        self::assertStringContainsString('CatalogueNodes', $connection->calls[0]['sql']);
        self::assertStringContainsString('CatalogueNodeRegions', $connection->calls[1]['sql']);
    }

    public function test_find_tab_by_slug_queries_one_row_not_all_tabs(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([self::tabRow('by-slug')]);
        $repository = new AzureSqlCatalogueNavigationRepository($connection);

        $tab = $repository->findTabBySlug('by-slug');

        self::assertNotNull($tab);
        self::assertSame('by-slug', $tab->slug);
        self::assertCount(1, $connection->calls);
        self::assertArrayHasKey(':slug', $connection->calls[0]['params']);
        self::assertSame('by-slug', $connection->calls[0]['params'][':slug']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function nodeRow(string $publicId, ?string $parentPublicId, int $childCount): array
    {
        return [
            'PublicId' => $publicId,
            'TabPublicId' => 'tab-1',
            'TabSlug' => 'products',
            'TabName' => 'Products',
            'ParentPublicId' => $parentPublicId,
            'ParentName' => $parentPublicId === null ? null : 'Root',
            'NodeCode' => 'N-' . $publicId,
            'NodeName' => 'Node ' . $publicId,
            'Slug' => 'node-' . $publicId,
            'Description' => null,
            'NodeTypeCode' => 'category',
            'ImageUrl' => null,
            'ImageAltText' => null,
            'ImageMediaPublicId' => null,
            'DisplayOrder' => 0,
            'IsActive' => 1,
            'IsPublic' => 1,
            'IsDeleted' => 0,
            'SeoTitle' => null,
            'SeoDescription' => null,
            'RowVersion' => str_repeat("\0", 7) . "\x01",
            'CreatedAtUtc' => '2026-01-01T00:00:00',
            'UpdatedAtUtc' => '2026-01-01T00:00:00',
            'ChildCount' => $childCount,
            'ProductCount' => 0,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tabRow(string $slug): array
    {
        return [
            'PublicId' => 'tab-public',
            'TabCode' => 'PRODUCTS',
            'TabName' => 'Products',
            'Description' => null,
            'Slug' => $slug,
            'DisplayOrder' => 0,
            'IsActive' => 1,
            'IsPublic' => 1,
            'IsDeleted' => 0,
            'RowVersion' => str_repeat("\0", 7) . "\x01",
            'CreatedAtUtc' => '2026-01-01T00:00:00',
            'UpdatedAtUtc' => '2026-01-01T00:00:00',
            'RootNodeCount' => 1,
        ];
    }
}
