<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
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
use TriCab\Core\Product\Application\DTO\ProductMediaSummary;
use TriCab\Core\Product\Application\Exception\CatalogueConcurrencyConflict;
use TriCab\Core\Product\Application\Service\ProductThumbnailImageResolver;
use TriCab\Core\Product\Contract\CatalogueNavigationRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class InMemoryCatalogueNavigationRepository implements CatalogueNavigationRepository
{
    /** @var array<string, array<string, mixed>> */
    private array $tabs = [];

    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $nodeProducts = [];

    /** @var array<string, list<array<string, mixed>>> */
    private array $nodeProductStyles = [];

    /** @var array<string, list<string>> */
    private array $nodeRegions = [];

    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listTabsAdmin(array $filters = []): array
    {
        $out = [];
        foreach ($this->tabs as $row) {
            if (!$this->matchesTabFilters($row, $filters)) {
                continue;
            }
            $out[] = $this->toTabRecord($row);
        }
        usort(
            $out,
            static fn (CatalogueTabRecord $a, CatalogueTabRecord $b): int =>
                [$a->displayOrder, $a->tabName] <=> [$b->displayOrder, $b->tabName],
        );

        return $out;
    }

    public function findTabByPublicId(string $publicId, bool $includeDeleted = false): ?CatalogueTabRecord
    {
        $row = $this->tabs[$publicId] ?? null;
        if ($row === null) {
            return null;
        }
        if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
            return null;
        }

        return $this->toTabRecord($row);
    }

    public function findTabBySlug(string $slug, bool $includeDeleted = false): ?CatalogueTabRecord
    {
        $needle = strtolower(trim($slug));
        foreach ($this->tabs as $row) {
            if (strtolower((string) ($row['slug'] ?? '')) !== $needle) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                return null;
            }

            return $this->toTabRecord($row);
        }

        return null;
    }

    public function createTab(array $data, UserId $actorUserId): CatalogueTabRecord
    {
        unset($actorUserId);
        $now = $this->store->nowUtc();
        $publicId = $this->store->newPublicId();
        $row = [
            'publicId' => $publicId,
            'tabCode' => strtoupper(trim((string) $data['tabCode'])),
            'tabName' => trim((string) $data['tabName']),
            'description' => $data['description'] ?? null,
            'slug' => strtolower(trim((string) $data['slug'])),
            'displayOrder' => (int) ($data['displayOrder'] ?? 0),
            'isActive' => (bool) ($data['isActive'] ?? true),
            'isPublic' => (bool) ($data['isPublic'] ?? true),
            'deletedAtUtc' => null,
            'rowVersion' => $this->store->nextRowVersion()->toBase64(),
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
        ];
        $this->tabs[$publicId] = $row;

        return $this->toTabRecord($row);
    }

    public function updateTab(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord
    {
        return $this->mutateTab($publicId, $expectedRowVersion, $actorUserId, $data);
    }

    public function setTabActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord
    {
        return $this->mutateTab($publicId, $expectedRowVersion, $actorUserId, ['isActive' => $isActive]);
    }

    public function softDeleteTab(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord
    {
        return $this->mutateTab($publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => $this->store->nowUtc(),
            'isActive' => false,
        ]);
    }

    public function restoreTab(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueTabRecord
    {
        return $this->mutateTab($publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => null,
            'isActive' => true,
        ], true);
    }

    public function reorderTabs(array $orderedPublicIds, UserId $actorUserId): void
    {
        unset($actorUserId);
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            if (!isset($this->tabs[$publicId])) {
                continue;
            }
            $this->tabs[$publicId]['displayOrder'] = $order;
            $this->tabs[$publicId]['rowVersion'] = $this->store->nextRowVersion()->toBase64();
            $this->tabs[$publicId]['updatedAtUtc'] = $this->store->nowUtc();
            $order += 10;
        }
    }

    public function tabCodeExists(string $tabCode, ?string $excludePublicId = null): bool
    {
        $needle = strtoupper(trim($tabCode));
        foreach ($this->tabs as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if ($excludePublicId !== null && ($row['publicId'] ?? null) === $excludePublicId) {
                continue;
            }
            if (strtoupper((string) ($row['tabCode'] ?? '')) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function tabSlugExists(string $slug, ?string $excludePublicId = null): bool
    {
        $needle = strtolower(trim($slug));
        foreach ($this->tabs as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if ($excludePublicId !== null && ($row['publicId'] ?? null) === $excludePublicId) {
                continue;
            }
            if (strtolower((string) ($row['slug'] ?? '')) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function listNodesAdmin(array $filters = []): array
    {
        $out = [];
        foreach ($this->nodes as $row) {
            if (!$this->matchesNodeFilters($row, $filters)) {
                continue;
            }
            $out[] = $this->toNodeRecord($row);
        }
        usort(
            $out,
            static fn (CatalogueNodeRecord $a, CatalogueNodeRecord $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );

        return $out;
    }

    public function findNodeByPublicId(string $publicId, bool $includeDeleted = false): ?CatalogueNodeRecord
    {
        $row = $this->nodes[$publicId] ?? null;
        if ($row === null) {
            return null;
        }
        if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
            return null;
        }

        return $this->toNodeRecord($row);
    }

    public function findNodeBySlug(string $tabPublicId, string $slug, ?string $parentPublicId = null, bool $includeDeleted = false): ?CatalogueNodeRecord
    {
        $needle = strtolower(trim($slug));
        foreach ($this->nodes as $row) {
            if (($row['tabPublicId'] ?? null) !== $tabPublicId) {
                continue;
            }
            if (($row['parentPublicId'] ?? null) !== $parentPublicId) {
                continue;
            }
            if (strtolower((string) ($row['slug'] ?? '')) !== $needle) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                return null;
            }

            return $this->toNodeRecord($row);
        }

        return null;
    }

    public function listNodesForTab(string $tabPublicId, bool $includeDeleted = false): array
    {
        $out = [];
        foreach ($this->nodes as $row) {
            if (($row['tabPublicId'] ?? null) !== $tabPublicId) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            $out[] = $this->toNodeRecord($row);
        }

        return $out;
    }

    public function buildAdminTree(string $tabPublicId, bool $includeDeleted = false): CatalogueNodeTreeNode|null
    {
        $roots = [];
        foreach ($this->nodes as $row) {
            if (($row['tabPublicId'] ?? null) !== $tabPublicId) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if (($row['parentPublicId'] ?? null) !== null) {
                continue;
            }
            $roots[] = $row;
        }
        if (count($roots) !== 1) {
            return null;
        }

        return $this->buildAdminTreeNode($roots[0], $includeDeleted, 0);
    }

    public function buildAdminForest(string $tabPublicId, bool $includeDeleted = false): array
    {
        $forest = [];
        foreach ($this->nodes as $row) {
            if (($row['tabPublicId'] ?? null) !== $tabPublicId) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if (($row['parentPublicId'] ?? null) !== null) {
                continue;
            }
            $forest[] = $this->buildAdminTreeNode($row, $includeDeleted, 0);
        }
        usort(
            $forest,
            static fn (CatalogueNodeTreeNode $a, CatalogueNodeTreeNode $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );

        return $forest;
    }

    public function createNode(array $data, UserId $actorUserId): CatalogueNodeRecord
    {
        unset($actorUserId);
        $now = $this->store->nowUtc();
        $publicId = $this->store->newPublicId();
        $regionCodes = $this->normalizeRegionCodes($data['regionCodes'] ?? []);
        $row = [
            'publicId' => $publicId,
            'tabPublicId' => (string) $data['tabPublicId'],
            'parentPublicId' => $data['parentPublicId'] ?? null,
            'nodeCode' => strtoupper(trim((string) $data['nodeCode'])),
            'nodeName' => trim((string) $data['nodeName']),
            'slug' => strtolower(trim((string) $data['slug'])),
            'description' => $data['description'] ?? null,
            'nodeTypeCode' => strtolower(trim((string) ($data['nodeTypeCode'] ?? 'category'))),
            'imageUrl' => $data['imageUrl'] ?? null,
            'imageAltText' => $data['imageAltText'] ?? null,
            'imageMediaPublicId' => $data['imageMediaPublicId'] ?? null,
            'displayOrder' => (int) ($data['displayOrder'] ?? 0),
            'isActive' => (bool) ($data['isActive'] ?? true),
            'isPublic' => (bool) ($data['isPublic'] ?? true),
            'seoTitle' => $data['seoTitle'] ?? null,
            'seoDescription' => $data['seoDescription'] ?? null,
            'deletedAtUtc' => null,
            'rowVersion' => $this->store->nextRowVersion()->toBase64(),
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
        ];
        $this->nodes[$publicId] = $row;
        $this->nodeRegions[$publicId] = $regionCodes;
        $this->nodeProducts[$publicId] = [];

        return $this->toNodeRecord($row);
    }

    public function updateNode(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord
    {
        if (array_key_exists('regionCodes', $data) && $data['regionCodes'] !== null) {
            $this->nodeRegions[$publicId] = $this->normalizeRegionCodes($data['regionCodes']);
            unset($data['regionCodes']);
        }

        return $this->mutateNode($publicId, $expectedRowVersion, $actorUserId, $data);
    }

    public function moveNode(string $publicId, ?string $newParentPublicId, string $expectedRowVersion, UserId $actorUserId, ?int $displayOrder = null): CatalogueNodeRecord
    {
        $data = ['parentPublicId' => $newParentPublicId];
        if ($displayOrder !== null) {
            $data['displayOrder'] = $displayOrder;
        }

        return $this->mutateNode($publicId, $expectedRowVersion, $actorUserId, $data);
    }

    public function setNodeActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord
    {
        return $this->mutateNode($publicId, $expectedRowVersion, $actorUserId, ['isActive' => $isActive]);
    }

    public function softDeleteNode(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord
    {
        return $this->mutateNode($publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => $this->store->nowUtc(),
            'isActive' => false,
        ]);
    }

    public function restoreNode(string $publicId, string $expectedRowVersion, UserId $actorUserId): CatalogueNodeRecord
    {
        return $this->mutateNode($publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => null,
            'isActive' => true,
        ], true);
    }

    public function reorderNodes(string $tabPublicId, ?string $parentPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        unset($actorUserId);
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $row = $this->nodes[$publicId] ?? null;
            if ($row === null) {
                continue;
            }
            if (($row['tabPublicId'] ?? null) !== $tabPublicId) {
                continue;
            }
            if (($row['parentPublicId'] ?? null) !== $parentPublicId) {
                continue;
            }
            $row['displayOrder'] = $order;
            $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
            $row['updatedAtUtc'] = $this->store->nowUtc();
            $this->nodes[$publicId] = $row;
            $order += 10;
        }
    }

    public function nodeCodeExists(string $tabPublicId, string $nodeCode, ?string $excludePublicId = null): bool
    {
        $needle = strtoupper(trim($nodeCode));
        foreach ($this->nodes as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if (($row['tabPublicId'] ?? null) !== $tabPublicId) {
                continue;
            }
            if ($excludePublicId !== null && ($row['publicId'] ?? null) === $excludePublicId) {
                continue;
            }
            if (strtoupper((string) ($row['nodeCode'] ?? '')) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function siblingSlugExists(string $tabPublicId, ?string $parentPublicId, string $slug, ?string $excludePublicId = null): bool
    {
        $needle = strtolower(trim($slug));
        foreach ($this->nodes as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if (($row['tabPublicId'] ?? null) !== $tabPublicId) {
                continue;
            }
            if (($row['parentPublicId'] ?? null) !== $parentPublicId) {
                continue;
            }
            if ($excludePublicId !== null && ($row['publicId'] ?? null) === $excludePublicId) {
                continue;
            }
            if (strtolower((string) ($row['slug'] ?? '')) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function nodeDepth(string $publicId): int
    {
        $depth = 0;
        $current = $this->nodes[$publicId] ?? null;
        while ($current !== null && ($current['parentPublicId'] ?? null) !== null) {
            $depth++;
            $parentId = (string) $current['parentPublicId'];
            $current = $this->nodes[$parentId] ?? null;
        }

        return $depth;
    }

    public function wouldCreateCycle(string $nodePublicId, ?string $newParentPublicId): bool
    {
        if ($newParentPublicId === null) {
            return false;
        }
        if ($newParentPublicId === $nodePublicId) {
            return true;
        }
        $current = $newParentPublicId;
        while ($current !== null) {
            if ($current === $nodePublicId) {
                return true;
            }
            $row = $this->nodes[$current] ?? null;
            $current = $row !== null ? ($row['parentPublicId'] ?? null) : null;
        }

        return false;
    }

    public function getNodeUsage(string $publicId): CatalogueNodeUsage
    {
        $childCount = 0;
        $activeChildCount = 0;
        foreach ($this->nodes as $row) {
            if (($row['parentPublicId'] ?? null) !== $publicId) {
                continue;
            }
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            $childCount++;
            if ($row['isActive'] ?? true) {
                $activeChildCount++;
            }
        }
        $productCount = count($this->nodeProducts[$publicId] ?? []);
        $regionCount = count($this->nodeRegions[$publicId] ?? []);
        $summary = sprintf('%d child categories, %d products', $childCount, $productCount);

        return new CatalogueNodeUsage(
            publicId: $publicId,
            childCount: $childCount,
            activeChildCount: $activeChildCount,
            productCount: $productCount,
            regionCount: $regionCount,
            summary: $summary,
        );
    }

    public function listNodeProducts(string $nodePublicId, bool $activeOnly = false): array
    {
        $out = [];
        foreach ($this->nodeProducts[$nodePublicId] ?? [] as $placement) {
            if ($activeOnly && !($placement['isActive'] ?? true)) {
                continue;
            }
            $out[] = $this->toNodeProductRecord($placement);
        }
        usort(
            $out,
            static fn (CatalogueNodeProductRecord $a, CatalogueNodeProductRecord $b): int =>
                [$a->displayOrder, $a->productName] <=> [$b->displayOrder, $b->productName],
        );

        return $out;
    }

    public function listNodeProductStyles(string $nodePublicId, bool $activeOnly = false): array
    {
        $out = [];
        foreach ($this->nodeProductStyles[$nodePublicId] ?? [] as $link) {
            if ($activeOnly && !($link['isActive'] ?? true)) {
                continue;
            }
            $out[] = new CatalogueNodeProductStyleRecord(
                productStylePublicId: (string) ($link['productStylePublicId'] ?? ''),
                styleCode: (string) ($link['styleCode'] ?? ''),
                displayName: (string) ($link['displayName'] ?? ''),
                displayOrder: (int) ($link['displayOrder'] ?? 0),
                isActive: (bool) ($link['isActive'] ?? true),
                childStyleCount: (int) ($link['childStyleCount'] ?? 0),
                assignedProductCount: (int) ($link['assignedProductCount'] ?? 0),
            );
        }
        usort(
            $out,
            static fn (CatalogueNodeProductStyleRecord $a, CatalogueNodeProductStyleRecord $b): int =>
                [$a->displayOrder, $a->styleCode] <=> [$b->displayOrder, $b->styleCode],
        );

        return $out;
    }

    public function addNodeProductStyle(
        string $nodePublicId,
        string $productStylePublicId,
        int $displayOrder,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        $links = $this->nodeProductStyles[$nodePublicId] ?? [];
        foreach ($links as &$link) {
            if (($link['productStylePublicId'] ?? '') === $productStylePublicId) {
                $link['isActive'] = true;
                $link['displayOrder'] = $displayOrder;

                return;
            }
        }
        unset($link);
        $links[] = [
            'productStylePublicId' => $productStylePublicId,
            'styleCode' => $productStylePublicId,
            'displayName' => $productStylePublicId,
            'displayOrder' => $displayOrder,
            'isActive' => true,
            'childStyleCount' => 0,
            'assignedProductCount' => 0,
        ];
        $this->nodeProductStyles[$nodePublicId] = $links;
    }

    public function deactivateNodeProductStyle(
        string $nodePublicId,
        string $productStylePublicId,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        foreach ($this->nodeProductStyles[$nodePublicId] ?? [] as &$link) {
            if (($link['productStylePublicId'] ?? '') === $productStylePublicId) {
                $link['isActive'] = false;
            }
        }
        unset($link);
    }

    public function listNodePublicIdsWithActiveStyleLinks(): array
    {
        $out = [];
        foreach ($this->nodeProductStyles as $nodePublicId => $links) {
            foreach ($links as $link) {
                if ($link['isActive'] ?? true) {
                    $out[] = $nodePublicId;
                    break;
                }
            }
        }

        return $out;
    }

    public function replaceNodeProducts(string $nodePublicId, array $placements, UserId $actorUserId): void
    {
        unset($actorUserId);
        $normalized = [];
        $order = 10;
        foreach ($placements as $placement) {
            $productPublicId = (string) ($placement['productPublicId'] ?? '');
            if ($productPublicId === '') {
                continue;
            }
            $normalized[] = [
                'productPublicId' => $productPublicId,
                'displayOrder' => (int) ($placement['displayOrder'] ?? $order),
                'isFeatured' => (bool) ($placement['isFeatured'] ?? false),
                'isActive' => (bool) ($placement['isActive'] ?? true),
            ];
            $order += 10;
        }
        $this->nodeProducts[$nodePublicId] = $normalized;
    }

    public function listProductPlacements(string $productPublicId): array
    {
        $out = [];
        foreach ($this->nodeProducts as $nodePublicId => $placements) {
            foreach ($placements as $placement) {
                if (($placement['productPublicId'] ?? null) !== $productPublicId) {
                    continue;
                }
                $node = $this->nodes[$nodePublicId] ?? null;
                if ($node === null) {
                    continue;
                }
                $tab = $this->tabs[(string) $node['tabPublicId']] ?? null;
                if ($tab === null) {
                    continue;
                }
                $out[] = new CatalogueProductPlacementRecord(
                    nodePublicId: $nodePublicId,
                    nodeName: AzureSqlValueMapper::stringValue($node['nodeName'] ?? ''),
                    nodeSlug: AzureSqlValueMapper::stringValue($node['slug'] ?? ''),
                    tabPublicId: AzureSqlValueMapper::stringValue($node['tabPublicId'] ?? ''),
                    tabName: AzureSqlValueMapper::stringValue($tab['tabName'] ?? ''),
                    tabSlug: AzureSqlValueMapper::stringValue($tab['slug'] ?? ''),
                    displayOrder: AzureSqlValueMapper::intValue($placement['displayOrder'] ?? 0),
                    isFeatured: AzureSqlValueMapper::boolValue($placement['isFeatured'] ?? false),
                    isActive: AzureSqlValueMapper::boolValue($placement['isActive'] ?? true),
                    breadcrumbNames: $this->breadcrumbNames($nodePublicId),
                );
            }
        }
        usort(
            $out,
            static fn (CatalogueProductPlacementRecord $a, CatalogueProductPlacementRecord $b): int =>
                [$a->tabName, $a->displayOrder, $a->nodeName] <=> [$b->tabName, $b->displayOrder, $b->nodeName],
        );

        return $out;
    }

    public function replaceProductPlacements(string $productPublicId, array $placements, UserId $actorUserId): void
    {
        unset($actorUserId);
        foreach ($this->nodeProducts as $nodePublicId => $existing) {
            $this->nodeProducts[$nodePublicId] = array_values(array_filter(
                $existing,
                static fn (array $row): bool => ($row['productPublicId'] ?? null) !== $productPublicId,
            ));
        }
        foreach ($placements as $placement) {
            $nodePublicId = (string) ($placement['nodePublicId'] ?? '');
            if ($nodePublicId === '') {
                continue;
            }
            $this->nodeProducts[$nodePublicId] ??= [];
            $this->nodeProducts[$nodePublicId][] = [
                'productPublicId' => $productPublicId,
                'displayOrder' => (int) ($placement['displayOrder'] ?? 0),
                'isFeatured' => (bool) ($placement['isFeatured'] ?? false),
                'isActive' => (bool) ($placement['isActive'] ?? true),
            ];
        }
    }

    public function listPublicTabs(?string $regionCode = null): array
    {
        $out = [];
        foreach ($this->tabs as $row) {
            if (!$this->isPublicTabRow($row)) {
                continue;
            }
            $out[] = new PublicCatalogueTab(
                publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
                tabCode: AzureSqlValueMapper::stringValue($row['tabCode'] ?? ''),
                tabName: AzureSqlValueMapper::stringValue($row['tabName'] ?? ''),
                description: AzureSqlValueMapper::nullableString($row['description'] ?? null),
                slug: AzureSqlValueMapper::stringValue($row['slug'] ?? ''),
                displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            );
        }
        usort(
            $out,
            static fn (PublicCatalogueTab $a, PublicCatalogueTab $b): int =>
                [$a->displayOrder, $a->tabName] <=> [$b->displayOrder, $b->tabName],
        );

        return $out;
    }

    public function getPublicTabTree(string $tabSlug, ?string $regionCode = null, bool $includeProducts = false): array
    {
        $tab = $this->findTabBySlug($tabSlug);
        if ($tab === null || !$tab->isActive || !$tab->isPublic || $tab->isDeleted) {
            return [];
        }

        $forest = [];
        foreach ($this->nodes as $row) {
            if (($row['tabPublicId'] ?? null) !== $tab->publicId) {
                continue;
            }
            if (!$this->isPublicNodeRow($row, $regionCode)) {
                continue;
            }
            if (($row['parentPublicId'] ?? null) !== null) {
                continue;
            }
            $forest[] = $this->buildPublicTreeNode($row, $regionCode, $includeProducts, 0);
        }
        usort(
            $forest,
            static fn (PublicCatalogueTreeNode $a, PublicCatalogueTreeNode $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );

        return $forest;
    }

    public function getPublicNodeBySlug(string $nodeSlug, ?string $tabSlug = null, ?string $regionCode = null): ?PublicCatalogueTreeNode
    {
        $needle = strtolower(trim($nodeSlug));
        $tabPublicId = null;
        if ($tabSlug !== null && trim($tabSlug) !== '') {
            $tab = $this->findTabBySlug($tabSlug);
            $tabPublicId = $tab?->publicId;
        }
        foreach ($this->nodes as $row) {
            if ($tabPublicId !== null && ($row['tabPublicId'] ?? null) !== $tabPublicId) {
                continue;
            }
            if (strtolower((string) ($row['slug'] ?? '')) !== $needle) {
                continue;
            }
            if (!$this->isPublicNodeRow($row, $regionCode)) {
                return null;
            }

            return $this->buildRequestedPublicNode($row, $regionCode);
        }

        return null;
    }

    public function getPublicNodeByPublicId(string $nodePublicId, ?string $regionCode = null): ?PublicCatalogueTreeNode
    {
        $row = $this->nodes[$nodePublicId] ?? null;
        if ($row === null || !$this->isPublicNodeRow($row, $regionCode)) {
            return null;
        }

        return $this->buildRequestedPublicNode($row, $regionCode);
    }

    public function listPublicChildren(string $nodePublicId, ?string $regionCode = null): array
    {
        $out = [];
        foreach ($this->nodes as $row) {
            if (($row['parentPublicId'] ?? null) !== $nodePublicId) {
                continue;
            }
            if (!$this->isPublicNodeRow($row, $regionCode)) {
                continue;
            }
            $out[] = $this->buildPublicTreeNode($row, $regionCode, false, $this->nodeDepth((string) $row['publicId']));
        }
        usort(
            $out,
            static fn (PublicCatalogueTreeNode $a, PublicCatalogueTreeNode $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );

        return $out;
    }

    public function listPublicNodeProducts(string $nodePublicId, ?string $regionCode = null): array
    {
        $out = [];
        foreach ($this->nodeProducts[$nodePublicId] ?? [] as $placement) {
            if (!($placement['isActive'] ?? true)) {
                continue;
            }
            $card = $this->toPublicProductCard($placement, $regionCode);
            if ($card !== null) {
                $out[] = $card;
            }
        }
        usort(
            $out,
            static fn (PublicCatalogueProductCard $a, PublicCatalogueProductCard $b): int =>
                [$a->displayOrder, $a->name] <=> [$b->displayOrder, $b->name],
        );

        return $out;
    }

    public function searchAdmin(string $query, int $limit = 50): array
    {
        $needle = strtolower(trim($query));
        $tabs = [];
        $nodes = [];
        foreach ($this->tabs as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            $hay = strtolower((string) ($row['tabName'] ?? '') . ' ' . (string) ($row['tabCode'] ?? '') . ' ' . (string) ($row['slug'] ?? ''));
            if (str_contains($hay, $needle)) {
                $tabs[] = $this->toTabRecord($row);
            }
        }
        foreach ($this->nodes as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            $hay = strtolower((string) ($row['nodeName'] ?? '') . ' ' . (string) ($row['nodeCode'] ?? '') . ' ' . (string) ($row['slug'] ?? ''));
            if (str_contains($hay, $needle)) {
                $nodes[] = $this->toNodeRecord($row);
            }
        }

        return [
            'tabs' => array_slice($tabs, 0, $limit),
            'nodes' => array_slice($nodes, 0, $limit),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $filters
     */
    private function matchesTabFilters(array $row, array $filters): bool
    {
        $includeDeleted = (bool) ($filters['includeDeleted'] ?? false);
        if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
            return false;
        }
        $activeOnly = $filters['activeOnly'] ?? null;
        if ($activeOnly === true && !($row['isActive'] ?? true)) {
            return false;
        }
        if ($activeOnly === false && ($row['isActive'] ?? true)) {
            return false;
        }
        $search = isset($filters['search']) ? strtolower(trim((string) $filters['search'])) : '';
        if ($search !== '') {
            $hay = strtolower((string) ($row['tabName'] ?? '') . ' ' . (string) ($row['tabCode'] ?? '') . ' ' . (string) ($row['slug'] ?? ''));
            if (!str_contains($hay, $search)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $filters
     */
    private function matchesNodeFilters(array $row, array $filters): bool
    {
        $includeDeleted = (bool) ($filters['includeDeleted'] ?? false);
        if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
            return false;
        }
        $activeOnly = $filters['activeOnly'] ?? null;
        if ($activeOnly === true && !($row['isActive'] ?? true)) {
            return false;
        }
        if ($activeOnly === false && ($row['isActive'] ?? true)) {
            return false;
        }
        if (isset($filters['tabPublicId']) && is_string($filters['tabPublicId']) && ($row['tabPublicId'] ?? null) !== $filters['tabPublicId']) {
            return false;
        }
        if (array_key_exists('parentPublicId', $filters) && ($row['parentPublicId'] ?? null) !== $filters['parentPublicId']) {
            return false;
        }
        $search = isset($filters['search']) ? strtolower(trim((string) $filters['search'])) : '';
        if ($search !== '') {
            $hay = strtolower((string) ($row['nodeName'] ?? '') . ' ' . (string) ($row['nodeCode'] ?? '') . ' ' . (string) ($row['slug'] ?? ''));
            if (!str_contains($hay, $search)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mutateTab(
        string $publicId,
        string $expectedRowVersion,
        UserId $actorUserId,
        array $data,
        bool $includeDeleted = false,
    ): CatalogueTabRecord {
        unset($actorUserId);
        $row = $this->tabs[$publicId] ?? null;
        if ($row === null || (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null)) {
            throw CatalogueConcurrencyConflict::forTab();
        }
        if ((string) ($row['rowVersion'] ?? '') !== $expectedRowVersion) {
            throw CatalogueConcurrencyConflict::forTab();
        }
        foreach ($data as $key => $value) {
            if ($key === 'tabCode' && is_string($value)) {
                $row[$key] = strtoupper(trim($value));
                continue;
            }
            if ($key === 'slug' && is_string($value)) {
                $row[$key] = strtolower(trim($value));
                continue;
            }
            $row[$key] = $value;
        }
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $this->tabs[$publicId] = $row;

        return $this->toTabRecord($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mutateNode(
        string $publicId,
        string $expectedRowVersion,
        UserId $actorUserId,
        array $data,
        bool $includeDeleted = false,
    ): CatalogueNodeRecord {
        unset($actorUserId);
        $row = $this->nodes[$publicId] ?? null;
        if ($row === null || (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null)) {
            throw CatalogueConcurrencyConflict::forNode();
        }
        if ((string) ($row['rowVersion'] ?? '') !== $expectedRowVersion) {
            throw CatalogueConcurrencyConflict::forNode();
        }
        foreach ($data as $key => $value) {
            if ($key === 'nodeCode' && is_string($value)) {
                $row[$key] = strtoupper(trim($value));
                continue;
            }
            if ($key === 'slug' && is_string($value)) {
                $row[$key] = strtolower(trim($value));
                continue;
            }
            if ($key === 'nodeTypeCode' && is_string($value)) {
                $row[$key] = strtolower(trim($value));
                continue;
            }
            $row[$key] = $value;
        }
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $this->nodes[$publicId] = $row;

        return $this->toNodeRecord($row);
    }

    /** @param array<string, mixed> $row */
    private function toTabRecord(array $row): CatalogueTabRecord
    {
        $rootCount = 0;
        foreach ($this->nodes as $node) {
            if (($node['tabPublicId'] ?? null) !== ($row['publicId'] ?? null)) {
                continue;
            }
            if (($node['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if (($node['parentPublicId'] ?? null) === null) {
                $rootCount++;
            }
        }

        return new CatalogueTabRecord(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            tabCode: AzureSqlValueMapper::stringValue($row['tabCode'] ?? ''),
            tabName: AzureSqlValueMapper::stringValue($row['tabName'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['description'] ?? null),
            slug: AzureSqlValueMapper::stringValue($row['slug'] ?? ''),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['isActive'] ?? true),
            isPublic: AzureSqlValueMapper::boolValue($row['isPublic'] ?? true),
            isDeleted: ($row['deletedAtUtc'] ?? null) !== null,
            rowVersion: AzureSqlValueMapper::stringValue($row['rowVersion'] ?? 'AAAAAAAAAAA='),
            createdAtUtc: AzureSqlValueMapper::nullableString($row['createdAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['updatedAtUtc'] ?? null),
            rootNodeCount: $rootCount,
        );
    }

    /** @param array<string, mixed> $row */
    private function toNodeRecord(array $row): CatalogueNodeRecord
    {
        $publicId = AzureSqlValueMapper::stringValue($row['publicId'] ?? '');
        $tab = $this->tabs[(string) ($row['tabPublicId'] ?? '')] ?? null;
        $parentPublicId = AzureSqlValueMapper::nullableString($row['parentPublicId'] ?? null);
        $parentName = null;
        if ($parentPublicId !== null) {
            $parent = $this->nodes[$parentPublicId] ?? null;
            $parentName = $parent !== null ? AzureSqlValueMapper::nullableString($parent['nodeName'] ?? null) : null;
        }
        $childCount = 0;
        foreach ($this->nodes as $child) {
            if (($child['parentPublicId'] ?? null) === $publicId && ($child['deletedAtUtc'] ?? null) === null) {
                $childCount++;
            }
        }

        return new CatalogueNodeRecord(
            publicId: $publicId,
            tabPublicId: AzureSqlValueMapper::stringValue($row['tabPublicId'] ?? ''),
            tabSlug: AzureSqlValueMapper::stringValue($tab['slug'] ?? ''),
            tabName: AzureSqlValueMapper::stringValue($tab['tabName'] ?? ''),
            parentPublicId: $parentPublicId,
            parentName: $parentName,
            nodeCode: AzureSqlValueMapper::stringValue($row['nodeCode'] ?? ''),
            nodeName: AzureSqlValueMapper::stringValue($row['nodeName'] ?? ''),
            slug: AzureSqlValueMapper::stringValue($row['slug'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['description'] ?? null),
            nodeTypeCode: AzureSqlValueMapper::stringValue($row['nodeTypeCode'] ?? 'category'),
            imageUrl: AzureSqlValueMapper::nullableString($row['imageUrl'] ?? null),
            imageAltText: AzureSqlValueMapper::nullableString($row['imageAltText'] ?? null),
            imageMediaPublicId: AzureSqlValueMapper::nullableString($row['imageMediaPublicId'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['isActive'] ?? true),
            isPublic: AzureSqlValueMapper::boolValue($row['isPublic'] ?? true),
            isDeleted: ($row['deletedAtUtc'] ?? null) !== null,
            seoTitle: AzureSqlValueMapper::nullableString($row['seoTitle'] ?? null),
            seoDescription: AzureSqlValueMapper::nullableString($row['seoDescription'] ?? null),
            rowVersion: AzureSqlValueMapper::stringValue($row['rowVersion'] ?? 'AAAAAAAAAAA='),
            depth: $this->nodeDepth($publicId),
            childCount: $childCount,
            productCount: count($this->nodeProducts[$publicId] ?? []),
            regionCodes: $this->nodeRegions[$publicId] ?? [],
            createdAtUtc: AzureSqlValueMapper::nullableString($row['createdAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['updatedAtUtc'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildAdminTreeNode(array $row, bool $includeDeleted, int $depth): CatalogueNodeTreeNode
    {
        $publicId = (string) ($row['publicId'] ?? '');
        $children = [];
        foreach ($this->nodes as $child) {
            if (($child['parentPublicId'] ?? null) !== $publicId) {
                continue;
            }
            if (!$includeDeleted && ($child['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            $children[] = $this->buildAdminTreeNode($child, $includeDeleted, $depth + 1);
        }
        usort(
            $children,
            static fn (CatalogueNodeTreeNode $a, CatalogueNodeTreeNode $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );
        $usage = $this->getNodeUsage($publicId);

        return new CatalogueNodeTreeNode(
            publicId: $publicId,
            nodeCode: AzureSqlValueMapper::stringValue($row['nodeCode'] ?? ''),
            nodeName: AzureSqlValueMapper::stringValue($row['nodeName'] ?? ''),
            slug: AzureSqlValueMapper::stringValue($row['slug'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['description'] ?? null),
            nodeTypeCode: AzureSqlValueMapper::stringValue($row['nodeTypeCode'] ?? 'category'),
            imageUrl: AzureSqlValueMapper::nullableString($row['imageUrl'] ?? null),
            imageAltText: AzureSqlValueMapper::nullableString($row['imageAltText'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['isActive'] ?? true),
            isPublic: AzureSqlValueMapper::boolValue($row['isPublic'] ?? true),
            depth: $depth,
            childCount: $usage->childCount,
            productCount: $usage->productCount,
            children: $children,
            regionCodes: $this->nodeRegions[$publicId] ?? [],
            rowVersion: AzureSqlValueMapper::stringValue($row['rowVersion'] ?? 'AAAAAAAAAAA='),
            parentPublicId: AzureSqlValueMapper::nullableString($row['parentPublicId'] ?? null),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildRequestedPublicNode(array $row, ?string $regionCode): PublicCatalogueTreeNode
    {
        $tree = $this->buildPublicTreeNode($row, $regionCode, false, $this->nodeDepth((string) $row['publicId']));
        $products = $this->listPublicNodeProducts((string) $row['publicId'], $regionCode);

        return new PublicCatalogueTreeNode(
            publicId: $tree->publicId,
            nodeCode: $tree->nodeCode,
            nodeName: $tree->nodeName,
            slug: $tree->slug,
            description: $tree->description,
            nodeTypeCode: $tree->nodeTypeCode,
            imageUrl: $tree->imageUrl,
            imageAltText: $tree->imageAltText,
            displayOrder: $tree->displayOrder,
            depth: $tree->depth,
            hasChildren: $tree->hasChildren,
            hasProducts: $tree->hasProducts || $products !== [],
            children: $tree->children,
            products: $products,
            parentPublicId: $tree->parentPublicId,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildPublicTreeNode(array $row, ?string $regionCode, bool $includeProducts, int $depth): PublicCatalogueTreeNode
    {
        $publicId = (string) ($row['publicId'] ?? '');
        $children = [];
        foreach ($this->nodes as $child) {
            if (($child['parentPublicId'] ?? null) !== $publicId) {
                continue;
            }
            if (!$this->isPublicNodeRow($child, $regionCode)) {
                continue;
            }
            $children[] = $this->buildPublicTreeNode($child, $regionCode, $includeProducts, $depth + 1);
        }
        usort(
            $children,
            static fn (PublicCatalogueTreeNode $a, PublicCatalogueTreeNode $b): int =>
                [$a->displayOrder, $a->nodeName] <=> [$b->displayOrder, $b->nodeName],
        );
        $products = $includeProducts ? $this->listPublicNodeProducts($publicId, $regionCode) : [];
        $productCount = $includeProducts
            ? count($products)
            : count(array_filter(
                $this->nodeProducts[$publicId] ?? [],
                static fn (array $placement): bool => (bool) ($placement['isActive'] ?? true),
            ));

        return new PublicCatalogueTreeNode(
            publicId: $publicId,
            nodeCode: AzureSqlValueMapper::stringValue($row['nodeCode'] ?? ''),
            nodeName: AzureSqlValueMapper::stringValue($row['nodeName'] ?? ''),
            slug: AzureSqlValueMapper::stringValue($row['slug'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['description'] ?? null),
            nodeTypeCode: AzureSqlValueMapper::stringValue($row['nodeTypeCode'] ?? 'category'),
            imageUrl: AzureSqlValueMapper::nullableString($row['imageUrl'] ?? null),
            imageAltText: AzureSqlValueMapper::nullableString($row['imageAltText'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            depth: $depth,
            hasChildren: $children !== [],
            hasProducts: $productCount > 0,
            children: $children,
            products: $products,
            parentPublicId: AzureSqlValueMapper::nullableString($row['parentPublicId'] ?? null),
        );
    }

    /** @param array<string, mixed> $row */
    private function isPublicTabRow(array $row): bool
    {
        return ($row['deletedAtUtc'] ?? null) === null
            && ($row['isActive'] ?? true)
            && ($row['isPublic'] ?? true);
    }

    /** @param array<string, mixed> $row */
    private function isPublicNodeRow(array $row, ?string $regionCode): bool
    {
        if (($row['deletedAtUtc'] ?? null) !== null) {
            return false;
        }
        if (!($row['isActive'] ?? true) || !($row['isPublic'] ?? true)) {
            return false;
        }

        return $this->nodeVisibleForRegion($this->nodeRegions[(string) ($row['publicId'] ?? '')] ?? [], $regionCode);
    }

    /** @param list<string> $regionCodes */
    private function nodeVisibleForRegion(array $regionCodes, ?string $regionCode): bool
    {
        if ($regionCode === null || trim($regionCode) === '') {
            return true;
        }
        if ($regionCodes === []) {
            return true;
        }
        $needle = strtoupper(trim($regionCode));
        foreach ($regionCodes as $code) {
            if (strtoupper(trim((string) $code)) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $regionCodes
     * @return list<string>
     */
    private function normalizeRegionCodes(array $regionCodes): array
    {
        $out = [];
        foreach ($regionCodes as $code) {
            if (!is_string($code)) {
                continue;
            }
            $normalized = strtoupper(trim($code));
            if ($normalized !== '') {
                $out[] = $normalized;
            }
        }

        return array_values(array_unique($out));
    }

    /** @param array<string, mixed> $placement */
    private function toNodeProductRecord(array $placement): CatalogueNodeProductRecord
    {
        $productPublicId = (string) ($placement['productPublicId'] ?? '');
        $meta = $this->productMeta($productPublicId);

        return new CatalogueNodeProductRecord(
            productPublicId: $productPublicId,
            productName: $meta['name'],
            productSlug: $meta['slug'],
            internalCode: $meta['internalCode'],
            thumbnailUrl: $meta['thumbnailUrl'],
            displayOrder: AzureSqlValueMapper::intValue($placement['displayOrder'] ?? 0),
            isFeatured: AzureSqlValueMapper::boolValue($placement['isFeatured'] ?? false),
            isActive: AzureSqlValueMapper::boolValue($placement['isActive'] ?? true),
            publicationStatus: $meta['statusCode'],
        );
    }

    /** @param array<string, mixed> $placement */
    private function toPublicProductCard(array $placement, ?string $regionCode): ?PublicCatalogueProductCard
    {
        $productPublicId = (string) ($placement['productPublicId'] ?? '');
        $record = $this->store->productRecordByPublicId(ProductPublicId::fromString($productPublicId));
        if ($record === null || !$this->isPublishedProduct($record['product'], $regionCode)) {
            return null;
        }
        $meta = $this->productMeta($productPublicId);
        $thumbs = $this->listPublicProductThumbnailUrls([$productPublicId]);

        return new PublicCatalogueProductCard(
            publicId: $productPublicId,
            name: $meta['name'],
            slug: $meta['slug'],
            internalCode: $meta['internalCode'],
            thumbnailUrl: $thumbs[$productPublicId] ?? null,
            displayOrder: AzureSqlValueMapper::intValue($placement['displayOrder'] ?? 0),
            isFeatured: AzureSqlValueMapper::boolValue($placement['isFeatured'] ?? false),
        );
    }

    public function listPublicProductThumbnailUrls(array $productPublicIds): array
    {
        $wanted = [];
        foreach ($productPublicIds as $id) {
            if ($id !== '') {
                $wanted[$id] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $mediaByProduct = [];
        foreach ($this->store->media() as $row) {
            $productPublicId = (string) ($row['productPublicId'] ?? '');
            if (!isset($wanted[$productPublicId])) {
                continue;
            }
            if (($row['archived'] ?? false) === true) {
                continue;
            }
            if (($row['isActive'] ?? true) === false) {
                continue;
            }
            $mediaByProduct[$productPublicId][] = new ProductMediaSummary(
                publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
                mediaCategoryCode: AzureSqlValueMapper::stringValue($row['mediaCategoryCode'] ?? ''),
                mediaTypeCode: AzureSqlValueMapper::stringValue($row['mediaTypeCode'] ?? ''),
                title: AzureSqlValueMapper::stringValue($row['title'] ?? ''),
                publicUrl: AzureSqlValueMapper::nullableString($row['publicUrl'] ?? null),
                altText: AzureSqlValueMapper::nullableString($row['altText'] ?? null),
                displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
                isPrimary: AzureSqlValueMapper::boolValue($row['isPrimary'] ?? false),
                thumbnailUrl: AzureSqlValueMapper::nullableString($row['thumbnailUrl'] ?? null),
            );
        }

        $out = [];
        foreach ($mediaByProduct as $productPublicId => $media) {
            $url = ProductThumbnailImageResolver::fromMediaSummaries($media);
            if ($url !== null) {
                $out[$productPublicId] = $url;
            }
        }

        return $out;
    }

    private function isPublishedProduct(Product $product, ?string $regionCode): bool
    {
        if ($product->isSoftDeleted() || !$product->isActive() || !$product->status()->isPublished()) {
            return false;
        }
        $now = $this->store->clock()->now();
        if ($product->publishAtUtc() !== null && $product->publishAtUtc() > $now) {
            return false;
        }
        if ($product->unpublishAtUtc() !== null && $product->unpublishAtUtc() <= $now) {
            return false;
        }
        if ($regionCode === null || trim($regionCode) === '') {
            return true;
        }
        foreach ($this->store->productRegions($product->publicId()->toString()) as $regionPublicId) {
            if ($this->store->regionPublicIdMatchesCode($regionPublicId, $regionCode)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{name: string, slug: string, internalCode: ?string, thumbnailUrl: ?string, statusCode: string} */
    private function productMeta(string $productPublicId): array
    {
        $record = $this->store->productRecordByPublicId(ProductPublicId::fromString($productPublicId));
        if ($record === null) {
            return [
                'name' => 'Unknown product',
                'slug' => $productPublicId,
                'internalCode' => null,
                'thumbnailUrl' => null,
                'statusCode' => 'unknown',
            ];
        }
        $product = $record['product'];
        $slug = $productPublicId;
        foreach ($this->store->slugs() as $row) {
            if (($row['productPublicId'] ?? null) === $productPublicId && ($row['isPrimary'] ?? false) === true) {
                $slug = (string) ($row['slug'] ?? $productPublicId);
                break;
            }
        }

        return [
            'name' => $product->productName(),
            'slug' => $slug,
            'internalCode' => $product->internalCode()?->toString(),
            'thumbnailUrl' => null,
            'statusCode' => $product->status()->toString(),
        ];
    }

    /** @return list<string> */
    private function breadcrumbNames(string $nodePublicId): array
    {
        $names = [];
        $current = $this->nodes[$nodePublicId] ?? null;
        while ($current !== null) {
            array_unshift($names, AzureSqlValueMapper::stringValue($current['nodeName'] ?? ''));
            $parentId = $current['parentPublicId'] ?? null;
            $current = is_string($parentId) ? ($this->nodes[$parentId] ?? null) : null;
        }

        return $names;
    }
}
