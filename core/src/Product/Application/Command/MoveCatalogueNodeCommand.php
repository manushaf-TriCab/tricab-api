<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class MoveCatalogueNodeCommand
{
    public function __construct(
        public string $nodePublicId,
        public ?string $newParentPublicId,
        public string $expectedRowVersion,
        public ?int $displayOrder = null,
    ) {
    }
}
