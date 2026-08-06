<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;

final class GetCustomerService
{
    public function __construct(
        private readonly CustomerRepository $customers,
    ) {
    }

    public function execute(string $publicId): CustomerDetails
    {
        $id = CustomerPublicId::fromString($publicId);
        $details = $this->customers->findDetailsByPublicId($id);

        if ($details === null) {
            throw CustomerNotFound::forPublicId($id->toString());
        }

        return $details;
    }
}
