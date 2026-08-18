<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AssignCatalogueNodeProductsCommand
{
    /**
     * @param list<array{productPublicId: string, displayOrder?: int, isFeatured?: bool, isActive?: bool}> $placements
     */
    public function __construct(
        public string $nodePublicId,
        public array $placements,
    ) {
    }
}
