<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;

final readonly class CustomerContactReportReopen
{
    public function __construct(
        public string $publicId,
        public string $expectedRowVersion,
        public DateTimeImmutable $reopenedAtUtc,
    ) {
    }
}
