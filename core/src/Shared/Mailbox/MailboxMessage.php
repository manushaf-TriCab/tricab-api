<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

/**
 * One message with its body and recipients. The body is **untrusted**: for HTML it is
 * whatever the sender wrote, and it must be shown in an inert viewer (a sandboxed
 * frame, scripts and remote loads off), never inserted into a page.
 */
final readonly class MailboxMessage
{
    public const BODY_HTML = 'html';

    public const BODY_TEXT = 'text';

    /**
     * @param self::BODY_* $bodyType
     * @param list<MailboxRecipient> $to
     * @param list<MailboxRecipient> $cc
     */
    public function __construct(
        public MailboxMessageSummary $summary,
        public string $bodyType,
        public string $body,
        public array $to = [],
        public array $cc = [],
    ) {
    }
}
