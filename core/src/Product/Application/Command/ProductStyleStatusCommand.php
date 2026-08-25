<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ProductStyleStatusCommand
{
    public function __construct(
        public string $stylePublicId,
        public string $expectedRowVersion,
    ) {
    }
}
