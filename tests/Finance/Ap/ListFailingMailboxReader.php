<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use DateTimeImmutable;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;

/** A finance mailbox reader whose LISTING fails for the mailboxes named, wrapped round a working one. */
final class ListFailingMailboxReader implements FinanceMailboxReader
{
    /** @var list<string> lower-case addresses whose listing fails */
    public array $failFor = [];

    public function __construct(private readonly FinanceMailboxReader $inner)
    {
    }

    public function listMessages(string $application, string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, ?int $maxMessages = null, ?int $actorUserId = null, ?string $folder = null): array
    {
        if (in_array(strtolower($mailbox), $this->failFor, true)) {
            throw new MailboxReadFailed('Graph is down for ' . $mailbox);
        }

        return $this->inner->listMessages($application, $mailbox, $from, $to, $maxMessages, $actorUserId, $folder);
    }

    public function getMessage(string $application, string $mailbox, string $messageId, ?int $actorUserId = null): MailboxMessage
    {
        return $this->inner->getMessage($application, $mailbox, $messageId, $actorUserId);
    }

    public function listAttachments(string $application, string $mailbox, string $messageId, ?int $actorUserId = null): array
    {
        return $this->inner->listAttachments($application, $mailbox, $messageId, $actorUserId);
    }

    public function getAttachment(string $application, string $mailbox, string $messageId, string $attachmentId, ?int $actorUserId = null): MailboxAttachment
    {
        return $this->inner->getAttachment($application, $mailbox, $messageId, $attachmentId, $actorUserId);
    }
}
