<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/** Nothing by that id. */
final class ApNotFound extends ApFailure
{
    public function httpStatus(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP_NOT_FOUND';
    }
}
