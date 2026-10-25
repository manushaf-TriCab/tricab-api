<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\CatalogueNodeProductRecord;
use TriCab\Core\Product\Application\DTO\CatalogueNodeResolvedProductRecord;
use TriCab\Core\Product\Application\DTO\ProductAdminDetail;
use TriCab\Core\Product\Application\DTO\PublicCatalogueProductCard;
use TriCab\Core\Product\Contract\CatalogueNavigationRepository;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Resolves effective catalogue node products from direct placements and linked ProductStyles.
 */
final class CatalogueNodeEffectiveProductsService
{
    /** @var array<string, mixed>|null */
    private ?array $styleIndexes = null;

    /** @var array<string, list<\TriCab\Core\Product\Application\DTO\CatalogueNodeProductStyleRecord>> */
    private array $styleLinksByNode = [];

    /** @var array<string, true>|null */
    private ?array $nodePublicIdsWithStyleLinks = null;

    /**
     * Set only inside summarizePublicNodes(): direct placements per node, read in one query.
     *
     * @var array<string, list<CatalogueNodeProductRecord>>|null
     */
    private ?array $preloadedDirect = null;

    /**
     * Set only inside summarizePublicNodes(): product details keyed by ProductPublicId::toString(),
     * null for products that do not exist.
     *
     * @var array<string, ProductAdminDetail|null>|null
     */
    private ?array $preloadedDetails = null;

    public function __construct(
        private readonly CatalogueNavigationRepository $catalogue,
        private readonly ProductStyleRepository $styles,
        private readonly ProductAdminQueryRepository $products,
        private readonly ResolveCatalogueStyleFamilyProductsService $styleResolver = new ResolveCatalogueStyleFamilyProductsService(),
    ) {
    }

    /**
     * @return list<CatalogueNodeResolvedProductRecord>
     */
    public function listResolvedAdmin(string $nodePublicId, ?string $regionCode = null): array
    {
        return $this->resolve($nodePublicId, $regionCode, false);
    }

    /**
     * @return list<PublicCatalogueProductCard>
     */
    public function listPublicProductCards(string $nodePublicId, ?string $regionCode = null): array
    {
        $visible = $this->visiblePublicRows($nodePublicId, $regionCode);
        $thumbnailUrls = $this->catalogue->listPublicProductThumbnailUrls(array_map(
            static fn (array $pair): string => $pair[0]->productPublicId,
            $visible,
        ));

        return $this->buildPublicCards($visible, $thumbnailUrls);
    }

    /**
     * Same results as calling listPublicProductCards() and hasPublicVisibleProducts() for each node,
     * but placements, style links, style assignments, product details and thumbnails are each read
     * once for all nodes instead of once per node / product.
     *
     * @param array<string, bool> $directHasProductsByNode node public id => repository hasProducts flag
     * @return array{cards: array<string, list<PublicCatalogueProductCard>>, hasProducts: array<string, bool>}
     */
    public function summarizePublicNodes(array $directHasProductsByNode, ?string $regionCode, bool $includeCards): array
    {
        $nodeIds = array_map(static fn (int|string $id): string => (string) $id, array_keys($directHasProductsByNode));
        if ($nodeIds === []) {
            return ['cards' => [], 'hasProducts' => []];
        }

        $this->preloadNodes($nodeIds);
        try {
            $hasProducts = [];
            $visibleByNode = [];
            foreach ($nodeIds as $nodeId) {
                if ($includeCards) {
                    $visibleByNode[$nodeId] = $this->visiblePublicRows($nodeId, $regionCode);
                }
                $hasProducts[$nodeId] = $this->hasPublicVisibleProducts(
                    $nodeId,
                    $directHasProductsByNode[$nodeId],
                    $regionCode,
                );
            }

            $cards = [];
            if ($includeCards) {
                $productIds = [];
                foreach ($visibleByNode as $visible) {
                    foreach ($visible as [$row]) {
                        $productIds[$row->productPublicId] = true;
                    }
                }
                $thumbnailUrls = $this->catalogue->listPublicProductThumbnailUrls(array_map(
                    static fn (int|string $id): string => (string) $id,
                    array_keys($productIds),
                ));
                foreach ($visibleByNode as $nodeId => $visible) {
                    $cards[$nodeId] = $this->buildPublicCards($visible, $thumbnailUrls);
                }
            }

            return ['cards' => $cards, 'hasProducts' => $hasProducts];
        } finally {
            $this->preloadedDirect = null;
            $this->preloadedDetails = null;
        }
    }

