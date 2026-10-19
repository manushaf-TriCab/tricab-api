<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

/** An attachment about to be recorded (the bytes are already in the store under `blobKey`). */
final readonly class NewNprAttachment
{
    public function __construct(
        public string $filename,
        public string $blobKey,
        public int $sizeBytes,
        public string $mimeType,
        public string $documentType,
        public string $title,
        public NprPerson $uploadedBy,
    ) {
    }
}
