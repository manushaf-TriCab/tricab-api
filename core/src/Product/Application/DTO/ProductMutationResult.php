<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Generic mutation acknowledgement for Product and ProductVariant aggregates.
 */
final readonly class ProductMutationResult
{
    public function __construct(
        public string $publicId,
        public string $rowVersion,
        public string $statusCode,
        public ?string $createdAtUtc,
        public string $updatedAtUtc,
    ) {
    }
}
