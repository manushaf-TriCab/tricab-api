<?php

declare(strict_types=1);

namespace TriCab\Api\Shared\Mailbox;

use DateTimeImmutable;
use TriCab\Core\Shared\Mailbox\MailboxAttachment;
use TriCab\Core\Shared\Mailbox\MailboxItemNotFound;
use TriCab\Core\Shared\Mailbox\MailboxMessage;
use TriCab\Core\Shared\Mailbox\MailboxMessageSummary;
use TriCab\Core\Shared\Mailbox\FolderMailboxReader;
use TriCab\Core\Shared\Mailbox\MailboxFolders;

/**
 * Mailboxes held in memory: the tests', and a run with no Graph registration. A
 * mailbox is a list of messages you add; reading behaves as Graph does (newest first,
 * the window inclusive, the cap applied), and a message that is not there is a
 * {@see MailboxItemNotFound}, as Graph's 404 is.
 */
final class InMemoryMailboxReader implements FolderMailboxReader
{
    /** @var array<string, array<string, array{message: MailboxMessage, attachments: array<string, MailboxAttachment>, folder: string}>> mailbox (lower case), then message id */
    private array $mailboxes = [];

    /** How many reads were made, whatever they were: lets a test show that none happened. */
    public int $reads = 0;

    /**
     * @param list<MailboxAttachment> $attachments
     * @param string $folder a well-known folder name; the default is the Inbox
     */
    public function add(string $mailbox, MailboxMessage $message, array $attachments = [], string $folder = FolderMailboxReader::INBOX): void
    {
        $byId = [];
        foreach ($attachments as $attachment) {
            $byId[$attachment->summary->id] = $attachment;
        }
        $this->mailboxes[self::key($mailbox)][$message->summary->id] = ['message' => $message, 'attachments' => $byId, 'folder' => MailboxFolders::normalise($folder)];
    }

    public function listMessages(string $mailbox, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
    {
        return $this->list($mailbox, null, $from, $to, $maxMessages);
    }

    public function listMessagesInFolder(string $mailbox, string $folder, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
    {
        return $this->list($mailbox, MailboxFolders::normalise($folder), $from, $to, $maxMessages);
    }

    /**
     * @return list<MailboxMessageSummary>
     */
    private function list(string $mailbox, ?string $folder, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array
    {
        ++$this->reads;
        $summaries = [];
        foreach ($this->mailboxes[self::key($mailbox)] ?? [] as $entry) {
            if ($folder !== null && $entry['folder'] !== $folder) {
                continue;
            }
            $at = $entry['message']->summary->receivedAt;
            if ($at >= $from && $at <= $to) {
                $summaries[] = $entry['message']->summary;
            }
        }
        usort($summaries, static fn (MailboxMessageSummary $a, MailboxMessageSummary $b): int => $b->receivedAt <=> $a->receivedAt);

        return array_slice($summaries, 0, max(1, $maxMessages));
    }

    public function getMessage(string $mailbox, string $messageId): MailboxMessage
    {
        ++$this->reads;

        return $this->entry($mailbox, $messageId)['message'];
    }

    public function listAttachments(string $mailbox, string $messageId): array
    {
        ++$this->reads;

        return array_values(array_map(
            static fn (MailboxAttachment $attachment) => $attachment->summary,
            $this->entry($mailbox, $messageId)['attachments'],
        ));
    }

    public function getAttachment(string $mailbox, string $messageId, string $attachmentId): MailboxAttachment
    {
        ++$this->reads;

        return $this->entry($mailbox, $messageId)['attachments'][$attachmentId]
            ?? throw new MailboxItemNotFound('The mailbox, message or attachment was not found.');
    }

    /**
     * @return array{message: MailboxMessage, attachments: array<string, MailboxAttachment>, folder: string}
     */
    private function entry(string $mailbox, string $messageId): array
    {
        return $this->mailboxes[self::key($mailbox)][$messageId]
            ?? throw new MailboxItemNotFound('The mailbox, message or attachment was not found.');
    }

    private static function key(string $mailbox): string
    {
        return strtolower(trim($mailbox));
    }
}
