<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\DTO;

final readonly class PriceLookupSearchHit
{
    public function __construct(
        public string $productCode,
        public ?string $status = null,
        public ?string $setupLength = null,
        public ?string $lastUpdated = null,
    ) {
    }
}
