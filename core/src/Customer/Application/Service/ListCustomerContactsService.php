<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\ContactListCriteria;
use TriCab\Core\Customer\Application\DTO\ContactListResult;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;

final class ListCustomerContactsService
{
    public function __construct(
        private readonly CustomerContactRepository $contacts,
        private readonly CustomerRepository $customers,
    ) {
    }

    public function execute(string $customerPublicId, ContactListCriteria $criteria): ContactListResult
    {
        $publicId = CustomerPublicId::fromString($customerPublicId);
        $customerId = $this->customers->findIdByPublicId($publicId);

        if ($customerId === null) {
            throw CustomerNotFound::forPublicId($publicId->toString());
        }

        return $this->contacts->listByCustomer($customerId, $criteria);
    }
}
