<?php

declare(strict_types=1);

namespace TriCab\Core\CustomerContactReport\Application\DTO;

use DateTimeImmutable;

final readonly class CustomerContactReportSignature
{
    public function __construct(
        public string $publicId,
        public string $expectedRowVersion,
        public string $operatorName,
        public string $signedAt,
        public string $signatureUrl,
        public int $actorUserId,
        public string $actorEmail,
        public DateTimeImmutable $signedAtUtc,
    ) {
    }
}
