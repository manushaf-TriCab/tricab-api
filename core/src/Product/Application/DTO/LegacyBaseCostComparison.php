<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Read-only comparison between new-system base cost and legacy tc.price.cost.
 */
final readonly class LegacyBaseCostComparison
{
    public function __construct(
        public string $variantPublicId,
        public ?string $variantName,
        public ?string $legacyItemCode,
        public ?string $legacyPriceCode,
        public ?string $newBaseCost,
        public ?string $newCurrencyCode,
        public ?string $newPriceSourceCode,
        public ?string $legacyBaseCost,
        public ?string $legacyCurrencyCode,
        public ?int $legacyDebtorCompanyCode,
        public ?string $legacyLastUpdatedUtc,
        public ?string $differenceAmount,
        public bool $legacyAvailable,
        public ?string $message,
        public ?string $resolutionStatus = null,
        public ?string $resolutionSource = null,
        public ?string $priceDataStatus = null,
    ) {
    }
}
