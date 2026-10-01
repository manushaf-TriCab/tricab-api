<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;

final readonly class CustomerContactReportApprovalRequest
{
    public function __construct(
        public string $publicId,
        public string $expectedReportRowVersion,
        public string $kind,
        public string $approverLogin,
        public int $approverUserId,
        public string $approverName,
        public string $approverEmail,
        public string $requestedBy,
        public int $requestedByUserId,
        public DateTimeImmutable $requestedAtUtc,
        public bool $writeCreditFigures,
        public ?string $valueExGst,
        public ?string $restockingAmount,
        public ?string $subTotal,
        public ?string $gst,
        public ?string $creditTotal,
    ) {
    }
}
