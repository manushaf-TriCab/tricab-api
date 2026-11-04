<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Intake;

use DateTimeImmutable;

/** Graph reads for the one-off backfill: plain list-with-filter, never delta, so the live cursors are not involved. */
interface MailBackfillSource
{
    /**
     * The Inbox subtree, plus Deleted Items itself when asked.
     *
     * @return list<MailFolder>
     */
    public function backfillFolders(string $mailbox, bool $includeDeleted): array;

    /**
     * One page of the messages received since `$since` (newest first). `link` of the result is the next page's
     * link while `complete` is false.
     */
    public function listPage(string $mailbox, MailFolder $folder, ?string $link, DateTimeImmutable $since): MailChanges;
}
