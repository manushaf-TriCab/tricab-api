<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Contract;

use TriCab\Core\Finance\Ar\Domain\ArAssignee;

/**
 * Who a record can be assigned to (decision D43): active platform users holding
 * `FINANCE.AR.RECORDS.WRITE` through an active role, once each however many roles reach
 * them. A platform `ADMINISTRATOR` holding only `FINANCE.ADMIN` is not one (decision D3).
 */
interface ArAssigneeDirectory
{
    /**
     * @return list<ArAssignee> by name, then id
     */
    public function assignees(): array;

    public function find(int $userId): ?ArAssignee;
}
