<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/** A "set a match" body that cannot be used. The source's 400; the platform says 422. */
final class ApVendorInvalid extends ApVendorFailure
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
