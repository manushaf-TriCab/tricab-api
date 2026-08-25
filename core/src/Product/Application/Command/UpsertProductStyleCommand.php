<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpsertProductStyleCommand
{
    public function __construct(
        public string $styleCode,
        public string $displayName,
        public ?string $parentPublicId = null,
        public int $sortOrder = 0,
        public bool $isActive = true,
        public ?string $expectedRowVersion = null,
        public ?string $stylePublicId = null,
    ) {
    }
}
