<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\CustomerContactReport;

use TriCab\Core\CustomerContactReport\Application\DTO\ResolvedCustomerContactAssignee;
use TriCab\Core\CustomerContactReport\Contract\CustomerContactReportReportingLine;

/**
 * Test double. Production binds the unavailable reporting line.
 */
final class FixedCustomerContactReportReportingLine implements CustomerContactReportReportingLine
{
    /**
     * @param array<string, ResolvedCustomerContactAssignee> $managers
     */
    public function __construct(
        private readonly array $managers,
        private readonly bool $available = true,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->available;
    }

    public function managerOf(string $staffLogin): ?ResolvedCustomerContactAssignee
    {
        return $this->managers[strtolower(trim($staffLogin))] ?? null;
    }
}
