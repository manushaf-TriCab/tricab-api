<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/** The request clashes with the state of a record. */
final class ApConflict extends ApFailure
{
    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP_CONFLICT';
    }
}
