<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class RemoveCatalogueNodeProductStyleCommand
{
    public function __construct(
        public string $nodePublicId,
        public string $productStylePublicId,
    ) {
    }
}
