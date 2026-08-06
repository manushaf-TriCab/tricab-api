<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AssignProductMarketsCommand
{
    /**
     * @param list<string> $regionPublicIds
     * @param list<string> $marketPublicIds
     */
    public function __construct(
        public string $productPublicId,
        public array $regionPublicIds,
        public array $marketPublicIds,
    ) {
    }
}
