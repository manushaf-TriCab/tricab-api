<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Application\DTO;

/**
 * Presentation media for a cart line. Identifiers are public IDs only.
 */
final readonly class CartLineMedia
{
    public function __construct(
        public ?string $productPublicId,
        public ?string $imageUrl,
        public ?string $mediaPublicId = null,
        public ?string $storageContainer = null,
        public ?string $storagePath = null,
    ) {
    }
}
