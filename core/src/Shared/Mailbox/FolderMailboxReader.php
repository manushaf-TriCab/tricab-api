<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * A {@see MailboxReader} that can also list one folder. {@see MailboxReader::listMessages()} reads every
 * folder, as the apps scan today; Tri-AR scans the Inbox only (Phase 5, Q2) so mail a rule filed in Sent
 * Items, Drafts or Deleted Items is not ingested as customer mail.
 *
 * A folder is one of Graph's **well-known names**, never an id or a path: the name goes into a URL, and
 * anything else is refused here.
 */
interface FolderMailboxReader extends MailboxReader
{
    public const INBOX = 'inbox';

    /** Graph's well-known folder names this port accepts (lower case). */
    public const WELL_KNOWN = ['inbox', 'sentitems', 'drafts', 'deleteditems', 'junkemail', 'archive'];

    /**
     * Messages received in [$from, $to] in one folder, newest first, at most $maxMessages.
     *
     * @param string $folder a well-known name, any case ({@see MailboxFolders::normalise()})
     * @return list<MailboxMessageSummary>
     * @throws InvalidArgumentException when the folder is not a well-known name
     */
    public function listMessagesInFolder(string $mailbox, string $folder, DateTimeImmutable $from, DateTimeImmutable $to, int $maxMessages): array;
}
