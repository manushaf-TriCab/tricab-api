<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

use DateTimeImmutable;

/** A message as the RFQ reader (M3) saw it. */
final readonly class NewMessage
{
    /**
     * @param list<MailParty> $to
     * @param list<MailParty> $cc
     * @param list<NewAttachment> $attachments
     */
    public function __construct(
        public string $mailboxAddress,
        /** The Graph immutable id (`Prefer: IdType="ImmutableId"`). */
        public string $graphMessageId,
        /** The dedupe key within the mailbox. */
        public string $internetMessageId,
        /** Graph's `conversationId`; empty gives the message a thread of its own. */
        public string $conversationId,
        public string $subject,
        public MailParty $from,
        public array $to,
        public array $cc,
        public DateTimeImmutable $receivedAt,
        /** Graph's `bodyPreview` (at most 255 characters). */
        public string $bodyPreview,
        public bool $isRead,
        public array $attachments = [],
        public string $mailboxDisplayName = '',
    ) {
    }
}
