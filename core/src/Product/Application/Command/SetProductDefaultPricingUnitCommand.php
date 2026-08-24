<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class SetProductDefaultPricingUnitCommand
{
    public function __construct(
        public string $productPublicId,
        /** Null clears the product default (falls back to system metre). */
        public ?string $pricingUnitPublicId,
    ) {
    }
}
