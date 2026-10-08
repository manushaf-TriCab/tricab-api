<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/** A value that cannot be used. The source's 400; the platform says 422, as RFQ and Tri-Task do. */
final class ArInvalid extends ArFailure
{
    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AR_INPUT_INVALID';
    }
}
