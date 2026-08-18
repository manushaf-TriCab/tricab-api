<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class SpecificationStatusCommand
{
    public function __construct(
        public string $publicId,
        public string $expectedRowVersion,
    ) {
    }
}
