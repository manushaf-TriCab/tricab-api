<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ar;

use DateTimeImmutable;
use TriCab\Core\Finance\Shared\Mailbox\FinanceMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxReadFailed;

/** A finance mailbox reader that can be made to fail, wrapped round a working one; it counts the listings it was asked for. */
final class FlakyMailboxReader implements FinanceMailboxReader
{
    public bool $failListing = false;

    /** @var list<string> message ids whose body cannot be read */
    public array $failMessages = [];

    /** @var list<string> attachment ids whose bytes cannot be read */
    public array $failAttachments = [];

    public bool $failAttachmentListing = false;

    /** @var list<array{from: string, to: string, folder: ?string, max: ?int}> */
    public array $listings = [];

    public function __construct(private readonly FinanceMailboxReader $inner)
    {
    }

    public function listMessages(string $application, string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, ?int $maxMessages = null, ?int $actorUserId = null, ?string $folder = null): array
    {
        $this->listings[] = ['from' => $from->format('c'), 'to' => $to->format('c'), 'folder' => $folder, 'max' => $maxMessages];
        if ($this->failListing) {
            throw new MailboxReadFailed('Graph is down');
        }

        return $this->inner->listMessages($application, $mailbox, $from, $to, $maxMessages, $actorUserId, $folder);
    }

    public function getMessage(string $application, string $mailbox, string $messageId, ?int $actorUserId = null): MailboxMessage
    {
        if (in_array($messageId, $this->failMessages, true)) {
            throw new MailboxReadFailed('Graph could not read that message');
        }

        return $this->inner->getMessage($application, $mailbox, $messageId, $actorUserId);
    }

    public function listAttachments(string $application, string $mailbox, string $messageId, ?int $actorUserId = null): array
    {
        if ($this->failAttachmentListing) {
            throw new MailboxReadFailed('Graph could not list attachments');
        }

        return $this->inner->listAttachments($application, $mailbox, $messageId, $actorUserId);
    }

    public function getAttachment(string $application, string $mailbox, string $messageId, string $attachmentId, ?int $actorUserId = null): MailboxAttachment
    {
        if (in_array($attachmentId, $this->failAttachments, true)) {
            throw new MailboxReadFailed('Graph could not read that attachment');
        }

        return $this->inner->getAttachment($application, $mailbox, $messageId, $attachmentId, $actorUserId);
    }
}
