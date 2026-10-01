<?php

declare(strict_types=1);

namespace TriCab\Api\CustomerContactReport\Identity;

use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine;

/**
 * Identity users have no manager column. Credit escalation must fail closed
 * rather than accept a guessed approver.
 */
final class UnavailableCustomerContactReportReportingLine implements CustomerContactReportReportingLine
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function managerOf(string $staffLogin): ?ResolvedCustomerContactAssignee
    {
        unset($staffLogin);

        return null;
    }
}
