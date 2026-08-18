<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\Entity\ProductVariant;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Domain\ValueObject\Sku;

/**
 * Command repository for the ProductVariant aggregate root.
 *
 * Future Azure SQL: product.ProductVariants.
 */
interface ProductVariantRepository
{
    public function findByPublicId(ProductVariantPublicId $publicId): ?ProductVariant;

    public function findById(ProductVariantId $variantId): ?ProductVariant;

    public function findIdByPublicId(ProductVariantPublicId $publicId): ?ProductVariantId;

    public function skuExists(Sku $sku, ?ProductVariantPublicId $exceptPublicId = null): bool;

    public function findRowVersion(ProductVariantPublicId $publicId): ?RowVersionToken;

    public function save(
        ProductVariant $variant,
        UserId $actorUserId,
        ?RowVersionToken $expectedRowVersion = null,
    ): ProductVariant;
}
