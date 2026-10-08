<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * What every finance app calls to send mail from a shared mailbox.
 *
 * The rules (grant, limits, ledger, idempotency) are the service's, so an app
 * cannot send without them. Throws {@see FinanceMailRefused},
 * {@see FinanceMailInvalid}, {@see FinanceMailConflict} or
 * {@see FinanceMailTransportFailed}.
 */
interface FinanceMailSender
{
    public function send(FinanceMailMessage $message): FinanceMailResult;
}
