<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/** A part of Tri-AR that is switched off on this server (sending, in the overlap week: decision D53). Nothing was read or written. */
final class ArUnavailable extends ArFailure
{
    public function httpStatus(): int
    {
        return 503;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AR_UNAVAILABLE';
    }
}
