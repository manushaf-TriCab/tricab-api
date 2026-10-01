<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;

final readonly class CustomerContactReportAssignment
{
    public function __construct(
        public string $publicId,
        public string $expectedRowVersion,
        public string $route,
        public string $errorCode,
        public int $assigneeUserId,
        public string $assigneeLogin,
        public string $assigneeName,
        public string $assigneeEmail,
        public string $assignedByEmail,
        public DateTimeImmutable $assignedAtUtc,
    ) {
    }
}
