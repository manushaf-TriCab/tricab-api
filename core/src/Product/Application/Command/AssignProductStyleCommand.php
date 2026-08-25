<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AssignProductStyleCommand
{
    public function __construct(
        public string $productPublicId,
        public ?string $stylePublicId,
    ) {
    }
}
