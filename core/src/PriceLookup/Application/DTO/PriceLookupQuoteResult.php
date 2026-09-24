<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\DTO;

final readonly class PriceLookupQuoteResult
{
    public function __construct(
        public string $productCode,
        public string $status,
        public string $company,
        public string $currency,
        public string $metric,
        public string $adjustedCost,
        public string $sell,
        public string $baseCost,
        public string $setupLength,
        public string $setupPremium,
        public string $lastUpdated,
        public string $costSource,
    ) {
    }
}
