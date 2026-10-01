<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use TriCab\Core\Customer\Application\DTO\Pagination;

final readonly class CustomerContactReportListResult
{
    /**
     * @param list<CustomerContactReportDetails> $items
     * @param array<string, list<array{kind: string, status: string}>> $approvalSummaries
     */
    public function __construct(
        public array $items,
        public Pagination $pagination,
        public CustomerContactReportCounts $counts,
        public array $approvalSummaries = [],
    ) {
    }
}
