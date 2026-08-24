<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class VerifyVariantLegacyPricingIdentityCommand
{
    public function __construct(
        public string $variantPublicId,
        public string $legacyPriceCode,
        public string $priceListPublicId,
    ) {
    }
}
