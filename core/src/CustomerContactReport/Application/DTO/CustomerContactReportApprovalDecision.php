<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;

final readonly class CustomerContactReportApprovalDecision
{
    public function __construct(
        public string $publicId,
        public string $kind,
        public string $expectedRowVersion,
        public string $decision,
        public ?string $note,
        public int $actorUserId,
        public string $actorEmail,
        public DateTimeImmutable $decidedAtUtc,
        public ?int $settlementApproverUserId,
        public ?string $settlementApproverLogin,
        public ?string $settlementApproverName,
        public ?string $settlementApproverEmail,
    ) {
    }
}
