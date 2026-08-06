<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Admin-only pricing summary. Never embed in ProductPublicDetail.
 */
final readonly class ProductPricingSummary
{
    public function __construct(
        public string $publicId,
        public string $currencyCode,
        public string $unitPrice,
        public string $minimumQuantity,
        public ?string $priceListCode,
        public ?string $effectiveFromUtc,
        public ?string $effectiveToUtc,
    ) {
    }
}
