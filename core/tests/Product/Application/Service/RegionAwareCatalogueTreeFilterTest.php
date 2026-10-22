<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Application\Service;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Product\Application\DTO\PublicCatalogueProductCard;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode;
use TriCab\Core\Product\Application\Service\RegionAwareCatalogueTreeFilter;

final class RegionAwareCatalogueTreeFilterTest extends TestCase
{
    private RegionAwareCatalogueTreeFilter $filter;

    protected function setUp(): void
    {
        $this->filter = new RegionAwareCatalogueTreeFilter();
    }

    public function test_keeps_nodes_with_visible_products(): void
    {
        $tree = [$this->node('control', hasProducts: true, children: [
            $this->node('ali-flex', hasProducts: true),
            $this->node('flexicom', hasProducts: false),
        ])];

        $filtered = $this->filter->filterTree($tree);

        self::assertCount(1, $filtered);
        self::assertSame('control', $filtered[0]->slug);
        self::assertCount(1, $filtered[0]->children);
        self::assertSame('ali-flex', $filtered[0]->children[0]->slug);
    }

    public function test_preserves_direct_product_counts(): void
    {
        $crimpers = new PublicCatalogueTreeNode(
            publicId: 'crimpers',
            nodeCode: 'CRIMPERS',
            nodeName: 'crimpers',
            slug: 'crimpers',
            description: null,
            nodeTypeCode: 'category',
            imageUrl: null,
            imageAltText: null,
            displayOrder: 0,
            depth: 1,
            hasChildren: false,
            hasProducts: true,
            productCount: 10,
        );

        $filtered = $this->filter->filterTree([$this->node('tools', children: [$crimpers])]);

        self::assertSame(0, $filtered[0]->productCount);
        self::assertSame(10, $filtered[0]->children[0]->productCount);
    }

    public function test_hides_parent_when_all_descendants_are_empty(): void
    {
        $tree = [
            $this->node('industrial', children: [
                $this->node('control', children: [
                    $this->node('mining', hasProducts: false),
                    $this->node('renewable', hasProducts: false),
                ]),
            ]),
        ];

        self::assertSame([], $this->filter->filterTree($tree));
    }

    public function test_keeps_parent_with_one_visible_child_branch(): void
    {
        $tree = [
            $this->node('industrial', children: [
                $this->node('control', children: [
                    $this->node('ali-flex', hasProducts: true),
                    $this->node('mining', hasProducts: false),
                ]),
                $this->node('renewable', hasProducts: false),
            ]),
        ];

        $filtered = $this->filter->filterTree($tree);
        self::assertCount(1, $filtered);
        self::assertSame('industrial', $filtered[0]->slug);
        self::assertCount(1, $filtered[0]->children);
        self::assertSame('control', $filtered[0]->children[0]->slug);
        self::assertCount(1, $filtered[0]->children[0]->children);
        self::assertSame('ali-flex', $filtered[0]->children[0]->children[0]->slug);
    }

    public function test_treats_embedded_products_as_visible(): void
    {
        $product = new PublicCatalogueProductCard(
            publicId: 'prod-1',
            name: 'ALI FLEX',
            slug: 'ali-flex',
            internalCode: null,
            thumbnailUrl: null,
            displayOrder: 1,
            isFeatured: false,
        );
        $tree = [$this->node('control', products: [$product])];

        $filtered = $this->filter->filterTree($tree);
        self::assertCount(1, $filtered);
        self::assertTrue($filtered[0]->hasProducts);
        self::assertSame('prod-1', $filtered[0]->products[0]->publicId);
    }

    /**
     * @param list<PublicCatalogueTreeNode> $children
     * @param list<PublicCatalogueProductCard> $products
     */
    private function node(
        string $slug,
        bool $hasProducts = false,
        array $children = [],
        array $products = [],
    ): PublicCatalogueTreeNode {
        return new PublicCatalogueTreeNode(
            publicId: 'node-' . $slug,
            nodeCode: strtoupper($slug),
            nodeName: ucfirst(str_replace('-', ' ', $slug)),
            slug: $slug,
            description: null,
            nodeTypeCode: 'category',
            imageUrl: null,
            imageAltText: null,
            displayOrder: 0,
            depth: 0,
            hasChildren: $children !== [],
            hasProducts: $hasProducts,
            children: $children,
            products: $products,
        );
    }
}
