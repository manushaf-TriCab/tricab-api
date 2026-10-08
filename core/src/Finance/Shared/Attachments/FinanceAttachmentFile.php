<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Attachments;

/** A file that may be served: its row and its bytes. */
final readonly class FinanceAttachmentFile
{
    public function __construct(
        public FinanceAttachment $attachment,
        public string $bytes,
    ) {
    }
}
