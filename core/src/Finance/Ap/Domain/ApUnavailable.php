<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/** A part of Tri-AP that is switched off on this server (sending, decision D79). Nothing was read or written. */
final class ApUnavailable extends ApFailure
{
    public function httpStatus(): int
    {
        return 503;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP_UNAVAILABLE';
    }
}
