<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\VariantLegacyPricingIdentityMapping;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;

/**
 * Explicit variant → legacy tc.price.code mappings via ExternalEntityMappings.
 */
interface VariantLegacyPricingIdentityRepository
{
    public function findByVariantPublicId(ProductVariantPublicId $publicId): ?VariantLegacyPricingIdentityMapping;

    public function findByVariantId(ProductVariantId $variantId): ?VariantLegacyPricingIdentityMapping;

    /**
     * @param list<int> $variantIds
     * @return array<int, VariantLegacyPricingIdentityMapping> keyed by ProductVariantId
     */
    public function findByVariantIds(array $variantIds): array;

    public function upsert(
        ProductVariantId $variantId,
        ProductVariantPublicId $variantPublicId,
        string $legacyPriceCode,
        UserId $actorUserId,
    ): VariantLegacyPricingIdentityMapping;

    public function deactivate(ProductVariantId $variantId, UserId $actorUserId): void;
}
