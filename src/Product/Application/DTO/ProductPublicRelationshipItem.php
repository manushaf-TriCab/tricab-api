<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Public-safe product relationship summary (no internal IDs, no pricing).
 */
final readonly class ProductPublicRelationshipItem
{
    public function __construct(
        public string $relatedProductPublicId,
        public string $relatedProductName,
        public string $relationshipTypeCode,
        public ?string $relatedSlug,
        public int $displayOrder,
    ) {
    }
}
