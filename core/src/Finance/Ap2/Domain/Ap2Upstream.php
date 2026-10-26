<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** Something Tri-AP2 depends on (the mailbox, the mail service) failed (502). */
final class Ap2Upstream extends Ap2Failure
{
    public function httpStatus(): int
    {
        return 502;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP2_UPSTREAM_FAILED';
    }
}
