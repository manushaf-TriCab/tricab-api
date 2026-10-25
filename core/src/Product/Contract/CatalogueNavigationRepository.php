<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\CatalogueNodeProductRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeProductStyleRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeTreeNode;
use TriCab\Core\Product\Application\DTO\CatalogueNodeUsage;
use TriCab\Core\Product\Application\DTO\CatalogueProductPlacementRecord;
use TriCab\Core\Product\Application\DTO\CatalogueTabRecord;
use TriCab\Core\Product\Application\DTO\PublicCatalogueProductCard;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTab;
use TriCab\Core\Product\Application\DTO\PublicCatalogueTreeNode;

/**
 * Customer catalogue navigation persistence (separate from Family/Category/Series).
 */
interface CatalogueNavigationRepository
{
    public const MAX_DEPTH = 10;

    /** @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool} $filters
     * @return list<CatalogueTabRecord>
     */
    public function listTabsAdmin(array $filters = []): array;

    public function findTabByPublicId(string $publicId, bool $includeDeleted = false): ?CatalogueTabRecord;

    public function findTabBySlug(string $slug, bool $includeDeleted = false): ?CatalogueTabRecord;

    /**
     * @param array{
     *   tabCode: string,
     *   tabName: string,
     *   slug: string,
     *   description?: ?string,
     *   displayOrder?: int,
     *   isActive?: bool,
     *   isPublic?: bool
     * } $data
     */
    public function createTab(array $data, UserId $actorUserId): CatalogueTabRecord;

