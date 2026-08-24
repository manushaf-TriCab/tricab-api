<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class VariantLegacyPricingIdentityMapping
{
    public function __construct(
        public string $publicId,
        public string $variantPublicId,
        public string $legacyPriceCode,
        public bool $isActive,
    ) {
    }
}
