<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class CreateProduct360SetCommand
{
    public function __construct(
        public string $productPublicId,
        public string $title,
        public int $expectedFrameCount,
        public int $displayOrder = 0,
    ) {
    }
}
