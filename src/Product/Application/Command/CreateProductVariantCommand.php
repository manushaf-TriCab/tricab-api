<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class CreateProductVariantCommand
{
    public function __construct(
        public string $productPublicId,
        public string $variantName,
        public string $statusCode = 'draft',
        public int $displayOrder = 0,
        public ?string $sku = null,
        public ?string $variantCode = null,
        public ?string $minimumOrderQuantity = null,
        public ?string $weightKg = null,
        public ?string $packagingDescription = null,
        public ?string $erpReference = null,
        public bool $isActive = true,
    ) {
    }
}
