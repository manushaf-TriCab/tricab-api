<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode;

/**
 * Recursively removes catalogue branches that have no region-visible products
 * and no region-visible descendants.
 */
final class RegionAwareCatalogueTreeFilter
{
    /**
     * @param list<PublicCatalogueTreeNode> $nodes
     * @return list<PublicCatalogueTreeNode>
     */
    public function filterTree(array $nodes): array
    {
        $filtered = [];
        foreach ($nodes as $node) {
            $pruned = $this->filterNode($node);
            if ($pruned !== null) {
                $filtered[] = $pruned;
            }
        }

        usort(
            $filtered,
            static fn (PublicCatalogueTreeNode $a, PublicCatalogueTreeNode $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );

        return $filtered;
    }

    public function filterNode(PublicCatalogueTreeNode $node): ?PublicCatalogueTreeNode
    {
        $children = $this->filterTree($node->children);
        $hasVisibleProducts = $node->hasProducts || $node->products !== [];

        if (!$hasVisibleProducts && $children === []) {
            return null;
        }

        return new PublicCatalogueTreeNode(
            publicId: $node->publicId,
            nodeCode: $node->nodeCode,
            nodeName: $node->nodeName,
            slug: $node->slug,
            description: $node->description,
            nodeTypeCode: $node->nodeTypeCode,
            imageUrl: $node->imageUrl,
            imageAltText: $node->imageAltText,
            displayOrder: $node->displayOrder,
            depth: $node->depth,
            hasChildren: $children !== [],
            hasProducts: $hasVisibleProducts,
            children: $children,
            products: $node->products,
            parentPublicId: $node->parentPublicId,
            productThumbnailUrl: $node->productThumbnailUrl,
            productCount: $node->productCount,
        );
    }
}
