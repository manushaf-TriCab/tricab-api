<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\DTO;

final readonly class CustomerListResult
{
    /**
     * @param list<CustomerSummary> $items
     */
    public function __construct(
        public array $items,
        public Pagination $pagination,
        public CustomerSummaryCounts $counts,
    ) {
    }
}
