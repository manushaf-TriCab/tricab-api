<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * A transport that can answer a message in its own conversation (Tri-AR's reply composer). A sub-interface of
 * {@see FinanceMailTransport}, so a transport or a test double that only sends keeps working: the service uses
 * this method only when a message names a reply target and the transport has it.
 */
interface FinanceReplyMailTransport extends FinanceMailTransport
{
    /**
     * Sends `$message` as a reply to the message `$replyToMessageId` in `$message->mailbox`.
     *
     * Returns true when it went into the customer's conversation, false when it went as a new message because the
     * original could not be used (it is gone, or its id is not valid). Throws {@see FinanceMailTransportFailed} for
     * any other failure, and {@see FinanceMailSuppressed} when mail is switched off. Nothing is sent twice.
     */
    public function deliverReply(FinanceMailMessage $message, string $replyToMessageId): bool;
}
