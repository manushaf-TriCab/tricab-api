<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\QuoteOrder\Application\DTO\QuoteWriteIdempotencyRecord;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore;

/**
 * No-op store until Azure schema is approved (Phase 4C).
 */
final class NullQuoteWriteIdempotencyStore implements QuoteWriteIdempotencyStore
{
    public function find(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
    ): ?QuoteWriteIdempotencyRecord {
        return null;
    }

    public function record(QuoteWriteIdempotencyRecord $record): void
    {
    }
}
