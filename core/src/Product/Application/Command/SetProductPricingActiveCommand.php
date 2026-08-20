<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class SetProductPricingActiveCommand
{
    public function __construct(
        public string $productPublicId,
        public string $pricingPublicId,
        public bool $isActive,
        public ?string $expectedRowVersion = null,
    ) {
    }
}
