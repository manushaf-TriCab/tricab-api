<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ActivateProduct360SetCommand
{
    public function __construct(
        public string $setPublicId,
    ) {
    }
}
