<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/** The request clashes with the state of a record. */
final class ArConflict extends ArFailure
{
    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AR_CONFLICT';
    }
}
