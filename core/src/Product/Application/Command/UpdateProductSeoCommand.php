<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpdateProductSeoCommand
{
    public function __construct(
        public string $productPublicId,
        public ?string $regionPublicId = null,
        public ?string $pageTitle = null,
        public ?string $metaDescription = null,
        public ?string $canonicalUrl = null,
        public ?string $openGraphTitle = null,
        public ?string $openGraphDescription = null,
        public ?string $openGraphImageUrl = null,
        public ?string $searchKeywords = null,
    ) {
    }
}
