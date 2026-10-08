<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ar\Domain;

/** The person is signed in and holds the route's permission, but the thing behind it (a mailbox grant, a scope) says no. */
final class ArForbidden extends ArFailure
{
    public function httpStatus(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AR_FORBIDDEN';
    }
}
