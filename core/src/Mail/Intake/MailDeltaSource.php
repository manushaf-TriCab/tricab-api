<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use DateTimeImmutable;

/**
 * Asks Exchange what changed in a mailbox's Inbox and the folders under it. Graph only: it never touches
 * the database.
 *
 * @see \TriCab\Core\Shared\Mailbox\MailboxSyncExpired thrown when the link is no longer valid (410)
 * @see \TriCab\Core\Shared\Mailbox\MailboxItemNotFound thrown when the folder is gone (404)
 */
interface MailDeltaSource
{
    /**
     * The Inbox (first, id `inbox`) and every folder below it. Nothing outside the Inbox subtree: not Sent
     * Items, Drafts, Junk, Outbox, Deleted Items, Clutter or Conversation History.
     *
     * @return list<MailFolder>
     */
    public function folders(string $mailbox): array;

    /**
     * One page of one folder (`$folderId`, default the Inbox). With no `$link` the query starts at
     * `$since`; otherwise it resumes from a link this method returned earlier for the same folder.
     */
    public function changes(string $mailbox, ?string $link, DateTimeImmutable $since, string $folderId = MailFolder::INBOX): MailChanges;

    /**
     * The message's Internet headers (lower-case names), for a message whose delta row had none.
     *
     * @return array<string, string>
     */
    public function headers(string $mailbox, string $graphMessageId): array;
}
