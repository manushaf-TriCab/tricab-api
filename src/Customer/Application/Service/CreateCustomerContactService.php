<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Application\Service;

use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Application\Exception\CustomerNotFound;
use TriCab\Core\Customer\Contract\CustomerContactRepository;
use TriCab\Core\Customer\Contract\CustomerRepository;
use TriCab\Core\Customer\Domain\Exception\InvalidCustomerValue;
use TriCab\Core\Customer\Domain\ValueObject\CustomerPublicId;
use TriCab\Core\Customer\Domain\ValueObject\LegacyContactId;
use TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

final class CreateCustomerContactService
{
    public function __construct(
        private readonly CustomerContactRepository $contacts,
        private readonly CustomerRepository $customers,
    ) {
    }

    public function execute(
        ActorContext $actor,
        string $customerPublicId,
        string $displayName,
        ?string $email = null,
        ?string $position = null,
        ?string $phone = null,
        ?string $mobile = null,
        bool $isPrimary = false,
        bool $isActive = true,
        ?int $legacyContactId = null,
    ): CustomerContactDetails {
        $customerId = $this->customers->findIdByPublicId(CustomerPublicId::fromString($customerPublicId));

        if ($customerId === null) {
            throw CustomerNotFound::forPublicId($customerPublicId);
        }

        $name = self::requireDisplayName($displayName);
        $emailAddress = $email !== null ? EmailAddress::fromString($email) : null;
        $legacyId = $legacyContactId !== null ? LegacyContactId::fromInt($legacyContactId) : null;

        return $this->contacts->create(
            customerId: $customerId,
            displayName: $name,
            email: $emailAddress,
            position: $position,
            phone: $phone,
            mobile: $mobile,
            isPrimary: $isPrimary,
            isActive: $isActive,
            portalAccessStatus: PortalAccessStatus::notInvited(),
            legacyContactId: $legacyId,
            actorUserId: UserId::fromInt($actor->userId),
        );
    }

    public static function requireDisplayName(string $displayName): string
    {
        $normalized = trim($displayName);
        if ($normalized === '') {
            throw InvalidCustomerValue::for('DisplayName', $displayName);
        }

        return $normalized;
    }
}
