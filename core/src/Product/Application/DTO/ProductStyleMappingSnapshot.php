<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Read-only target Product plus linked legacy style identity.
 */
final readonly class ProductStyleMappingSnapshot
{
    /**
     * @param list<string> $variantRawStyleCodes
     * @param list<string> $sourcesAvailable
     */
    public function __construct(
        public string $productPublicId,
        public string $productName,
        public ?string $productCode,
        public ?string $legacyProductId,
        public string $statusCode,
        public bool $isActive,
        public bool $isArchived,
        public ?string $assignedStylePublicId,
        public ?string $assignedStyleCode,
        public ?string $sourceKey,
        public ?string $canonicalRawStyleCode,
        public ?string $wordpressRawStyleCode,
        public ?string $tcRawStyleCode,
        public array $variantRawStyleCodes = [],
        public ?string $legacyTitle = null,
        public ?string $legacyKind = null,
        public ?string $matchMethod = null,
        public array $sourcesAvailable = [],
        public ?string $internalCode = null,
    ) {
    }
}
