<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** There is no such record (404). */
final class Ap2NotFound extends Ap2Failure
{
    public function httpStatus(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP2_NOT_FOUND';
    }
}
