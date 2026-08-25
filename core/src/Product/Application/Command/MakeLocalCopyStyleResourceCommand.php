<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class MakeLocalCopyStyleResourceCommand
{
    public function __construct(
        public string $stylePublicId,
        public string $resourceType,
        public string $expectedRowVersion,
        public bool $confirmed = false,
    ) {
    }
}
