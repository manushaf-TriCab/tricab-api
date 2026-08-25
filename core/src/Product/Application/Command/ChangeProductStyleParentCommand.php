<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ChangeProductStyleParentCommand
{
    public function __construct(
        public string $stylePublicId,
        public ?string $parentPublicId,
        public string $expectedRowVersion,
    ) {
    }
}
