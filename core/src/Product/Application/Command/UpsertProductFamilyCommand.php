<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpsertProductFamilyCommand
{
    public function __construct(
        public string $name,
        public string $code,
        public ?string $description = null,
        public int $displayOrder = 0,
        public bool $isActive = true,
        public ?string $expectedRowVersion = null,
        public ?string $familyPublicId = null,
    ) {
    }
}
