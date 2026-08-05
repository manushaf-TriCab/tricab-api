<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\ProductAdminDetail;
use TriCab\Core\Product\Application\DTO\ProductAdminListItem;
use TriCab\Core\Product\Application\DTO\ProductListCriteria;
use TriCab\Core\Product\Application\DTO\ProductListResult;
use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

final class InMemoryProductAdminQueryRepository implements ProductAdminQueryRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function list(ProductListCriteria $criteria): ProductListResult
    {
        $items = [];
        foreach ($this->store->productRecords() as $record) {
            if (!$this->matchesCriteria($record['product'], $criteria)) {
                continue;
            }
            $items[] = $this->buildListItem($record);
        }

        usort(
            $items,
            fn (ProductAdminListItem $a, ProductAdminListItem $b): int => $this->compareListItems($a, $b, $criteria),
        );

        $total = count($items);
        $offset = ($criteria->page - 1) * $criteria->pageSize;
        $pageItems = array_slice($items, $offset, $criteria->pageSize);

        return new ProductListResult(
            items: $pageItems,
            pagination: Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function findDetailByPublicId(ProductPublicId $publicId): ?ProductAdminDetail
    {
        $record = $this->store->productRecordByPublicId($publicId);
        if ($record === null || $record['product']->isSoftDeleted()) {
            return null;
        }

        $product = $record['product'];

        return new ProductAdminDetail(
            publicId: $product->publicId()->toString(),
            productName: $product->productName(),
            internalName: $product->internalName(),
            internalCode: $product->internalCode()?->toString(),
            shortDescription: $product->shortDescription(),
            fullDescription: $product->fullDescription(),
            familyPublicId: $this->store->familyPublicIdForId($product->familyId()),
            categoryPublicId: $this->store->categoryPublicIdForId($product->categoryId()),
            seriesPublicId: $this->store->seriesPublicIdForId($product->seriesId()),
            statusCode: $product->status()->toString(),
            lifecycleStatusCode: $product->lifecycleStatus()->toString(),
            isFeatured: $product->isFeatured(),
            publishAtUtc: $product->publishAtUtc()?->format(DATE_ATOM),
            unpublishAtUtc: $product->unpublishAtUtc()?->format(DATE_ATOM),
            discontinueAtUtc: $product->discontinueAtUtc()?->format(DATE_ATOM),
            erpReference: $product->erpReference(),
            legacyProductId: $product->legacyProductId(),
            variants: $this->listVariantSummariesByProduct($publicId, includeArchived: true, includeDeleted: false),
            regionCodes: $this->codesForProduct($publicId->toString(), 'regions'),
            marketCodes: $this->codesForProduct($publicId->toString(), 'markets'),
            industryCodes: $this->codesForProduct($publicId->toString(), 'industries'),
            applicationCodes: $this->codesForProduct($publicId->toString(), 'applications'),
            primarySlug: $this->primarySlug($publicId->toString()),
            isActive: $product->isActive(),
            isArchived: $product->isArchived(),
            createdAtUtc: $record['createdAtUtc'],
            updatedAtUtc: $record['updatedAtUtc'],
            createdByDisplayName: null,
            updatedByDisplayName: null,
            rowVersion: $product->rowVersion()?->toBase64() ?? '',
        );
    }

    public function listVariantSummariesByProduct(
        ProductPublicId $productPublicId,
        bool $includeArchived = true,
        bool $includeDeleted = false,
    ): array {
        $items = [];
        foreach ($this->store->variantRecords() as $record) {
            $variant = $record['variant'];
            $parent = $this->store->productRecordById($variant->productId());
            if ($parent === null || !$parent['product']->publicId()->equals($productPublicId)) {
                continue;
            }
            if (!$includeDeleted && $variant->isSoftDeleted()) {
                continue;
            }
            if (!$includeArchived && $variant->isArchived()) {
                continue;
            }
            $items[] = $this->toVariantSummary($variant);
        }

        usort(
            $items,
            static fn (ProductVariantSummary $a, ProductVariantSummary $b): int => $a->displayOrder <=> $b->displayOrder
                ?: strcmp($a->publicId, $b->publicId),
        );

        return $items;
    }

    public function findVariantSummaryByPublicId(
        ProductVariantPublicId $publicId,
        bool $includeDeleted = false,
    ): ?ProductVariantSummary {
        $record = $this->store->variantRecordByPublicId($publicId);
        if ($record === null) {
            return null;
        }
        if (!$includeDeleted && $record['variant']->isSoftDeleted()) {
            return null;
        }

        return $this->toVariantSummary($record['variant']);
    }

    /**
     * @param array{
     *   product: Product,
     *   createdAtUtc: string,
     *   updatedAtUtc: string,
     *   createdByUserId: int,
     *   updatedByUserId: int
     * } $record
     */
    private function buildListItem(array $record): ProductAdminListItem
    {
        $product = $record['product'];
        $publicId = $product->publicId()->toString();

        return new ProductAdminListItem(
            publicId: $publicId,
            productName: $product->productName(),
            internalCode: $product->internalCode()?->toString(),
            primaryProductCode: $this->primaryProductCode($publicId),
            thumbnailUrl: $this->thumbnailUrl($publicId),
            familyName: $this->store->familyNameForId($product->familyId()),
            categoryName: $this->store->categoryNameForId($product->categoryId()),
            seriesName: $this->store->seriesNameForId($product->seriesId()),
            variantCount: $this->variantCount($product->id()),
            regionCodes: $this->codesForProduct($publicId, 'regions'),
            statusCode: $product->status()->toString(),
            lifecycleStatusCode: $product->lifecycleStatus()->toString(),
            updatedAtUtc: $record['updatedAtUtc'],
            updatedByDisplayName: null,
            isArchived: $product->isArchived(),
            rowVersion: $product->rowVersion()?->toBase64() ?? '',
        );
    }

    private function matchesCriteria(Product $product, ProductListCriteria $criteria): bool
    {
        if (!$criteria->includeDeleted && $product->isSoftDeleted()) {
            return false;
        }

        if ($criteria->isArchived === true && !$product->isArchived()) {
            return false;
        }
        if ($criteria->isArchived === false && $product->isArchived()) {
            return false;
        }

        if ($criteria->isActive !== null && $product->isActive() !== $criteria->isActive) {
            return false;
        }

        if ($criteria->statusCode !== null && strcasecmp($product->status()->toString(), $criteria->statusCode) !== 0) {
            return false;
        }

        if (
            $criteria->lifecycleStatusCode !== null
            && strcasecmp($product->lifecycleStatus()->toString(), $criteria->lifecycleStatusCode) !== 0
        ) {
            return false;
        }

        if (
            $criteria->familyPublicId !== null
            && !$this->store->familyPublicIdMatches($product->familyId(), $criteria->familyPublicId)
        ) {
            return false;
        }

        if (
            $criteria->categoryPublicId !== null
            && !$this->store->categoryPublicIdMatches($product->categoryId(), $criteria->categoryPublicId)
        ) {
            return false;
        }

        if (
            $criteria->seriesPublicId !== null
            && !$this->store->seriesPublicIdMatches($product->seriesId(), $criteria->seriesPublicId)
        ) {
            return false;
        }

        $search = $criteria->normalizedSearch();
        if ($search !== null && !$this->matchesSearch($search, $product)) {
            return false;
        }

        if ($criteria->hasVariants === true && $this->variantCount($product->id()) === 0) {
            return false;
        }
        if ($criteria->hasVariants === false && $this->variantCount($product->id()) > 0) {
            return false;
        }

        $publicId = $product->publicId()->toString();
        if ($criteria->hasDocuments === true && !$this->hasDocuments($publicId)) {
            return false;
        }
        if ($criteria->hasDocuments === false && $this->hasDocuments($publicId)) {
            return false;
        }

        if ($criteria->hasMedia === true && !$this->hasMedia($publicId)) {
            return false;
        }
        if ($criteria->hasMedia === false && $this->hasMedia($publicId)) {
            return false;
        }

        if ($criteria->hasPricing === true && !$this->hasPricing($publicId)) {
            return false;
        }
        if ($criteria->hasPricing === false && $this->hasPricing($publicId)) {
            return false;
        }

        if ($criteria->regionPublicId !== null && !in_array($criteria->regionPublicId, $this->store->productRegions($publicId), true)) {
            return false;
        }

        if ($criteria->marketPublicId !== null && !in_array($criteria->marketPublicId, $this->store->productMarkets($publicId), true)) {
            return false;
        }

        if ($criteria->industryPublicId !== null && !in_array($criteria->industryPublicId, $this->store->productIndustries($publicId), true)) {
            return false;
        }

        if ($criteria->applicationPublicId !== null && !in_array($criteria->applicationPublicId, $this->store->productApplications($publicId), true)) {
            return false;
        }

        if ($criteria->updatedFromUtc !== null) {
            $record = $this->store->productRecordByPublicId($product->publicId());
            if ($record === null || $record['updatedAtUtc'] < $criteria->updatedFromUtc) {
                return false;
            }
        }

        if ($criteria->updatedToUtc !== null) {
            $record = $this->store->productRecordByPublicId($product->publicId());
            if ($record === null || $record['updatedAtUtc'] > $criteria->updatedToUtc) {
                return false;
            }
        }

        if ($criteria->missingRequiredInformation === true) {
            if ($this->primarySlug($publicId) !== null && $product->shortDescription() !== null) {
                return false;
            }
        }

        return true;
    }

    private function matchesSearch(string $search, Product $product): bool
    {
        $needle = strtolower($search);
        $fields = [
            $product->productName(),
            $product->internalCode()?->toString(),
            $product->publicId()->toString(),
        ];

        foreach ($fields as $field) {
            if ($field !== null && str_contains(strtolower($field), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function compareListItems(
        ProductAdminListItem $a,
        ProductAdminListItem $b,
        ProductListCriteria $criteria,
    ): int {
        $direction = $criteria->normalizedSortDirection() === ProductListCriteria::SORT_ASC ? 1 : -1;

        $value = match ($criteria->sortBy) {
            ProductListCriteria::SORT_NAME => strcmp($a->productName, $b->productName),
            ProductListCriteria::SORT_PRODUCT_CODE => strcmp($a->internalCode ?? '', $b->internalCode ?? ''),
            ProductListCriteria::SORT_CATEGORY => strcmp($a->categoryName ?? '', $b->categoryName ?? ''),
            ProductListCriteria::SORT_SERIES => strcmp($a->seriesName ?? '', $b->seriesName ?? ''),
            ProductListCriteria::SORT_STATUS => strcmp($a->statusCode, $b->statusCode),
            ProductListCriteria::SORT_LIFECYCLE => strcmp($a->lifecycleStatusCode, $b->lifecycleStatusCode),
            ProductListCriteria::SORT_CREATED_AT => strcmp($a->publicId, $b->publicId),
            ProductListCriteria::SORT_DISPLAY_ORDER => strcmp($a->publicId, $b->publicId),
            default => strcmp($a->updatedAtUtc, $b->updatedAtUtc),
        };

        if ($value === 0) {
            return strcmp($a->publicId, $b->publicId) * $direction;
        }

        return $value * $direction;
    }

    private function variantCount(?\TriCab\Core\Product\Domain\ValueObject\ProductId $productId): int
    {
        if ($productId === null) {
            return 0;
        }

        $count = 0;
        foreach ($this->store->variantRecords() as $record) {
            if ($record['variant']->productId()->equals($productId) && !$record['variant']->isSoftDeleted()) {
                ++$count;
            }
        }

        return $count;
    }

    private function primaryProductCode(string $productPublicId): ?string
    {
        foreach ($this->store->productCodes() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId
                && ($row['isPrimary'] ?? false)
                && !($row['deleted'] ?? false)
            ) {
                return is_string($row['codeValue'] ?? null) ? $row['codeValue'] : null;
            }
        }

        return null;
    }

    private function thumbnailUrl(string $productPublicId): ?string
    {
        foreach ($this->store->media() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId
                && ($row['isPrimary'] ?? false)
                && !($row['archived'] ?? false)
            ) {
                $publicUrl = $row['publicUrl'] ?? null;

                return is_string($publicUrl) ? $publicUrl : null;
            }
        }

        return null;
    }

    private function primarySlug(string $productPublicId): ?string
    {
        foreach ($this->store->slugs() as $row) {
            if (
                ($row['productPublicId'] ?? '') === $productPublicId
                && ($row['isPrimary'] ?? false)
                && !($row['deleted'] ?? false)
            ) {
                $slug = $row['slug'] ?? null;

                return is_string($slug) ? $slug : null;
            }
        }

        return null;
    }

    private function hasDocuments(string $productPublicId): bool
    {
        foreach ($this->store->documents() as $row) {
            if (($row['productPublicId'] ?? '') === $productPublicId && !($row['archived'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function hasMedia(string $productPublicId): bool
    {
        foreach ($this->store->media() as $row) {
            if (($row['productPublicId'] ?? '') === $productPublicId && !($row['archived'] ?? false)) {
                return true;
            }
        }

        return false;
    }

    private function hasPricing(string $productPublicId): bool
    {
        foreach ($this->store->prices() as $row) {
            if (($row['productPublicId'] ?? '') === $productPublicId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function codesForProduct(string $productPublicId, string $kind): array
    {
        $publicIds = match ($kind) {
            'regions' => $this->store->productRegions($productPublicId),
            'markets' => $this->store->productMarkets($productPublicId),
            'industries' => $this->store->productIndustries($productPublicId),
            default => $this->store->productApplications($productPublicId),
        };

        $lookup = match ($kind) {
            'regions' => $this->store->regions(),
            'markets' => $this->store->markets(),
            'industries' => $this->store->industries(),
            default => $this->store->applications(),
        };

        $codes = [];
        foreach ($publicIds as $publicId) {
            foreach ($lookup as $row) {
                if (($row['publicId'] ?? '') === $publicId) {
                    $codes[] = is_string($row['code'] ?? null) ? $row['code'] : '';
                    break;
                }
            }
        }

        return $codes;
    }

    private function toVariantSummary(\TriCab\Core\Product\Domain\Entity\ProductVariant $variant): ProductVariantSummary
    {
        return new ProductVariantSummary(
            publicId: $variant->publicId()->toString(),
            variantName: $variant->variantName(),
            sku: $variant->sku()?->toString(),
            variantCode: $variant->variantCode()?->toString(),
            statusCode: $variant->status()->toString(),
            displayOrder: $variant->displayOrder()->toInt(),
            isActive: $variant->isActive(),
        );
    }
}
