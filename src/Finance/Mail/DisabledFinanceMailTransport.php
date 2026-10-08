<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Mail;

use TriCab\Core\Finance\Shared\Mail\FinanceMailMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceReplyMailTransport;
use TriCab\Core\Finance\Shared\Mail\FinanceMailSuppressed;

/**
 * The transport while `FINANCE_MAIL_ENABLED` is off, or the Graph settings are
 * incomplete. Makes no network call. It reports the send as suppressed, so the
 * service leaves an honest `suppressed` ledger row ("mail is switched off") and
 * the caller sees that nothing was sent, rather than a `sent` row for mail that
 * never left. A suppressed key may be sent again once mail is on.
 */
final class DisabledFinanceMailTransport implements FinanceReplyMailTransport
{
    public const REASON = 'Mail is switched off: FINANCE_MAIL_ENABLED is not true, or the finance Graph settings are incomplete.';

    public function deliver(FinanceMailMessage $message): void
    {
        throw new FinanceMailSuppressed(self::REASON);
    }

    public function deliverReply(FinanceMailMessage $message, string $replyToMessageId): bool
    {
        throw new FinanceMailSuppressed(self::REASON);
    }
}
