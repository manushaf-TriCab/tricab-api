<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;

final readonly class CustomerContactReportClosure
{
    public function __construct(
        public string $publicId,
        public string $expectedRowVersion,
        public string $closedBy,
        public int $closedByUserId,
        public DateTimeImmutable $closedDate,
        public DateTimeImmutable $closedAtUtc,
    ) {
    }
}
