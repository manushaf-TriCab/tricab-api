<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use DateTimeImmutable;

/**
 * Asks Exchange what changed in a mailbox's Inbox. Graph only: it never touches the database.
 *
 * @see \TriCab\Core\Shared\Mailbox\MailboxSyncExpired thrown when the link is no longer valid (410)
 */
interface MailDeltaSource
{
    /**
     * One page. With no `$link` the query starts at `$since`; otherwise it resumes from a link this
     * method returned earlier.
     */
    public function changes(string $mailbox, ?string $link, DateTimeImmutable $since): MailChanges;

    /**
     * The message's Internet headers (lower-case names), for a message whose delta row had none.
     *
     * @return array<string, string>
     */
    public function headers(string $mailbox, string $graphMessageId): array;
}
