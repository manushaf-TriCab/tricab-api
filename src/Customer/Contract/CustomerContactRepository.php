<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Contract;

use TriCab\Core\Customer\Application\DTO\ContactListCriteria;
use TriCab\Core\Customer\Application\DTO\ContactListResult;
use TriCab\Core\Customer\Application\DTO\CustomerContactDetails;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactPublicId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Customer\Domain\ValueObject\LegacyContactId;
use TriCab\Core\Customer\Domain\ValueObject\PortalAccessStatus;
use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface CustomerContactRepository
{
    public function listByCustomer(CustomerId $customerId, ContactListCriteria $criteria): ContactListResult;

    public function findDetailsByPublicId(CustomerContactPublicId $publicId): ?CustomerContactDetails;

    public function findIdByPublicId(CustomerContactPublicId $publicId): ?CustomerContactId;

    public function create(
        CustomerId $customerId,
        string $displayName,
        ?EmailAddress $email,
        ?string $position,
        ?string $phone,
        ?string $mobile,
        bool $isPrimary,
        bool $isActive,
        PortalAccessStatus $portalAccessStatus,
        ?LegacyContactId $legacyContactId,
        UserId $actorUserId,
    ): CustomerContactDetails;

    public function update(
        CustomerContactPublicId $publicId,
        string $displayName,
        ?EmailAddress $email,
        ?string $position,
        ?string $phone,
        ?string $mobile,
        UserId $actorUserId,
    ): CustomerContactDetails;

    /**
     * Activates or deactivates a contact record (IsActive).
     */
    public function changeStatus(
        CustomerContactPublicId $publicId,
        bool $isActive,
        UserId $actorUserId,
    ): CustomerContactDetails;

    public function changePortalAccessStatus(
        CustomerContactPublicId $publicId,
        PortalAccessStatus $status,
        UserId $actorUserId,
    ): CustomerContactDetails;

    /**
     * @return list<CustomerContactDetails>
     */
    public function findByNormalizedEmail(EmailAddress $email, ?CustomerId $customerScope = null): array;
}
