<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ReorderProductMediaCommand
{
    /**
     * @param list<string> $orderedMediaPublicIds
     */
    public function __construct(
        public string $productPublicId,
        public array $orderedMediaPublicIds,
    ) {
    }
}
