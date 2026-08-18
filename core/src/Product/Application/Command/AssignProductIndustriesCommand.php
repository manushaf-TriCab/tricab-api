<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AssignProductIndustriesCommand
{
    /**
     * @param list<string> $industryPublicIds
     */
    public function __construct(
        public string $productPublicId,
        public array $industryPublicIds,
    ) {
    }
}
