<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Admin-only pricing dashboard counts for a product.
 */
final readonly class ProductPricingOverview
{
    public function __construct(
        public int $variantCount,
        public int $variantsWithPricing,
        public int $missingBaseCostCount,
        public int $currentRecordCount,
        public int $scheduledRecordCount,
        public int $expiredRecordCount,
        public int $inactiveRecordCount,
    ) {
    }
}