    /**
     * @param list<string> $nodeIds
     */
    private function preloadNodes(array $nodeIds): void
    {
        $this->preloadedDetails = [];
        $hasStyleLinks = false;
        foreach ($this->catalogue->listNodeProductStylesForNodes($nodeIds, true) as $nodeId => $links) {
            $this->styleLinksByNode[(string) $nodeId] = $links;
            $hasStyleLinks = $hasStyleLinks || $links !== [];
        }
        $productIds = [];
        $styleProducts = $hasStyleLinks ? $this->getStyleIndexes()['productsByPublicId'] : [];
        if (is_array($styleProducts)) {
            foreach (array_keys($styleProducts) as $id) {
                $productIds[] = (string) $id;
            }
        }

        $this->preloadedDirect = [];
        foreach ($this->catalogue->listNodeProductsForNodes($nodeIds, true) as $nodeId => $rows) {
            $this->preloadedDirect[(string) $nodeId] = $rows;
            foreach ($rows as $row) {
                $productIds[] = $row->productPublicId;
            }
        }
        $this->primeDetails($productIds);
    }

    /**
     * Loads details for ids not already preloaded, in bulk, when a preload is active.
     *
     * @param list<string> $productPublicIds
     */
    private function primeDetails(array $productPublicIds): void
    {
        if ($this->preloadedDetails === null) {
            return;
        }
        $this->loadDetails(array_values(array_filter(
            $productPublicIds,
            fn (string $id): bool => !array_key_exists(ProductPublicId::fromString($id)->toString(), $this->preloadedDetails ?? []),
        )));
    }

    private function findDetail(string $productPublicId): ?ProductAdminDetail
    {
        $publicId = ProductPublicId::fromString($productPublicId);
        if ($this->preloadedDetails !== null && array_key_exists($publicId->toString(), $this->preloadedDetails)) {
            return $this->preloadedDetails[$publicId->toString()];
        }

        return $this->products->findDetailByPublicId($publicId);
    }

    /**
     * @return list<array{0: CatalogueNodeResolvedProductRecord, 1: ProductAdminDetail}>
     */
    private function visiblePublicRows(string $nodePublicId, ?string $regionCode): array
    {
        $visible = [];
        foreach ($this->resolve($nodePublicId, $regionCode, true) as $row) {
            if (!$row->publicVisible || !$row->regionVisible) {
                continue;
            }
            $detail = $this->findDetail($row->productPublicId);
            if ($detail === null) {
                continue;
            }
            $visible[] = [$row, $detail];
        }

        return $visible;
    }

    /**
     * @param list<array{0: CatalogueNodeResolvedProductRecord, 1: ProductAdminDetail}> $visible
     * @param array<string, string> $thumbnailUrls
     * @return list<PublicCatalogueProductCard>
     */
    private function buildPublicCards(array $visible, array $thumbnailUrls): array
    {
        $cards = [];
        foreach ($visible as [$row, $detail]) {
            $cards[] = new PublicCatalogueProductCard(
                publicId: $row->productPublicId,
                name: $row->productName,
                slug: $detail->primarySlug ?? $row->productPublicId,
                internalCode: $row->productCode,
                thumbnailUrl: $thumbnailUrls[$row->productPublicId] ?? null,
                displayOrder: $row->displayOrder,
                isFeatured: false,
            );
        }

        return $cards;
    }

