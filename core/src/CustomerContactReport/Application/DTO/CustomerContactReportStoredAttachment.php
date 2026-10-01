<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

final readonly class CustomerContactReportStoredAttachment
{
    public function __construct(
        public int $reportId,
        public string $publicId,
        public string $fileUrl,
        public string $fileName,
        public int $fileSize,
        public string $contentType,
        public string $kind,
        public ?int $createdByUserId = null,
        public ?string $createdByEmail = null,
        public ?\DateTimeImmutable $createdAtUtc = null,
    ) {
    }
}
