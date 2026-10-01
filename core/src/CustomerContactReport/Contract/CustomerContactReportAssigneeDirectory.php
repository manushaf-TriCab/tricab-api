<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Contract;

use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;

interface CustomerContactReportAssigneeDirectory
{
    /**
     * Resolve a staff login. The login is an email address, which is the
     * identity key. Display name is never the authorization identity.
     */
    public function findActiveStaffByLogin(string $login): ?ResolvedCustomerContactAssignee;
}
