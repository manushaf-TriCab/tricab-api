<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ImportSpecificationDefinitionsCommand
{
    /**
     * @param list<array<string, mixed>> $definitions
     */
    public function __construct(
        public array $definitions,
    ) {
    }
}
