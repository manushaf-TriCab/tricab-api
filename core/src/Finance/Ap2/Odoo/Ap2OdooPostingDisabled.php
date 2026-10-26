<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

use TriCab\Core\Finance\Ap2\Domain\Ap2Failure;

/** A write to Odoo was asked for while FINANCE_AP2_ODOO_POSTING_ENABLED is off. Nothing was sent to Odoo. */
final class Ap2OdooPostingDisabled extends Ap2Failure
{
    public function __construct(string $message = 'Posting to Odoo is switched off (FINANCE_AP2_ODOO_POSTING_ENABLED)')
    {
        parent::__construct($message);
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP2_ODOO_POSTING_DISABLED';
    }
}
