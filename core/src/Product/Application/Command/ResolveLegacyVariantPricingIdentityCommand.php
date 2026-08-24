<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ResolveLegacyVariantPricingIdentityCommand
{
    public function __construct(
        public string $variantPublicId,
        public string $priceListPublicId,
    ) {
    }
}
