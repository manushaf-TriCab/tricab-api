<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\DTO;

final readonly class CustomerSummaryCounts
{
    public function __construct(
        public int $totalCustomers,
        public int $activeCustomers,
        public int $inactiveCustomers,
        public int $portalEnabledCustomers,
    ) {
    }
}
