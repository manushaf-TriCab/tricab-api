<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerListCriteria;
use TriCab\Core\Customer\Application\DTO\CustomerListResult;
use TriCab\Core\Customer\Contract\CustomerRepository;

final class ListCustomersService
{
    public function __construct(
        private readonly CustomerRepository $customers,
    ) {
    }

    public function execute(CustomerListCriteria $criteria): CustomerListResult
    {
        return $this->customers->list($criteria);
    }
}
