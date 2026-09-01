<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Admin edit aggregate snapshot (still excludes full media binaries / pricing unless loaded separately).
 */
final readonly class ProductAdminDetail
{
    /**
     * @param list<ProductVariantSummary> $variants
     * @param list<string> $regionCodes
     * @param list<string> $marketCodes
     * @param list<string> $industryCodes
     * @param list<string> $applicationCodes
     */
    public function __construct(
        public string $publicId,
        public string $productName,
        public ?string $internalName,
        public ?string $internalCode,
        public ?string $shortDescription,
        public ?string $fullDescription,
        public ?string $familyPublicId,
        public ?string $categoryPublicId,
        public ?string $seriesPublicId,
        public string $statusCode,
        public string $lifecycleStatusCode,
        public bool $isFeatured,
        public ?string $publishAtUtc,
        public ?string $unpublishAtUtc,
        public ?string $discontinueAtUtc,
        public ?string $erpReference,
        public ?string $legacyProductId,
        public array $variants,
        public array $regionCodes,
        public array $marketCodes,
        public array $industryCodes,
        public array $applicationCodes,
        public ?string $primarySlug,
        public bool $isActive,
        public bool $isArchived,
        public string $createdAtUtc,
        public string $updatedAtUtc,
        public ?string $createdByDisplayName,
        public ?string $updatedByDisplayName,
        public string $rowVersion,
        public ?string $thumbnailBackgroundColor = null,
    ) {
    }
}
