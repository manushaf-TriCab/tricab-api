<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AnalyseProduct3DModelCommand
{
    public function __construct(
        public string $productPublicId,
        public string $modelPublicId,
        public ?string $expectedRowVersion = null,
        /** Optional local path override for InMemory/tests when blob bytes are absent. */
        public ?string $localInputPath = null,
    ) {
    }
}
