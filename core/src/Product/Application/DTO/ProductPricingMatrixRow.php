<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Variant-first pricing row for admin matrix views.
 */
final readonly class ProductPricingMatrixRow
{
    public function __construct(
        public string $variantPublicId,
        public string $variantName,
        public ?string $variantCode,
        public ?string $sku,
        public int $displayOrder,
        public ?string $legacyItemCode,
        public ?string $currentPricingPublicId,
        public ?string $currencyCode,
        public ?string $baseCost,
        public ?string $unitOfMeasureCode,
        public ?string $unitOfMeasureSymbol,
        public ?string $priceListCode,
        public ?string $priceSourceCode,
        public ?string $effectiveFromUtc,
        public ?string $effectiveToUtc,
        public string $status,
        public bool $isActive,
        public ?string $rowVersion,
    ) {
    }
}
