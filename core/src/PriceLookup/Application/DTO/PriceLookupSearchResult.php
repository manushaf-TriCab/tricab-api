<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\DTO;

final readonly class PriceLookupSearchResult
{
    /**
     * @param list<PriceLookupSearchHit> $items
     */
    public function __construct(
        public array $items,
        public int $count,
        public string $company,
        public string $query,
    ) {
    }
}
