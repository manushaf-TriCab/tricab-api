<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class SpecificationGroupRecord
{
    public function __construct(
        public string $publicId,
        public string $code,
        public string $name,
        public ?string $description,
        public int $displayOrder,
        public bool $isActive,
        public bool $isDeleted,
        public string $rowVersion,
        public ?string $createdAtUtc = null,
        public ?string $updatedAtUtc = null,
        public int $definitionCount = 0,
        public int $activeDefinitionCount = 0,
    ) {
    }
}
