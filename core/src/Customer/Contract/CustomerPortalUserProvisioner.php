<?php

declare(strict_types=1);

namespace TriCab\Core\Customer\Contract;

use TriCab\Core\Identity\Domain\ValueObject\EmailAddress;
use TriCab\Core\Identity\Domain\ValueObject\RoleCode;
use TriCab\Core\Identity\Domain\ValueObject\UserId;

/**
 * Narrow port through which the Customer bounded context asks the Identity
 * bounded context to provision a new customer-portal user. Keeps Customer's
 * application services free of a direct dependency on Identity's
 * UserManagementRepository / role-assignment machinery; an Identity-side
 * adapter implements this later.
 */
interface CustomerPortalUserProvisioner
{
    public function createActivePortalUser(
        EmailAddress $email,
        string $displayName,
        RoleCode $customerRole,
        UserId $actorUserId,
    ): ProvisionedPortalUser;
}
