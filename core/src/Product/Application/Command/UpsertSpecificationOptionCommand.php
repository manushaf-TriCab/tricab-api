<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpsertSpecificationOptionCommand
{
    public function __construct(
        public string $definitionPublicId,
        public string $name,
        public string $code,
        public ?string $value = null,
        public int $displayOrder = 0,
        public bool $isActive = true,
        public ?string $expectedRowVersion = null,
        public ?string $optionPublicId = null,
        public ?string $imageUrl = null,
    ) {
    }
}
