<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class CreateProductCommand
{
    public function __construct(
        public string $productName,
        public ?string $internalName = null,
        public ?string $internalCode = null,
        public ?string $shortDescription = null,
        public ?string $fullDescription = null,
        public ?string $legacyProductId = null,
        public ?string $erpReference = null,
        public bool $isFeatured = false,
        public ?string $familyPublicId = null,
        public ?string $categoryPublicId = null,
        public ?string $seriesPublicId = null,
        public string $statusCode = 'draft',
        public string $lifecycleStatusCode = 'concept',
        public ?string $publishAtUtc = null,
        public ?string $unpublishAtUtc = null,
        public ?string $discontinueAtUtc = null,
        public bool $isActive = true,
    ) {
    }
}
