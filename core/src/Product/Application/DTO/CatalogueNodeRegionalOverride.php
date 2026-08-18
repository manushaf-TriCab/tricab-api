<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Future regional catalogue node overrides (not persisted yet).
 *
 * Enables per-region display name, description, sort order, and hero image without
 * duplicating the global catalogue tree.
 */
final readonly class CatalogueNodeRegionalOverride
{
    public function __construct(
        public string $nodePublicId,
        public string $regionCode,
        public ?string $displayName = null,
        public ?string $description = null,
        public ?int $sortOrder = null,
        public ?string $heroImageUrl = null,
    ) {
    }
}
