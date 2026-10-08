<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/** Too many attempts at the public form from one address or at one form (429). */
final class ArRateLimited extends ArFailure
{
    public function httpStatus(): int
    {
        return 429;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AR_RATE_LIMITED';
    }
}
