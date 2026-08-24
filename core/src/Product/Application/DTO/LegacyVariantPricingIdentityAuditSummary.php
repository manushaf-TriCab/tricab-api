<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class LegacyVariantPricingIdentityAuditSummary
{
    /**
     * @param array<string, int> $byDbco
     * @param list<string> $warnings
     */
    public function __construct(
        public int $variantsScanned,
        public int $explicitCount,
        public int $exactCount,
        public int $maskCount,
        public int $unresolvedCount,
        public int $ambiguousCount,
        public int $invalidExplicitCount,
        public int $zeroCostCount,
        public int $priceRevisionAmbiguousCount,
        public int $systemDefaultUomCount,
        public int $noPriceListContextCount,
        public array $byDbco,
        public int $legacyQueryCount,
        public float $executionSeconds,
        public array $warnings,
    ) {
    }
}
