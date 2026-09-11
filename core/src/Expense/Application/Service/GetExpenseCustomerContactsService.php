<?php

declare(strict_types=1);

namespace TriCab\Core\Expense\Application\Service;

use TriCab\Core\Customer\Application\DTO\ContactListCriteria;
use TriCab\Core\Customer\Application\DTO\ContactListResult;
use TriCab\Core\Customer\Application\DTO\CustomerListCriteria;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Application\Service\ListCustomerContactsService;
use TriCab\Core\Customer\Application\Service\ListCustomersService;
use TriCab\Core\Expense\Application\DTO\ExpenseActor;

final class GetExpenseCustomerContactsService
{
    public function __construct(
        private readonly ExpenseAccessService $access,
        private readonly ListCustomersService $customers,
        private readonly ListCustomerContactsService $contacts,
    ) {
    }

    public function execute(ExpenseActor $actor, string $codeOrPublicId, ContactListCriteria $criteria): ContactListResult
    {
        $this->access->requireActiveProfile($actor);
        $publicId = $this->resolveCustomerPublicId($codeOrPublicId);

        return $this->contacts->execute($publicId, $criteria);
    }

    private function resolveCustomerPublicId(string $codeOrPublicId): string
    {
        $needle = trim($codeOrPublicId);
        if ($needle === '') {
            throw CustomerNotFound::forPublicId($codeOrPublicId);
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $needle) === 1) {
            return strtolower($needle);
        }

        $result = $this->customers->execute(new CustomerListCriteria(
            page: 1,
            pageSize: 25,
            search: $needle,
            sortBy: CustomerListCriteria::SORT_CODE,
            sortDirection: CustomerListCriteria::SORT_ASC,
        ));
        foreach ($result->items as $item) {
            if ($item->customerCode !== null && strcasecmp($item->customerCode, $needle) === 0) {
                return $item->publicId;
            }
        }

        throw CustomerNotFound::forPublicId($needle);
    }
}
