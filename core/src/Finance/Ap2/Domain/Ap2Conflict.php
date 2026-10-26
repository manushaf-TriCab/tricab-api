<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** The write breaks a rule or clashes with the state of the record (409). */
final class Ap2Conflict extends Ap2Failure
{
    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP2_RULE_FAILED';
    }
}
