<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class RevertProduct3DAssetToSourceCommand
{
    public function __construct(
        public string $productPublicId,
        public string $modelPublicId,
        public string $expectedRowVersion,
    ) {
    }
}
