<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use TriCab\Core\QuoteOrder\Application\DTO\QuoteWriteIdempotencyRecord;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore;

/**
 * No-op until Azure migration is approved and applied.
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

    public function tryAcquire(QuoteWriteIdempotencyRecord $record): QuoteWriteIdempotencyRecord
    {
        return $record;
    }

    public function markCompleted(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $quoteId,
    ): void {
    }

    public function markFailed(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $failureReason,
    ): void {
    }

    public function findByPlatformOperationId(string $platformOperationId): ?QuoteWriteIdempotencyRecord
    {
        return null;
    }
}
