<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

/**
 * Merge-patch update. Unless noted, null means "leave the current value
 * unchanged" — it does not clear a nullable field. Classification is the
 * exception: set $updateClassification = true to replace family/category/series
 * (including clearing them by leaving the corresponding *PublicId null).
 */
final readonly class UpdateProductCommand
{
    public function __construct(
        public string $publicId,
        public string $expectedRowVersion,
        public ?string $productName = null,
        public ?string $internalName = null,
        public ?string $internalCode = null,
        public ?string $shortDescription = null,
        public ?string $fullDescription = null,
        public ?string $legacyProductId = null,
        public ?string $erpReference = null,
        public ?bool $isFeatured = null,
        public ?string $publishAtUtc = null,
        public ?string $unpublishAtUtc = null,
        public ?string $discontinueAtUtc = null,
        public ?bool $isActive = null,
        public bool $updateClassification = false,
        public ?string $familyPublicId = null,
        public ?string $categoryPublicId = null,
        public ?string $seriesPublicId = null,
    ) {
    }
}
