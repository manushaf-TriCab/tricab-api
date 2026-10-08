<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Mailbox;

/** An attachment as a list shows it. `isInline` marks images embedded in the body. */
final readonly class MailboxAttachmentSummary
{
    public function __construct(
        /** The Graph immutable id, valid with the message id it came with. */
        public string $id,
        public string $name,
        public string $contentType,
        public int $size,
        public bool $isInline = false,
    ) {
    }
}
