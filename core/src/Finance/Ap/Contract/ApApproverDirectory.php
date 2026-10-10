<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Contract;

use TriCab\Core\Finance\Ap\Domain\ApApprover;

/**
 * Who may complete an approval request (question Q10), read by platform user id from `[identity]` and never from a local
 * users table: the active people who hold both `FINANCE.AP.ACCESS` and `FINANCE.AP.APPROVALS.DECIDE` through an active,
 * unexpired role, once each however many roles reach them. A platform `ADMINISTRATOR` holding only `FINANCE.ADMIN` is not one
 * (decision D3). Under D83 that is the Administrators, since nobody holds the Approver role.
 */
interface ApApproverDirectory
{
    /**
     * @return list<ApApprover> by display name, then id
     */
    public function approvers(): array;
}
