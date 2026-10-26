<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** A value that cannot be used (422). */
final class Ap2Invalid extends Ap2Failure
{
    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP2_INPUT_INVALID';
    }
}
