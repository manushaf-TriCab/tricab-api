<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ReorderSpecificationCommand
{
    /**
     * @param list<string> $orderedPublicIds
     */
    public function __construct(
        public array $orderedPublicIds,
        public ?string $parentPublicId = null,
    ) {
    }
}
