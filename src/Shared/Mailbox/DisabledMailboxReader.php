<?php

declare(strict_types=1);

namespace TriCab\Api\Shared\Mailbox;

use DateTimeImmutable;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\FolderMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxReadUnavailable;

/**
 * The reader used while mailbox reading is off or not configured: every call refuses,
 * and nothing touches the network. "Off" is an error and never an empty mailbox: an
 * intake job told "no mail" would record a clean scan of a mailbox it did not read.
 */
final class DisabledMailboxReader implements FolderMailboxReader
{
    public const REASON = 'Mailbox reading is switched off: it is not enabled, or the finance Graph settings are incomplete.';

    public function listMessages(string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
    {
        throw new MailboxReadUnavailable(self::REASON);
    }

    public function listMessagesInFolder(string $mailbox, string $folder, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
    {
        throw new MailboxReadUnavailable(self::REASON);
    }

    public function getMessage(string $mailbox, string $messageId): MailboxMessage
    {
        throw new MailboxReadUnavailable(self::REASON);
    }

    public function listAttachments(string $mailbox, string $messageId): array
    {
        throw new MailboxReadUnavailable(self::REASON);
    }

    public function getAttachment(string $mailbox, string $messageId, string $attachmentId): MailboxAttachment
    {
        throw new MailboxReadUnavailable(self::REASON);
    }
}
