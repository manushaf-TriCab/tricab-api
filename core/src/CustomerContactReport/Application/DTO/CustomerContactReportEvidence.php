<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;

final readonly class CustomerContactReportEvidence
{
    public function __construct(
        public string $publicId,
        public string $fileUrl,
        public string $fileName,
        public int $fileSize,
        public string $contentType,
        public int $createdByUserId,
        public string $createdByEmail,
        public DateTimeImmutable $createdAtUtc,
    ) {
    }
}
