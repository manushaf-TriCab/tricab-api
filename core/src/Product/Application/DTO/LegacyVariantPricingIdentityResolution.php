<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class LegacyVariantPricingIdentityResolution
{
    /**
     * @param list<string> $maskCandidates
     * @param list<string> $warnings
     */
    public function __construct(
        public string $variantPublicId,
        public ?string $variantCode,
        public ?string $priceListPublicId,
        public ?int $legacyDbco,
        public string $resolutionStatus,
        public ?string $legacyPriceCode,
        public string $resolutionSource,
        public ?string $explicitMappingCode,
        public ?string $exactCandidateCode,
        public array $maskCandidates,
        public ?LegacyPricingCodeInspection $legacyMatch,
        public ?string $priceDataStatus,
        public array $warnings,
    ) {
    }
}
