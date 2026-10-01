<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;

final readonly class CustomerContactReportCreditNote
{
    public function __construct(
        public string $publicId,
        public string $expectedSettlementRowVersion,
        public string $fileUrl,
        public string $fileName,
        public int $fileSize,
        public string $contentType,
        public ?string $note,
        public int $actorUserId,
        public string $actorEmail,
        public DateTimeImmutable $decidedAtUtc,
    ) {
    }
}
