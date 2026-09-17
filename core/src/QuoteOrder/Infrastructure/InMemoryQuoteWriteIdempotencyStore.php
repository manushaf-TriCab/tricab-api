<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\QuoteOrder\Application\DTO\QuoteWriteIdempotencyRecord;
use TriCab\Core\QuoteOrder\Application\Exception\QuoteWriteIdempotencyConflictException;
use TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteWriteIdempotencyState;

/**
 * In-memory store for Phase 4C0 tests. Not production storage.
 */
final class InMemoryQuoteWriteIdempotencyStore implements QuoteWriteIdempotencyStore
{
    /** @var array<string, QuoteWriteIdempotencyRecord> */
    private array $byKey = [];

    /** @var array<string, QuoteWriteIdempotencyRecord> */
    private array $byOperationId = [];

    public function find(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
    ): ?QuoteWriteIdempotencyRecord {
        return $this->byKey[$this->key($actorKey, $operation, $idempotencyKey)] ?? null;
    }

    public function tryAcquire(QuoteWriteIdempotencyRecord $record): QuoteWriteIdempotencyRecord
    {
        $key = $this->key($record->actorKey, $record->operation, $record->idempotencyKey);
        $existing = $this->byKey[$key] ?? null;
        if ($existing !== null) {
            if (!hash_equals($existing->requestHash, $record->requestHash)) {
                throw QuoteWriteIdempotencyConflictException::requestMismatch();
            }

            return $existing;
        }

        $this->byKey[$key] = $record;
        $this->byOperationId[$record->platformOperationId] = $record;

        return $record;
    }

    public function markCompleted(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $quoteId,
    ): void {
        $key = $this->key($actorKey, $operation, $idempotencyKey);
        $existing = $this->byKey[$key] ?? null;
        if ($existing === null) {
            return;
        }
        $updated = new QuoteWriteIdempotencyRecord(
            actorKey: $existing->actorKey,
            operation: $existing->operation,
            idempotencyKey: $existing->idempotencyKey,
            requestHash: $existing->requestHash,
            state: QuoteWriteIdempotencyState::COMPLETED,
            platformOperationId: $existing->platformOperationId,
            quoteId: $quoteId,
            cartPublicId: $existing->cartPublicId,
            reconciliationFingerprint: $existing->reconciliationFingerprint,
            createdAtUtc: $existing->createdAtUtc,
            updatedAtUtc: new DateTimeImmutable('now', new DateTimeZone('UTC')),
        );
        $this->byKey[$key] = $updated;
        $this->byOperationId[$updated->platformOperationId] = $updated;
    }

    public function markFailed(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $failureReason,
    ): void {
        $key = $this->key($actorKey, $operation, $idempotencyKey);
        $existing = $this->byKey[$key] ?? null;
        if ($existing === null) {
            return;
        }
        $updated = new QuoteWriteIdempotencyRecord(
            actorKey: $existing->actorKey,
            operation: $existing->operation,
            idempotencyKey: $existing->idempotencyKey,
            requestHash: $existing->requestHash,
            state: QuoteWriteIdempotencyState::FAILED,
            platformOperationId: $existing->platformOperationId,
            quoteId: $existing->quoteId,
            cartPublicId: $existing->cartPublicId,
            reconciliationFingerprint: $existing->reconciliationFingerprint,
            createdAtUtc: $existing->createdAtUtc,
            updatedAtUtc: new DateTimeImmutable('now', new DateTimeZone('UTC')),
            failureReason: $failureReason,
        );
        $this->byKey[$key] = $updated;
        $this->byOperationId[$updated->platformOperationId] = $updated;
    }

    public function findByPlatformOperationId(string $platformOperationId): ?QuoteWriteIdempotencyRecord
    {
        return $this->byOperationId[$platformOperationId] ?? null;
    }

    private function key(string $actorKey, string $operation, string $idempotencyKey): string
    {
        return $actorKey . "\0" . $operation . "\0" . $idempotencyKey;
    }
}
