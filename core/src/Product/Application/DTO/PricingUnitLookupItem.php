<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/** Admin dropdown item for pricing unit selectors. */
final readonly class PricingUnitLookupItem
{
    public function __construct(
        public string $publicId,
        public string $code,
        public string $name,
        public string $symbol,
        public int $displayOrder,
        public bool $isActive,
    ) {
    }
}
