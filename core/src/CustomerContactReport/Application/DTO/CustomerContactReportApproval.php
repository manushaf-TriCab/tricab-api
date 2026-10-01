<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;

final readonly class CustomerContactReportApproval
{
    public function __construct(
        public int $approvalId,
        public int $reportId,
        public string $publicId,
        public string $rowVersion,
        public string $kind,
        public string $status,
        public string $approver,
        public ?int $approverUserId,
        public string $approverName,
        public string $approverEmail,
        public string $requestedBy,
        public ?int $requestedByUserId,
        public DateTimeImmutable $requestedAtUtc,
        public ?string $note,
        public ?DateTimeImmutable $decidedAtUtc,
    ) {
    }
}