    public function countPublicVisible(string $nodePublicId, ?string $regionCode = null): int
    {
        $count = 0;
        foreach ($this->resolve($nodePublicId, $regionCode, true) as $row) {
            if ($row->publicVisible && $row->regionVisible) {
                ++$count;
            }
        }

        return $count;
    }

    public function countEffective(string $nodePublicId, ?string $regionCode = null, bool $publicOnly = false): int
    {
        $count = 0;
        foreach ($this->resolve($nodePublicId, $regionCode, $publicOnly) as $row) {
            if ($publicOnly) {
                if ($row->publicVisible && $row->regionVisible) {
                    ++$count;
                }
                continue;
            }
            ++$count;
        }

        return $count;
    }

    public function nodeHasActiveStyleLinks(string $nodePublicId): bool
    {
        $this->ensureStyleLinkNodeIndex();

        return isset($this->nodePublicIdsWithStyleLinks[$nodePublicId]);
    }

    private function ensureStyleLinkNodeIndex(): void
    {
        if ($this->nodePublicIdsWithStyleLinks !== null) {
            return;
        }
        $this->nodePublicIdsWithStyleLinks = [];
        foreach ($this->catalogue->listNodePublicIdsWithActiveStyleLinks() as $id) {
            $this->nodePublicIdsWithStyleLinks[$id] = true;
        }
    }

    /**
     * @return list<\TriCab\Core\Product\Application\DTO\CatalogueNodeProductStyleRecord>
     */
    private function listActiveStyleLinks(string $nodePublicId): array
    {
        if (!array_key_exists($nodePublicId, $this->styleLinksByNode)) {
            $this->styleLinksByNode[$nodePublicId] = $this->catalogue->listNodeProductStyles($nodePublicId, true);
        }

        return $this->styleLinksByNode[$nodePublicId];
    }

    /**
     * Uses repository direct-product visibility unless only style-family links could expose products.
     */
    public function hasPublicVisibleProducts(string $nodePublicId, bool $directHasProducts, ?string $regionCode = null): bool
    {
        if ($directHasProducts) {
            return true;
        }
        if (!$this->nodeHasActiveStyleLinks($nodePublicId)) {
            return false;
        }

        return $this->countPublicVisible($nodePublicId, $regionCode) > 0;
    }

