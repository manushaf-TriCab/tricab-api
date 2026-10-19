<?php

declare(strict_types=1);

namespace TriCab\Core\Npr\Domain;

use DateTimeImmutable;

/** One file on a request, as stored: metadata here, bytes in Blob storage under `blobKey`. */
final readonly class NprAttachment
{
    public function __construct(
        public string $publicId,
        public string $requestPublicId,
        public string $filename,
        public string $blobKey,
        public int $sizeBytes,
        public string $mimeType,
        public string $documentType,
        public string $title,
        public NprPerson $uploadedBy,
        public DateTimeImmutable $uploadedAt,
    ) {
    }
}
