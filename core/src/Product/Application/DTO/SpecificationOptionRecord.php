<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class SpecificationOptionRecord
{
    public function __construct(
        public string $publicId,
        public string $definitionPublicId,
        public string $code,
        public string $name,
        public ?string $value,
        public int $displayOrder,
        public bool $isActive,
        public bool $isDeleted,
        public string $rowVersion,
        public ?string $createdAtUtc = null,
        public ?string $updatedAtUtc = null,
        public int $valueUsageCount = 0,
        public ?string $imageUrl = null,
    ) {
    }
}
