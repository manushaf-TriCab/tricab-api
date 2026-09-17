<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Contract;

use TriCab\Core\QuoteOrder\Application\DTO\QuoteWriteIdempotencyRecord;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteIdempotencyConflictException;

/**
 * Platform-side (Azure) idempotency for Save Quote.
 * Never stores keys on legacy tqoth.
 */
interface QuoteWriteIdempotencyStore
{
    public const OPERATION_PERSIST_DRAFT = 'quotes.persistDraft';

    public function find(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
    ): ?QuoteWriteIdempotencyRecord;

    /**
     * Insert IN_PROGRESS row. Concurrent duplicate key → loser loads existing.
     *
     * @throws QuoteWriteIdempotencyConflictException when key exists with different request hash
     */
    public function tryAcquire(QuoteWriteIdempotencyRecord $record): QuoteWriteIdempotencyRecord;

    public function markCompleted(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $quoteId,
    ): void;

    public function markFailed(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $failureReason,
    ): void;

    /**
     * Lookup by platform operation id for cross-DB crash recovery.
     */
    public function findByPlatformOperationId(string $platformOperationId): ?QuoteWriteIdempotencyRecord;
}
