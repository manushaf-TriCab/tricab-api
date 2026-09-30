<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductCustomerViewMatrixRow
{
    /**
     * @param array<string, string|null> $cells keyed by definitionPublicId
     */
    public function __construct(
        public string $publicId,
        public string $variantName,
        public ?string $sku,
        public ?string $variantCode,
        public string $statusCode,
        public int $displayOrder,
        public bool $isActive,
        public array $cells,
        /** Display colour (CODE_JACKET_COLOUR option label); flat layout only. Not a technical column. */
        public ?string $colour = null,
        /** ProductVariants.PackagingDescription (commercial data, not a specification); flat layout only. */
        public ?string $packSize = null,
    ) {
    }
}
