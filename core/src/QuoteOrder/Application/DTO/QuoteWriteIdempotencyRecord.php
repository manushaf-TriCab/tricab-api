<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Application\DTO;

use DateTimeImmutable;

final readonly class QuoteWriteIdempotencyRecord
{
    public function __construct(
        public string $actorKey,
        public string $operation,
        public string $idempotencyKey,
        public string $requestHash,
        public string $quoteId,
        public DateTimeImmutable $createdAtUtc,
    ) {
    }
}
