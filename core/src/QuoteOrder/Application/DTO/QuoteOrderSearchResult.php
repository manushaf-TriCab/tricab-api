<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

final readonly class QuoteOrderSearchResult
{
    /**
     * @param list<QuoteOrderSummary> $items
     */
    public function __construct(
        public array $items,
        public int $page,
        public int $pageSize,
        public int $total,
    ) {
    }
}
