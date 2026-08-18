<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\PublicCatalogueProductCard;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTab;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode;
use TriCab\Core\Product\Application\Exception\CatalogueNotFound;
use TriCab\Core\Product\Contract\CatalogueNavigationRepository;

/**
 * Public read model for customer catalogue navigation.
 */
final class GetPublicCatalogueService
{
    public function __construct(
        private readonly CatalogueNavigationRepository $catalogue,
        private readonly RegionAwareCatalogueTreeFilter $treeFilter = new RegionAwareCatalogueTreeFilter(),
    ) {
    }

    /** @return list<PublicCatalogueTab> */
    public function listTabs(?string $regionCode = null): array
    {
        return $this->catalogue->listPublicTabs($this->normalizeRegion($regionCode));
    }

    /**
     * Aggregate payload: tabs + selected tab tree (with optional products at each node).
     *
     * @return array{
     *   tabs: list<PublicCatalogueTab>,
     *   selectedTabSlug: ?string,
     *   tree: list<PublicCatalogueTreeNode>
     * }
     */
    public function getPanel(?string $tabSlug = null, ?string $regionCode = null, bool $includeProducts = false): array
    {
        $region = $this->normalizeRegion($regionCode);
        $tabs = $this->catalogue->listPublicTabs($region);
        if ($tabs === []) {
            return ['tabs' => [], 'selectedTabSlug' => null, 'tree' => []];
        }

        $selected = $tabSlug;
        if ($selected === null || $selected === '') {
            $selected = $tabs[0]->slug;
        }

        $match = null;
        foreach ($tabs as $tab) {
            if ($tab->slug === $selected) {
                $match = $tab;
                break;
            }
        }
        if ($match === null) {
            throw CatalogueNotFound::forTab((string) $selected);
        }

        $tree = $this->applyRegionTreeFilter(
            $this->catalogue->getPublicTabTree($match->slug, $region, $includeProducts),
            $region,
        );

        return [
            'tabs' => $tabs,
            'selectedTabSlug' => $match->slug,
            'tree' => $tree,
        ];
    }

    /** @return list<PublicCatalogueTreeNode> */
    public function getTabTree(string $tabSlug, ?string $regionCode = null, bool $includeProducts = false): array
    {
        $region = $this->normalizeRegion($regionCode);
        $tree = $this->applyRegionTreeFilter(
            $this->catalogue->getPublicTabTree($tabSlug, $region, $includeProducts),
            $region,
        );
        if ($tree === [] && $this->catalogue->findTabBySlug($tabSlug) === null) {
            throw CatalogueNotFound::forTab($tabSlug);
        }

        return $tree;
    }

    public function getNodeBySlug(string $nodeSlug, ?string $tabSlug = null, ?string $regionCode = null): PublicCatalogueTreeNode
    {
        $region = $this->normalizeRegion($regionCode);
        $node = $this->catalogue->getPublicNodeBySlug($nodeSlug, $tabSlug, $region);
        if ($node === null) {
            throw CatalogueNotFound::forNode($nodeSlug);
        }

        return $this->requireVisibleNode($node, $nodeSlug, $region);
    }

    public function getNodeByPublicId(string $nodePublicId, ?string $regionCode = null): PublicCatalogueTreeNode
    {
        $region = $this->normalizeRegion($regionCode);
        $node = $this->catalogue->getPublicNodeByPublicId($nodePublicId, $region);
        if ($node === null) {
            throw CatalogueNotFound::forNode($nodePublicId);
        }

        return $this->requireVisibleNode($node, $nodePublicId, $region);
    }

    /** @return list<PublicCatalogueTreeNode> */
    public function listChildren(string $nodePublicId, ?string $regionCode = null): array
    {
        $this->getNodeByPublicId($nodePublicId, $regionCode);

        $region = $this->normalizeRegion($regionCode);

        return $this->applyRegionTreeFilter(
            $this->catalogue->listPublicChildren($nodePublicId, $region),
            $region,
        );
    }

    /** @return list<PublicCatalogueProductCard> */
    public function listProducts(string $nodePublicId, ?string $regionCode = null): array
    {
        $this->getNodeByPublicId($nodePublicId, $regionCode);

        return $this->catalogue->listPublicNodeProducts($nodePublicId, $this->normalizeRegion($regionCode));
    }

    private function normalizeRegion(?string $regionCode): ?string
    {
        if ($regionCode === null) {
            return null;
        }
        $normalized = strtoupper(trim($regionCode));

        return $normalized === '' ? null : $normalized;
    }

    /**
     * @param list<PublicCatalogueTreeNode> $tree
     * @return list<PublicCatalogueTreeNode>
     */
    private function applyRegionTreeFilter(array $tree, ?string $regionCode): array
    {
        if ($regionCode === null) {
            return $tree;
        }

        return $this->treeFilter->filterTree($tree);
    }

    private function requireVisibleNode(
        PublicCatalogueTreeNode $node,
        string $identifier,
        ?string $regionCode,
    ): PublicCatalogueTreeNode {
        if ($regionCode === null) {
            return $node;
        }

        $filtered = $this->treeFilter->filterNode($node);
        if ($filtered === null) {
            throw CatalogueNotFound::forNode($identifier);
        }

        return $filtered;
    }
}
