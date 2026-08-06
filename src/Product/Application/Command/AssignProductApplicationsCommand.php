<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AssignProductApplicationsCommand
{
    /**
     * @param list<string> $applicationPublicIds
     */
    public function __construct(
        public string $productPublicId,
        public array $applicationPublicIds,
    ) {
    }
}
