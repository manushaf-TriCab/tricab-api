<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/** A link that has run out (the public form's 410): the rep is told why, in words they can act on. */
final class ArGone extends ArFailure
{
    public function httpStatus(): int
    {
        return 410;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AR_GONE';
    }
}
