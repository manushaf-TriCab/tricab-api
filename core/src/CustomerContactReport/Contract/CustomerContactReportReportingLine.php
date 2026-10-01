<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Contract;

use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;

/**
 * Direct manager of a staff login, resolved to an active TriCab user.
 * The identity catalogue has no manager column. Production asks Microsoft
 * Graph and then the staff directory. A null result is a broken chain, not
 * a guessed person.
 */
interface CustomerContactReportReportingLine
{
    public function isAvailable(): bool;

    public function managerOf(string $staffLogin): ?ResolvedCustomerContactAssignee;
}
