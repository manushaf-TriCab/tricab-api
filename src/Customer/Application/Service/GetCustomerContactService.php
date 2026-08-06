<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\Exception\CustomerContactNotFound;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;

final class GetCustomerContactService
{
    public function __construct(
        private readonly CustomerContactRepository $contacts,
    ) {
    }

    public function execute(string $contactPublicId): CustomerContactDetails
    {
        $id = CustomerContactPublicId::fromString($contactPublicId);
        $details = $this->contacts->findDetailsByPublicId($id);

        if ($details === null) {
            throw CustomerContactNotFound::forPublicId($id->toString());
        }

        return $details;
    }
}
