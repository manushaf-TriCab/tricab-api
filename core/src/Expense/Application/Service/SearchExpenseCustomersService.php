<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerListCriteria;
use TriCab\Core\Customer\Application\DTO\CustomerListResult;
use TriCab\Core\Customer\Application\Service\ListCustomersService;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;

final class SearchExpenseCustomersService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ListCustomersService $customers,
    ) {
    }

    public function execute(ExpenseActor $actor, ?string $query, int $page = 1, int $pageSize = 25): CustomerListResult
    {
        $this->access->requireActiveProfile($actor);

        $search = $query !== null ? trim($query) : null;

        return $this->customers->execute(new CustomerListCriteria(
            page: $page,
            pageSize: $pageSize,
            search: $search !== '' ? $search : null,
            sortBy: CustomerListCriteria::SORT_NAME,
            sortDirection: CustomerListCriteria::SORT_ASC,
        ));
    }
}