    /**
     * @param array{
     *   tabCode?: string,
     *   tabName?: string,
     *   slug?: string,
     *   description?: ?string,
     *   displayOrder?: int,
     *   isActive?: bool,
     *   isPublic?: bool
     * } $data
     */
    public function updateTab(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord;

    public function setTabActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord;

    public function softDeleteTab(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord;

    public function restoreTab(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord;

    /** @param list<string> $orderedPublicIds */
    public function reorderTabs(array $orderedPublicIds, UserId $actorUserId): void;

    public function tabCodeExists(string $tabCode, ?string $excludePublicId = null): bool;

    public function tabSlugExists(string $slug, ?string $excludePublicId = null): bool;

    /**
     * @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, tabPublicId?: ?string, parentPublicId?: ?string} $filters
     * @return list<CatalogueNodeRecord>
     */
    public function listNodesAdmin(array $filters = []): array;

    public function findNodeByPublicId(string $publicId, bool $includeDeleted = false): ?CatalogueNodeRecord;

    public function findNodeBySlug(string $tabPublicId, string $slug, ?string $parentPublicId = null, bool $includeDeleted = false): ?CatalogueNodeRecord;

    /**
     * Flat list of all nodes in a tab (admin tree build).
     *
     * @return list<CatalogueNodeRecord>
     */
    public function listNodesForTab(string $tabPublicId, bool $includeDeleted = false): array;

    public function buildAdminTree(string $tabPublicId, bool $includeDeleted = false): CatalogueNodeTreeNode|null;

    /**
     * @return list<CatalogueNodeTreeNode>
     */
    public function buildAdminForest(string $tabPublicId, bool $includeDeleted = false): array;

    /**
     * @param array{
     *   tabPublicId: string,
     *   parentPublicId?: ?string,
     *   nodeCode: string,
     *   nodeName: string,
     *   slug: string,
     *   description?: ?string,
     *   nodeTypeCode?: string,
     *   imageUrl?: ?string,
     *   imageAltText?: ?string,
     *   imageMediaPublicId?: ?string,
     *   displayOrder?: int,
     *   isActive?: bool,
     *   isPublic?: bool,
     *   seoTitle?: ?string,
     *   seoDescription?: ?string,
     *   regionCodes?: list<string>
     * } $data
     */
    public function createNode(array $data, UserId $actorUserId): CatalogueNodeRecord;

    /**
     * @param array{
     *   parentPublicId?: ?string,
     *   nodeCode?: string,
     *   nodeName?: string,
     *   slug?: string,
     *   description?: ?string,
     *   nodeTypeCode?: string,
     *   imageUrl?: ?string,
     *   imageAltText?: ?string,
     *   imageMediaPublicId?: ?string,
     *   displayOrder?: int,
     *   isActive?: bool,
     *   isPublic?: bool,
     *   seoTitle?: ?string,
     *   seoDescription?: ?string,
     *   regionCodes?: list<string>|null
     * } $data
     */
    public function updateNode(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord;

    public function moveNode(string $publicId, ?string $newParentPublicId, string $expectedRowVersion, UserId $actorUserId, ?int $displayOrder = null): CatalogueNodeRecord;

    public function setNodeActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord;

    public function softDeleteNode(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord;

    public function restoreNode(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord;

    /**
     * @param list<string> $orderedPublicIds siblings under the same parent (null = roots of tab)
     */
    public function reorderNodes(string $tabPublicId, ?string $parentPublicId, array $orderedPublicIds, UserId $actorUserId): void;

    public function nodeCodeExists(string $tabPublicId, string $nodeCode, ?string $excludePublicId = null): bool;

    public function siblingSlugExists(string $tabPublicId, ?string $parentPublicId, string $slug, ?string $excludePublicId = null): bool;

    public function nodeDepth(string $publicId): int;

    public function wouldCreateCycle(string $nodePublicId, ?string $newParentPublicId): bool;

    public function getNodeUsage(string $publicId): CatalogueNodeUsage;

    /** @return list<CatalogueNodeProductRecord> */
    public function listNodeProducts(string $nodePublicId, bool $activeOnly = false): array;

    /** @return list<CatalogueNodeProductStyleRecord> */
    public function listNodeProductStyles(string $nodePublicId, bool $activeOnly = false): array;

    /**
     * listNodeProducts for many nodes at once, keyed by the node ids as passed in
     * (every requested id is present, possibly with an empty list).
     *
     * @param list<string> $nodePublicIds
     * @return array<string, list<CatalogueNodeProductRecord>>
     */
    public function listNodeProductsForNodes(array $nodePublicIds, bool $activeOnly = false): array;

    /**
     * listNodeProductStyles for many nodes at once, keyed like listNodeProductsForNodes.
     *
     * @param list<string> $nodePublicIds
     * @return array<string, list<CatalogueNodeProductStyleRecord>>
     */
    public function listNodeProductStylesForNodes(array $nodePublicIds, bool $activeOnly = false): array;

    public function addNodeProductStyle(
        string $nodePublicId,
        string $productStylePublicId,
        int $displayOrder,
        UserId $actorUserId,
    ): void;

    public function deactivateNodeProductStyle(
        string $nodePublicId,
        string $productStylePublicId,
        UserId $actorUserId,
    ): void;

    /** @return list<string> CatalogueNode PublicIds with at least one active style link */
    public function listNodePublicIdsWithActiveStyleLinks(): array;

    /**
     * Replace all product placements for a node.
     *
     * @param list<array{productPublicId: string, displayOrder?: int, isFeatured?: bool, isActive?: bool}> $placements
     */
    public function replaceNodeProducts(string $nodePublicId, array $placements, UserId $actorUserId): void;

    /** @return list<CatalogueProductPlacementRecord> */
    public function listProductPlacements(string $productPublicId): array;

    /**
     * @param list<array{nodePublicId: string, displayOrder?: int, isFeatured?: bool, isActive?: bool}> $placements
     */
    public function replaceProductPlacements(string $productPublicId, array $placements, UserId $actorUserId): void;

    /** @return list<PublicCatalogueTab> */
    public function listPublicTabs(?string $regionCode = null): array;

    /**
     * @return list<PublicCatalogueTreeNode>
     */
    public function getPublicTabTree(string $tabSlug, ?string $regionCode = null, bool $includeProducts = false): array;

    public function getPublicNodeBySlug(string $nodeSlug, ?string $tabSlug = null, ?string $regionCode = null): ?PublicCatalogueTreeNode;

    public function getPublicNodeByPublicId(string $nodePublicId, ?string $regionCode = null): ?PublicCatalogueTreeNode;

    /** @return list<PublicCatalogueTreeNode> */
    public function listPublicChildren(string $nodePublicId, ?string $regionCode = null): array;

    /** @return list<PublicCatalogueProductCard> */
    public function listPublicNodeProducts(string $nodePublicId, ?string $regionCode = null): array;

    /**
     * Preferred public product-card thumbnail URLs for the given product public IDs.
     * One batched lookup — not a customer-view request per card.
     *
     * @param list<string> $productPublicIds
     * @return array<string, string> productPublicId => url
     */
    public function listPublicProductThumbnailUrls(array $productPublicIds): array;

    /**
     * Admin search across tabs/nodes.
     *
     * @return array{tabs: list<CatalogueTabRecord>, nodes: list<CatalogueNodeRecord>}
     */
    public function searchAdmin(string $query, int $limit = 50): array;
}
