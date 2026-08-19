<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Admin-only base-cost record summary.
 * Maps from product.ProductPrices / product.ProductVariantPrices.UnitPrice (base cost semantics).
 * Never embed in ProductPublicDetail.
 */
final readonly class ProductPricingSummary
{
    public function __construct(
        public string $publicId,
        public string $recordType,
        public ?string $variantPublicId,
        public ?string $variantName,
        public ?string $variantCode,
        public string $currencyCode,
        public string $baseCost,
        public string $minimumQuantity,
        public ?string $priceListCode,
        public ?string $priceListPublicId,
        public ?string $regionPublicId,
        public ?string $marketPublicId,
        public ?string $unitOfMeasureCode,
        public ?string $priceSourceCode,
        public ?string $effectiveFromUtc,
        public ?string $effectiveToUtc,
        public string $status,
        public bool $isActive,
        public ?string $rowVersion,
        public ?string $legacyItemCode,
        public ?string $erpSourceReference,
        public ?string $lastSourceUpdatedAtUtc,
    ) {
    }

    /** @deprecated Use baseCost — retained for internal migration from legacy naming. */
    public function unitPrice(): string
    {
        return $this->baseCost;
    }
}
