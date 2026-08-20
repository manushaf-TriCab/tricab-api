<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpsertProductPricingCommand
{
    public function __construct(
        public string $productPublicId,
        public string $currencyCode,
        public string $baseCost,
        public string $minimumQuantity,
        public ?string $priceListPublicId = null,
        public ?string $regionPublicId = null,
        public ?string $marketPublicId = null,
        public ?string $discountPercent = null,
        public bool $taxInclusive = false,
        public ?string $effectiveFromUtc = null,
        public ?string $effectiveToUtc = null,
        public ?string $variantPublicId = null,
        public ?string $priceSourceCode = null,
        public bool $createRevision = false,
        public ?string $pricingPublicId = null,
        public ?string $expectedRowVersion = null,
    ) {
    }
}
