<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\Exception\CustomerCodeConflict;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\ValueObject\CustomerCode;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class UpdateCustomerService
{
    public function __construct(
        private readonly CustomerRepository $customers,
    ) {
    }

    public function execute(
        ActorContext $actor,
        string $publicId,
        string $customerName,
        ?string $customerCode = null,
        ?string $addressLine1 = null,
        ?string $addressLine2 = null,
        ?string $suburb = null,
        ?string $postcode = null,
        ?string $countryCode = null,
        ?string $marketCode = null,
        ?string $salesRep = null,
        ?string $territory = null,
        ?string $phone = null,
    ): CustomerDetails {
        $id = CustomerPublicId::fromString($publicId);

        if ($this->customers->findDetailsByPublicId($id) === null) {
            throw CustomerNotFound::forPublicId($id->toString());
        }

        $name = CreateCustomerService::requireCustomerName($customerName);
        $code = $customerCode !== null ? CustomerCode::fromString($customerCode) : null;

        if ($code !== null && $this->customers->customerCodeExists($code, $id)) {
            throw CustomerCodeConflict::forCode($code->toString());
        }

        return $this->customers->update(
            publicId: $id,
            customerCode: $code,
            customerName: $name,
            addressLine1: $addressLine1,
            addressLine2: $addressLine2,
            suburb: $suburb,
            postcode: $postcode,
            countryCode: $countryCode,
            marketCode: $marketCode,
            salesRep: $salesRep,
            territory: $territory,
            phone: $phone,
            actorUserId: UserId::fromInt($actor->userId),
        );
    }
}
