<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Product;

use PHPUnit\Framework\TestCase;
use TriCab\Api\Product\Persistence\AzureSqlCatalogueNavigationRepository;
use TriCab\Api\Tests\Doubles\FakeSqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

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

    public function test_sibling_slug_exists_uses_unique_named_parameters_for_parent_and_root(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([]);
        $repository = new AzureSqlCatalogueNavigationRepository($connection);
        $repository->siblingSlugExists('tab-1', 'parent-1', 'j-box-assembly');

        self::assertCount(1, $connection->calls);
        self::assertSame(1, substr_count($connection->calls[0]['sql'], ':parentPublicId'));
        self::assertArrayHasKey(':parentPublicId', $connection->calls[0]['params']);
        self::assertUniqueNamedParameters($connection->calls[0]['sql']);

        $rootConnection = new FakeSqlConnection();
        $rootConnection->queueFetch([]);
        $rootRepository = new AzureSqlCatalogueNavigationRepository($rootConnection);
        $rootRepository->siblingSlugExists('tab-1', null, 'root');

        self::assertStringContainsString('[ParentNodeId] IS NULL', $rootConnection->calls[0]['sql']);
        self::assertStringNotContainsString(':parentPublicId', $rootConnection->calls[0]['sql']);
        self::assertUniqueNamedParameters($rootConnection->calls[0]['sql']);
    }

    public function test_reorder_and_usage_queries_use_unique_named_parameters(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([]);
        $repository = new AzureSqlCatalogueNavigationRepository($connection);
        $repository->reorderNodes('tab-1', 'parent-1', ['node-1'], UserId::fromInt(1));
        $repository->getNodeUsage('node-1');

        self::assertGreaterThanOrEqual(2, count($connection->calls));
        foreach ($connection->calls as $call) {
            self::assertUniqueNamedParameters($call['sql']);
        }
        self::assertSame(1, substr_count($connection->calls[0]['sql'], ':parentPublicId'));
        self::assertArrayHasKey(':productCountPublicId', $connection->calls[1]['params']);
        self::assertArrayHasKey(':regionCountPublicId', $connection->calls[1]['params']);
        self::assertStringContainsString('c.[CatalogueNodeId] IS NOT NULL', $connection->calls[1]['sql']);
    }

    public function test_list_public_node_products_selects_product_image_thumbnail(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([
            [
                'PublicId' => '11111111-1111-1111-1111-111111111301',
                'Name' => 'BV Cable',
                'Slug' => 'bv-cable',
                'InternalCode' => 'BV',
                'DisplayOrder' => 1,
                'IsFeatured' => 0,
                'ThumbnailUrl' => 'https://cdn.example/thumb.jpg',
            ],
        ]);
        $repository = new AzureSqlCatalogueNavigationRepository($connection);
        $cards = $repository->listPublicNodeProducts('node-1');

        self::assertCount(1, $cards);
        self::assertSame('https://cdn.example/thumb.jpg', $cards[0]->thumbnailUrl);
        self::assertStringContainsString('ProductImages', $connection->calls[0]['sql']);
        self::assertStringContainsString('product-thumbnail', $connection->calls[0]['sql']);
    }

    public function test_list_public_product_thumbnail_urls_is_batched(): void
    {
        $connection = new FakeSqlConnection();
        $connection->queueFetch([
            [
                'PublicId' => '11111111-1111-1111-1111-111111111301',
                'ThumbnailUrl' => 'https://cdn.example/thumb.jpg',
            ],
        ]);
        $repository = new AzureSqlCatalogueNavigationRepository($connection);
        $map = $repository->listPublicProductThumbnailUrls([
            '11111111-1111-1111-1111-111111111301',
            '11111111-1111-1111-1111-111111111302',
        ]);

        self::assertSame('https://cdn.example/thumb.jpg', $map['11111111-1111-1111-1111-111111111301']);
        self::assertCount(1, $connection->calls);
        self::assertStringContainsString('IN (', $connection->calls[0]['sql']);
        self::assertArrayHasKey(':pid0', $connection->calls[0]['params']);
        self::assertArrayHasKey(':pid1', $connection->calls[0]['params']);
    }

    public function test_get_public_node_by_slug_loads_product_cards_once_not_per_descendant(): void
    {
        $connection = new FakeSqlConnection();
        $parent = '00000000-0000-0000-0000-000000000101';
        $childA = '00000000-0000-0000-0000-000000000102';
        $childB = '00000000-0000-0000-0000-000000000103';
        $connection->queueFetch([
            [
                'PublicId' => $parent,
                'NodeCode' => 'MARINE',
                'NodeName' => 'Marine IEC LSZH',
                'Slug' => 'marine-iec-lszh',
                'Description' => null,
                'NodeTypeCode' => 'category',
                'ImageUrl' => null,
                'ImageAltText' => null,
                'DisplayOrder' => 0,
                'ParentPublicId' => null,
                'TabPublicId' => 'tab-1',
            ],
        ]);
        $connection->queueFetch([
            [
                'PublicId' => $parent,
                'NodeCode' => 'MARINE',
                'NodeName' => 'Marine IEC LSZH',
                'Slug' => 'marine-iec-lszh',
                'Description' => null,
                'NodeTypeCode' => 'category',
                'ImageUrl' => null,
                'ImageAltText' => null,
                'DisplayOrder' => 0,
                'ParentPublicId' => null,
                'ProductCount' => 0,
            ],
            self::publicNodeSqlRow($childA, $parent, 1),
            self::publicNodeSqlRow($childB, $parent, 1),
        ]);
        $connection->queueFetch([
            [
                'PublicId' => '11111111-1111-1111-1111-111111111301',
                'Name' => 'Root Cable',
                'Slug' => 'root-cable',
                'InternalCode' => 'R',
                'DisplayOrder' => 1,
                'IsFeatured' => 0,
                'ThumbnailUrl' => 'https://cdn.example/thumb.jpg',
            ],
        ]);

        $repository = new AzureSqlCatalogueNavigationRepository($connection);
        $node = $repository->getPublicNodeBySlug('marine-iec-lszh');

        self::assertNotNull($node);
        self::assertSame('marine-iec-lszh', $node->slug);
        self::assertCount(2, $node->children);
        self::assertCount(1, $node->products);
        self::assertSame('https://cdn.example/thumb.jpg', $node->products[0]->thumbnailUrl);
        self::assertSame([], $node->children[0]->products);
        self::assertSame([], $node->children[1]->products);
        $productCardQueries = array_values(array_filter(
            $connection->calls,
            static fn (array $call): bool => str_contains($call['sql'], '[IsFeatured]'),
        ));
        self::assertCount(1, $productCardQueries);
        self::assertLessThan(5, count($connection->calls));
    }

    /**
     * @return array<string, mixed>
     */
    private static function publicNodeSqlRow(string $publicId, ?string $parentPublicId, int $productCount): array
    {
        return [
            'PublicId' => $publicId,
            'NodeCode' => 'N-' . $publicId,
            'NodeName' => 'Node ' . $publicId,
            'Slug' => 'node-' . substr($publicId, -3),
            'Description' => null,
            'NodeTypeCode' => 'category',
            'ImageUrl' => null,
            'ImageAltText' => null,
            'DisplayOrder' => 0,
            'ParentPublicId' => $parentPublicId,
            'ProductCount' => $productCount,
        ];
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

    private static function assertUniqueNamedParameters(string $sql): void
    {
        preg_match_all('/(?<!:):([A-Za-z_][A-Za-z0-9_]*)/', $sql, $matches);
        $names = $matches[0];
        self::assertSame(
            $names,
            array_values(array_unique($names)),
            'sqlsrv named parameters must be unique in one statement: ' . $sql,
        );
    }
}
