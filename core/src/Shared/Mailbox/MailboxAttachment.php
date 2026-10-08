<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

/** A file attachment with its bytes. Untrusted: never trust the name or the type. */
final readonly class MailboxAttachment
{
    public function __construct(
        public MailboxAttachmentSummary $summary,
        public string $content,
    ) {
    }
}
