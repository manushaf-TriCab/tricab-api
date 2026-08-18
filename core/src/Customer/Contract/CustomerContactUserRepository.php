<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Contract;

use TriCab\Core\Customer\Application\DTO\CustomerContactUserLinkDetails;
use TriCab\Core\Customer\Domain\ValueObject\CustomerContactId;
use TriCab\Core\Customer\Domain\ValueObject\CustomerId;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

interface CustomerContactUserRepository
{
    public function link(
        CustomerContactId $contactId,
        UserId $userId,
        bool $isPrimary,
        ?UserId $createdByUserId,
    ): CustomerContactUserLinkDetails;

    public function unlink(CustomerContactId $contactId, UserId $userId): void;

    public function hasActiveLink(CustomerContactId $contactId, UserId $userId): bool;

    public function findActivePrimaryUser(CustomerContactId $contactId): ?UserId;

    /**
     * @return list<CustomerContactUserLinkDetails>
     */
    public function listActiveLinksForUser(UserId $userId): array;

    /**
     * @return list<CustomerContactUserLinkDetails>
     */
    public function listPortalUsersForCustomer(CustomerId $customerId): array;
}
