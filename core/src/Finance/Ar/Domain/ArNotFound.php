<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/** Nothing by that id. */
final class ArNotFound extends ArFailure
{
    public function httpStatus(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AR_NOT_FOUND';
    }
}
