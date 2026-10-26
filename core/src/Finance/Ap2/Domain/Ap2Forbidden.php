<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** The person may not do this to this record (403). */
final class Ap2Forbidden extends Ap2Failure
{
    public function httpStatus(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP2_FORBIDDEN';
    }
}
