<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpsertSpecificationUnitCommand
{
    public function __construct(
        public string $name,
        public string $code,
        public string $symbol,
        public ?string $description = null,
        public int $displayOrder = 0,
        public bool $isActive = true,
        public ?string $expectedRowVersion = null,
        public ?string $unitPublicId = null,
    ) {
    }
}
