<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

use DateTimeImmutable;

/**
 * A message as a list shows it. There is no web link on purpose: the shared mailboxes
 * have no interactive sign-in, so a link into Outlook opens nothing; the message is
 * shown in the app.
 */
final readonly class MailboxMessageSummary
{
    public function __construct(
        /** The Graph immutable id. */
        public string $id,
        public string $subject,
        public string $fromAddress,
        public string $fromName,
        /** UTC. */
        public DateTimeImmutable $receivedAt,
        public bool $hasAttachments,
        public string $bodyPreview,
    ) {
    }
}
