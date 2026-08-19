<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Historical base-cost revision (admin-only).
 */
final readonly class ProductBaseCostHistoryEntry
{
    public function __construct(
        public string $publicId,
        public string $recordType,
        public ?string $variantPublicId,
        public ?string $variantName,
        public string $currencyCode,
        public string $baseCost,
        public ?string $unitOfMeasureCode,
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
