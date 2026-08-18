<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Lightweight admin list row — not a full product aggregate.
 */
final readonly class ProductAdminListItem
{
    public function __construct(
        public string $publicId,
        public string $productName,
        public ?string $internalCode,
        public ?string $primaryProductCode,
        public ?string $thumbnailUrl,
        public ?string $familyName,
        public ?string $categoryName,
        public ?string $seriesName,
        public int $variantCount,
        /** @var list<string> */
        public array $regionCodes,
        public string $statusCode,
        public string $lifecycleStatusCode,
        public string $updatedAtUtc,
        public ?string $updatedByDisplayName,
        public bool $isArchived,
        public string $rowVersion,
    ) {
    }
}
