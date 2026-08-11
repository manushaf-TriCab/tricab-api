<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class CatalogueTabRecord
{
    public function __construct(
        public string $publicId,
        public string $tabCode,
        public string $tabName,
        public ?string $description,
        public string $slug,
        public int $displayOrder,
        public bool $isActive,
        public bool $isPublic,
        public bool $isDeleted,
        public string $rowVersion,
        public ?string $createdAtUtc = null,
        public ?string $updatedAtUtc = null,
        public int $rootNodeCount = 0,
    ) {
    }
}
