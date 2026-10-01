<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

use TriCab\Core\Product\Domain\ValueObject\DescriptionImagePresentation;

final readonly class UpdateProductPageMediaLayoutCommand
{
    public function __construct(
        public string $productPublicId,
        public DescriptionImagePresentation $presentation,
    ) {
    }
}
