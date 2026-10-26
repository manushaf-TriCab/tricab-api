<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** A mail sync was asked for before the product owner's cutoff date is set in Settings (409). */
final class Ap2MailCutoffUnset extends Ap2Failure
{
    public function __construct()
    {
        parent::__construct('Set the mail cutoff date in Settings first', self::details(['Set the mail cutoff date in Settings first']));
    }

    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'FINANCE_AP2_MAIL_CUTOFF_UNSET';
    }
}
