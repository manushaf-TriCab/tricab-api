<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * A calendar invite for a message: the VCALENDAR (`METHOD:REQUEST`) and a plain-text version of the wording. A transport
 * that can ({@see FinanceMimeMailTransport}) sends it as the message's own `text/calendar` part, which is what makes
 * Outlook show a meeting request with Accept, Tentative and Decline in the message itself.
 */
final readonly class FinanceMailCalendar
{
    public function __construct(
        public string $ics,
        public string $text,
    ) {
    }
}
