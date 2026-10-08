<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mailbox;

use DateTimeImmutable;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxAttachmentSummary;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;

/**
 * What every finance app calls to read a shared mailbox: the platform's
 * {@see \TriCab\Core\Shared\Mailbox\MailboxReader} behind the rules the finance portal's
 * Mail Service enforces (the app needs a read grant on an active mailbox, windows are at
 * most 31 days and 500 messages, and every read leaves an audit row).
 *
 * Throws {@see \TriCab\Core\Finance\Shared\Mail\FinanceMailRefused} (no read grant, or
 * the person's mailbox scope excludes it), {@see \TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid}
 * (a window or an id that breaks a rule), and the reader's own
 * {@see \TriCab\Core\Shared\Mailbox\MailboxReadFailed} family.
 *
 * `$actorUserId` is the signed-in person's `identity.Users.UserId`, or null for a
 * scheduled job; when given, their mailbox scope (F1.10) must allow the mailbox.
 */
interface FinanceMailboxReader
{
    /**
     * `$folder` is optional: null reads every folder, as before; a Graph well-known name (`inbox`) reads that one
     * folder (Tri-AR scans the Inbox only). A name that is not one is a {@see \TriCab\Core\Finance\Shared\Mail\FinanceMailInvalid};
     * a reader that cannot list one folder is a {@see \TriCab\Core\Shared\Mailbox\MailboxReadUnavailable}.
     *
     * @param int|null $maxMessages null or 0 means the ceiling, 500; anything else is clamped to 1 to 500
     * @return list<MailboxMessageSummary>
     */
    public function listMessages(string $application, string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, ?int $maxMessages = null, ?int $actorUserId = null, ?string $folder = null): array;

    public function getMessage(string $application, string $mailbox, string $messageId, ?int $actorUserId = null): MailboxMessage;

    /**
     * @return list<MailboxAttachmentSummary>
     */
    public function listAttachments(string $application, string $mailbox, string $messageId, ?int $actorUserId = null): array;

    public function getAttachment(string $application, string $mailbox, string $messageId, string $attachmentId, ?int $actorUserId = null): MailboxAttachment;
}
