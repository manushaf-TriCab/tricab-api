<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class OptimiseProduct3DModelCommand
{
    public function __construct(
        public string $productPublicId,
        public string $modelPublicId,
        public string $expectedRowVersion,
        public string $profile = 'web-standard',
        public ?string $localInputPath = null,
    ) {
    }
}
