<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ReplaceProductCataloguePlacementsCommand
{
    /**
     * @param list<array{nodePublicId: string, displayOrder?: int, isFeatured?: bool, isActive?: bool}> $placements
     */
    public function __construct(
        public string $productPublicId,
        public array $placements,
    ) {
    }
}
