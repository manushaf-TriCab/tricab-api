<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Infrastructure;

use DateTimeImmutable;
use TriCab\Core\Shared\Mailbox\MailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;

/**
 * The reader while the RFQ mail Graph registration (RFQ M0) does not exist: every call refuses and
 * nothing touches the network. "Not configured" is an error and never an empty mailbox.
 */
final class UnconfiguredMailboxReader implements MailboxReader
{
    public const REASON = 'Reading mail from Exchange is not set up yet: the RFQ mail Graph settings (RFQ_MAIL_GRAPH_*) are empty. Previews still work.';

    public function listMessages(string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
    {
        throw new MailboxReadUnavailable(self::REASON);
    }

    public function getMessage(string $mailbox, string $messageId): \TriCab\Core\Shared\Mailbox\MailboxMessage
    {
        throw new MailboxReadUnavailable(self::REASON);
    }

    public function listAttachments(string $mailbox, string $messageId): array
    {
        throw new MailboxReadUnavailable(self::REASON);
    }

    public function getAttachment(string $mailbox, string $messageId, string $attachmentId): \TriCab\Core\Shared\Mailbox\MailboxAttachment
    {
        throw new MailboxReadUnavailable(self::REASON);
    }
}
