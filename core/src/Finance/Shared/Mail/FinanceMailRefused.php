<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/** The app has no send grant on the mailbox, or the mailbox is unknown or inactive (the portal's 403). */
final class FinanceMailRefused extends FinanceMailException
{
    public static function forMailbox(string $application, string $mailbox): self
    {
        return new self(sprintf('%s is not allowed to send from the mailbox %s.', $application, $mailbox));
    }

    /**
     * No read grant on an active mailbox, or the person's mailbox scope leaves it out
     * (F1.11). The same words for every cause, so a refusal never says which mailboxes exist.
     */
    public static function forReading(string $application, string $mailbox): self
    {
        return new self(sprintf('%s is not allowed to read the mailbox %s.', $application, $mailbox));
    }
}
