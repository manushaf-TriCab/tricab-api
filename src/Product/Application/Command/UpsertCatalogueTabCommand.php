<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpsertCatalogueTabCommand
{
    public function __construct(
        public string $tabName,
        public string $tabCode,
        public string $slug,
        public ?string $description = null,
        public int $displayOrder = 0,
        public bool $isActive = true,
        public bool $isPublic = true,
        public ?string $expectedRowVersion = null,
        public ?string $tabPublicId = null,
    ) {
    }
}
