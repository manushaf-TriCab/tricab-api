<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** Where to fetch an attachment from, and what we recorded of it (the size is checked before any fetch). */
final readonly class AttachmentRef
{
    public function __construct(
        public string $attachmentId,
        public string $mailboxAddress,
        public string $graphMessageId,
        public string $graphAttachmentId,
        public string $fileName,
        public string $mimeType,
        public int $sizeBytes,
    ) {
    }
}
