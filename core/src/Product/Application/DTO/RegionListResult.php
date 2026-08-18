<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class RegionListResult
{
    /** @param list<RegionRecord> $items */
    public function __construct(
        public array $items,
        public Pagination $pagination,
    ) {
    }
}
