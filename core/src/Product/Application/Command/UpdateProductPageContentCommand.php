<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

use TriCab\Core\Product\Domain\ValueObject\ProductPageContent;

final readonly class UpdateProductPageContentCommand
{
    public function __construct(
        public string $productPublicId,
        public ProductPageContent $content,
        public ?string $expectedVersion,
    ) {
    }
}
