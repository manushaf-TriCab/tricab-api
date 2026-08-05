<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\ProductAdminDetail;
use TriCab\Core\Product\Application\DTO\ProductListCriteria;
use TriCab\Core\Product\Application\DTO\ProductListResult;
use TriCab\Core\Product\Application\DTO\ProductVariantSummary;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

/**
 * Query repository for admin product reads (DTO / read models only).
 *
 * Default behaviour excludes soft-deleted records unless criteria.includeDeleted.
 * Archived products are included unless filtered out (status / isArchived).
 */
interface ProductAdminQueryRepository
{
    public function list(ProductListCriteria $criteria): ProductListResult;

    public function findDetailByPublicId(ProductPublicId $publicId): ?ProductAdminDetail;

    /**
     * @return list<ProductVariantSummary>
     */
    public function listVariantSummariesByProduct(
        ProductPublicId $productPublicId,
        bool $includeArchived = true,
        bool $includeDeleted = false,
    ): array;

    public function findVariantSummaryByPublicId(
        ProductVariantPublicId $publicId,
        bool $includeDeleted = false,
    ): ?ProductVariantSummary;
}
