<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/** A transport that can send a message as raw MIME (see {@see FinanceMimeBuilder}); used for a message that carries a {@see FinanceMailCalendar}. */
interface FinanceMimeMailTransport extends FinanceMailTransport
{
    public function deliverMime(FinanceMailMessage $message): void;
}
