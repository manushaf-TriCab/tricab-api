<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/** Someone who may complete an approval request: an active platform user holding `FINANCE.AP.APPROVALS.DECIDE`. The address is lower-case. */
final readonly class ApApprover
{
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
    ) {
    }
}
