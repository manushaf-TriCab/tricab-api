<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class CatalogueNodeResolvedProductRecord
{
    public function __construct(
        public string $productPublicId,
        public string $productName,
        public ?string $productCode,
        public string $provenance,
        public ?string $resolvedViaStyleCode,
        public ?string $linkedStyleCode,
        public string $publicationStatus,
        public bool $publicVisible,
        public bool $regionVisible,
        public int $displayOrder,
    ) {
    }
}
