<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Command repository for the Product aggregate root.
 *
 * Loads and persists Product domain entities. Does not return list/detail DTOs —
 * use ProductAdminQueryRepository / ProductPublicQueryRepository for reads.
 *
 * Future Azure SQL: product.Products. Save must honour expected RowVersion.
 */
interface ProductRepository
{
    public function findByPublicId(ProductPublicId $publicId): ?Product;

    public function findById(ProductId $productId): ?Product;

    public function findIdByPublicId(ProductPublicId $publicId): ?ProductId;

    public function productCodeExists(ProductCodeValue $code, ?ProductPublicId $exceptPublicId = null): bool;

    public function internalCodeExists(string $internalCode, ?ProductPublicId $exceptPublicId = null): bool;

    public function slugExists(ProductSlug $slug, ?string $regionPublicId = null, ?ProductPublicId $exceptPublicId = null): bool;

    public function findRowVersion(ProductPublicId $publicId): ?RowVersionToken;

    /**
     * Persist a new or updated Product aggregate.
     * For updates, $expectedRowVersion is required; mismatch throws ProductConcurrencyException.
     */
    public function save(
        Product $product,
        UserId $actorUserId,
        ?RowVersionToken $expectedRowVersion = null,
    ): Product;
}
