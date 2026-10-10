<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/** A value that cannot be used. The source's 400; the platform says 422, as RFQ and Tri-Task do. */
final class ApInvalid extends ApFailure
{
    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP_INPUT_INVALID';
    }
}
