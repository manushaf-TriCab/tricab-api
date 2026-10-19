<?php

declare(strict_types=1);

namespace TriCab\Core\Mail\Application\DTO;

/** An attachment as the reader saw it. Names, types and sizes only: the bytes are never stored. */
final readonly class NewAttachment
{
    public function __construct(
        /** The Graph immutable attachment id, valid with the message's Graph id. */
        public string $graphAttachmentId,
        public string $fileName,
        public string $mimeType,
        public int $sizeBytes,
        /** An inline image embedded in the body; kept, but not listed as an attachment. */
        public bool $isInline = false,
    ) {
    }
}
