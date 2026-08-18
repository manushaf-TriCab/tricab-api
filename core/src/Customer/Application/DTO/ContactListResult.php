<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\DTO;

final readonly class ContactListResult
{
    /**
     * @param list<CustomerContactSummary> $items
     */
    public function __construct(
        public array $items,
        public Pagination $pagination,
    ) {
    }
}
