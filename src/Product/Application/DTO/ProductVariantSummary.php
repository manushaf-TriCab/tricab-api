<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductVariantSummary
{
    public function __construct(
        public string $publicId,
        public string $variantName,
        public ?string $sku,
        public ?string $variantCode,
        public string $statusCode,
        public int $displayOrder,
        public bool $isActive,
    ) {
    }
}
