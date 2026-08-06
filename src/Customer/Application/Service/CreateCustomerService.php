<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerDetails;
use TriCab\Core\Customer\Application\Exception\CustomerCodeConflict;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\Customer\Domain\ValueObject\CustomerCode;
use TriCab\Core\Customer\Domain\ValueObject\CustomerStatus;
use TriCab\Core\Customer\Domain\ValueObject\LegacyCustomerId;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class CreateCustomerService
{
    public function __construct(
        private readonly CustomerRepository $customers,
    ) {
    }

    public function execute(
        ActorContext $actor,
        string $customerName,
        ?string $customerCode = null,
        string $status = CustomerStatus::ACTIVE,
        ?string $addressLine1 = null,
        ?string $addressLine2 = null,
        ?string $suburb = null,
        ?string $postcode = null,
        ?string $countryCode = null,
        ?string $marketCode = null,
        ?string $salesRep = null,
        ?string $territory = null,
        ?string $phone = null,
        ?int $legacyCustomerId = null,
    ): CustomerDetails {
        $name = self::requireCustomerName($customerName);
        $customerStatus = CustomerStatus::fromString($status);
        $code = $customerCode !== null ? CustomerCode::fromString($customerCode) : null;
        $legacyId = $legacyCustomerId !== null ? LegacyCustomerId::fromInt($legacyCustomerId) : null;

        if ($code !== null && $this->customers->customerCodeExists($code)) {
            throw CustomerCodeConflict::forCode($code->toString());
        }

        return $this->customers->create(
            customerCode: $code,
            customerName: $name,
            status: $customerStatus,
            addressLine1: $addressLine1,
            addressLine2: $addressLine2,
            suburb: $suburb,
            postcode: $postcode,
            countryCode: $countryCode,
            marketCode: $marketCode,
            salesRep: $salesRep,
            territory: $territory,
            phone: $phone,
            legacyCustomerId: $legacyId,
            actorUserId: UserId::fromInt($actor->userId),
        );
    }

    public static function requireCustomerName(string $customerName): string
    {
        $normalized = trim($customerName);
        if ($normalized === '') {
            throw InvalidCustomerValue::for('CustomerName', $customerName);
        }

        return $normalized;
    }
}
