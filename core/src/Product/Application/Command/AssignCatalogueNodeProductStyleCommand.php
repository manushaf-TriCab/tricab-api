<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AssignCatalogueNodeProductStyleCommand
{
    public function __construct(
        public string $nodePublicId,
        public string $productStylePublicId,
        public int $displayOrder = 10,
    ) {
    }
}