    /**
     * @return list<CatalogueNodeResolvedProductRecord>
     */
    private function resolve(string $nodePublicId, ?string $regionCode, bool $publicOnly): array
    {
        $direct = $this->preloadedDirect[$nodePublicId] ?? $this->catalogue->listNodeProducts($nodePublicId, true);
        $styleLinks = $this->listActiveStyleLinks($nodePublicId);
        if ($styleLinks === [] && $direct === []) {
            return [];
        }

        $directRows = [];
        foreach ($direct as $row) {
            if (!$row->isActive) {
                continue;
            }
            $directRows[] = [
                'productPublicId' => $row->productPublicId,
                'productCode' => strtoupper(trim((string) ($row->internalCode ?? ''))),
                'productName' => $row->productName,
                'displayOrder' => $row->displayOrder,
            ];
        }

        $styleRows = [];
        if ($styleLinks !== []) {
            $indexes = $this->getStyleIndexes();
            foreach ($styleLinks as $link) {
                $resolved = $this->styleResolver->resolveForLinkedStyle(
                    $link->styleCode,
                    $indexes['stylesByCode'],
                    $indexes['childrenByParentPublicId'],
                    $indexes['productsByStylePublicId'],
                    $indexes['productsByPublicId'],
                    $indexes['regionCodesByProductPublicId'],
                    $regionCode,
                    $publicOnly,
                );
                $products = $publicOnly ? $resolved['visibleProducts'] : $resolved['candidateProducts'];
                foreach ($products as $product) {
                    $styleRows[] = array_merge($product, [
                        'linkedStyleCode' => $link->styleCode,
                        'styleDisplayOrder' => $link->displayOrder,
                    ]);
                }
            }
        }

        $merged = $this->styleResolver->mergeDirectAndStyleProducts($directRows, array_map(
            static fn (array $row): array => [
                'productPublicId' => $row['productPublicId'],
                'productCode' => $row['productCode'] ?? '',
                'productName' => $row['productName'] ?? '',
                'resolvedViaStyleCode' => $row['resolvedViaStyleCode'] ?? null,
            ],
            $styleRows,
        ));

        $out = [];
        $indexes = $styleLinks !== [] ? $this->getStyleIndexes() : null;
        foreach ($merged['products'] as $row) {
            $productPublicId = (string) $row['productPublicId'];
            $detail = $this->findDetail($productPublicId);
            $publicVisible = $this->isPublicVisible($detail, $publicOnly);
            $regionVisible = $this->isRegionVisible($detail, $regionCode);
            $out[] = new CatalogueNodeResolvedProductRecord(
                productPublicId: $productPublicId,
                productName: $detail?->productName ?? (string) ($row['productName'] ?? ''),
                productCode: $detail?->internalCode ?? ($row['productCode'] ?? null),
                provenance: (string) ($row['provenance'] ?? 'STYLE'),
                resolvedViaStyleCode: $row['resolvedViaStyleCode'] ?? null,
                linkedStyleCode: $indexes !== null
                    ? $this->findLinkedStyleCode(
                        $styleLinks,
                        $row['resolvedViaStyleCode'] ?? null,
                        $indexes['stylesByCode'],
                        $indexes['childrenByParentPublicId'],
                    )
                    : null,
                publicationStatus: $detail?->statusCode ?? 'unknown',
                publicVisible: $publicVisible,
                regionVisible: $regionVisible,
                displayOrder: (int) ($row['displayOrder'] ?? 0),
            );
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function getStyleIndexes(): array
    {
        if ($this->styleIndexes === null) {
            $this->styleIndexes = $this->buildStyleIndexes();
        }

        return $this->styleIndexes;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStyleIndexes(): array
    {
        $stylesByCode = [];
        $childrenByParentPublicId = [];
        $productsByStylePublicId = [];
        $styles = $this->styles->listAll(false);
        $assignedByStyle = $this->styles->listAssignedProductPublicIdsByStyle();
        $assignedIds = [];
        foreach ($styles as $style) {
            foreach ($assignedByStyle[$style->publicId] ?? [] as $productPublicId) {
                $assignedIds[] = $productPublicId;
            }
        }
        $details = $this->loadDetails($assignedIds);
        foreach ($styles as $style) {
            $stylesByCode[$style->styleCode] = [
                'publicId' => $style->publicId,
                'styleCode' => $style->styleCode,
                'displayName' => $style->displayName,
                'isActive' => $style->isActive,
                'isDeleted' => $style->isDeleted,
                'sortOrder' => $style->sortOrder,
                'parentPublicId' => $style->parentPublicId,
                'parentStyleCode' => $style->parentStyleCode,
            ];
            if ($style->parentPublicId !== null) {
                $childrenByParentPublicId[$style->parentPublicId][] = $style->publicId;
            }
            $assigned = [];
            foreach ($assignedByStyle[$style->publicId] ?? [] as $productPublicId) {
                $detail = $details[ProductPublicId::fromString($productPublicId)->toString()] ?? null;
                if ($detail === null) {
                    continue;
                }
                $assigned[] = [
                    'productPublicId' => $productPublicId,
                    'productCode' => strtoupper(trim($detail->internalCode ?? '')),
                    'productName' => $detail->productName,
                ];
            }
            $productsByStylePublicId[$style->publicId] = $assigned;
        }

        $productsByPublicId = [];
        $regionCodesByProductPublicId = [];
        foreach ($productsByStylePublicId as $assigned) {
            foreach ($assigned as $row) {
                $id = (string) $row['productPublicId'];
                if (isset($productsByPublicId[$id])) {
                    continue;
                }
                $detail = $details[ProductPublicId::fromString($id)->toString()] ?? null;
                if ($detail === null) {
                    continue;
                }
                $productsByPublicId[$id] = $this->productMeta($detail);
                $regionCodesByProductPublicId[$id] = array_map(
                    static fn (string $code): string => strtoupper(trim($code)),
                    $detail->regionCodes,
                );
            }
        }

        return [
            'stylesByCode' => $stylesByCode,
            'childrenByParentPublicId' => $childrenByParentPublicId,
            'productsByStylePublicId' => $productsByStylePublicId,
            'productsByPublicId' => $productsByPublicId,
            'regionCodesByProductPublicId' => $regionCodesByProductPublicId,
        ];
    }

    /**
     * Bulk product details keyed by ProductPublicId::toString(); also feeds an active preload.
     *
     * @param list<string> $productPublicIds
     * @return array<string, ProductAdminDetail>
     */
    private function loadDetails(array $productPublicIds): array
    {
        $publicIds = [];
        foreach ($productPublicIds as $id) {
            $publicId = ProductPublicId::fromString($id);
            $publicIds[$publicId->toString()] = $publicId;
        }
        if ($publicIds === []) {
            return [];
        }
        $details = $this->products->findDetailsByPublicIds(array_values($publicIds));
        if ($this->preloadedDetails !== null) {
            foreach (array_keys($publicIds) as $key) {
                $this->preloadedDetails[$key] = $details[$key] ?? null;
            }
        }

        return $details;
    }

    /**
     * @return array<string, mixed>
     */
    private function productMeta(ProductAdminDetail $detail): array
    {
        return [
            'publicId' => $detail->publicId,
            'productName' => $detail->productName,
            'internalCode' => strtoupper(trim($detail->internalCode ?? '')),
            'isActive' => $detail->isActive,
            'deletedAtUtc' => null,
            'publishAtUtc' => $detail->publishAtUtc,
            'unpublishAtUtc' => $detail->unpublishAtUtc,
            'publicationStatus' => strtolower($detail->statusCode),
        ];
    }

    private function isPublicVisible(?ProductAdminDetail $detail, bool $publicOnly): bool
    {
        if ($detail === null) {
            return false;
        }
        if (!$publicOnly) {
            return true;
        }
        if (!$detail->isActive) {
            return false;
        }
        if (strtolower($detail->statusCode) !== 'published') {
            return false;
        }
        $now = time();
        if ($detail->publishAtUtc !== null && strtotime($detail->publishAtUtc) > $now) {
            return false;
        }
        if ($detail->unpublishAtUtc !== null && strtotime($detail->unpublishAtUtc) <= $now) {
            return false;
        }

        return true;
    }

    private function isRegionVisible(?ProductAdminDetail $detail, ?string $regionCode): bool
    {
        if ($regionCode === null || trim($regionCode) === '') {
            return true;
        }
        if ($detail === null) {
            return false;
        }
        $needle = strtoupper(trim($regionCode));

        return in_array($needle, array_map(
            static fn (string $code): string => strtoupper(trim($code)),
            $detail->regionCodes,
        ), true);
    }

    /**
     * @param list<\TriCab\Core\Product\Application\DTO\CatalogueNodeProductStyleRecord> $styleLinks
     * @param array<string, array<string, mixed>> $stylesByCode
     * @param array<string, list<string>> $childrenByParentPublicId
     */
    private function findLinkedStyleCode(
        array $styleLinks,
        ?string $resolvedViaStyleCode,
        array $stylesByCode,
        array $childrenByParentPublicId,
    ): ?string {
        if ($resolvedViaStyleCode === null) {
            return null;
        }
        $resolved = strtoupper(trim($resolvedViaStyleCode));
        foreach ($styleLinks as $link) {
            $resolvedLink = $this->styleResolver->resolveForLinkedStyle(
                $link->styleCode,
                $stylesByCode,
                $childrenByParentPublicId,
                [],
                [],
                [],
                null,
                false,
            );
            if (in_array($resolved, $resolvedLink['descendantStyleCodes'], true)) {
                return $link->styleCode;
            }
        }

        return null;
    }
}
