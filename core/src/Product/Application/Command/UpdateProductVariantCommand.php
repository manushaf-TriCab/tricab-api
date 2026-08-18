<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

/**
 * Merge-patch update. Null means "leave the current value unchanged".
 */
final readonly class UpdateProductVariantCommand
{
    public function __construct(
        public string $publicId,
        public string $expectedRowVersion,
        public ?string $variantName = null,
        public ?string $statusCode = null,
        public ?int $displayOrder = null,
        public ?string $sku = null,
        public ?string $variantCode = null,
        public ?string $minimumOrderQuantity = null,
        public ?string $weightKg = null,
        public ?string $packagingDescription = null,
        public ?string $erpReference = null,
        public ?bool $isActive = null,
    ) {
    }
}
