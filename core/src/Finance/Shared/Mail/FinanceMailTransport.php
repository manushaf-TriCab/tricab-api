<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * Puts a validated message on the wire. Throws {@see FinanceMailTransportFailed}
 * for any failure; a message that did not throw was accepted.
 */
interface FinanceMailTransport
{
    public function deliver(FinanceMailMessage $message): void;
}
