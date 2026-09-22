<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Infrastructure;

use TriCab\Core\Onboarding\Application\DTO\OnboardingWriteIdempotencyRecord;
use TriCab\Core\Onboarding\Contract\OnboardingWriteIdempotencyStore;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteWriteIdempotencyState;

/**
 * The default binding, and what the HTTP tests run against.
 *
 * Follows {@see \TriCab\Core\QuoteOrder\Infrastructure\InMemoryQuoteWriteIdempotencyStore}
 * in behaviour, including the one subtlety that matters: `tryAcquire` on an
 * existing key compares the request hash with {@see hash_equals} and returns the
 * held record when they agree, rather than refusing every second call. A double
 * that refused would make every retry test pass for the wrong reason.
 */
final class InMemoryOnboardingWriteIdempotencyStore implements OnboardingWriteIdempotencyStore
{
    /** @var array<string, OnboardingWriteIdempotencyRecord> */
    private array $records = [];

    public function find(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
    ): ?OnboardingWriteIdempotencyRecord {
        return $this->records[self::key($actorKey, $operation, $idempotencyKey)] ?? null;
    }

    public function tryAcquire(OnboardingWriteIdempotencyRecord $record): OnboardingWriteIdempotencyRecord
    {
        $key = self::key($record->actorKey, $record->operation, $record->idempotencyKey);
        $existing = $this->records[$key] ?? null;

        if ($existing === null) {
            $this->records[$key] = $record;

            return $record;
        }

        if (!hash_equals($existing->requestHash, $record->requestHash)) {
            throw OnboardingWriteConflict::idempotencyKeyReused();
        }

        return $existing;
    }

    public function markCompleted(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $resultPublicId,
    ): void {
        $this->transition($actorKey, $operation, $idempotencyKey, QuoteWriteIdempotencyState::COMPLETED, $resultPublicId, null);
    }

    public function markFailed(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $failureReason,
    ): void {
        $this->transition($actorKey, $operation, $idempotencyKey, QuoteWriteIdempotencyState::FAILED, null, $failureReason);
    }

    public function clear(): void
    {
        $this->records = [];
    }

    private function transition(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $state,
        ?string $resultPublicId,
        ?string $failureReason,
    ): void {
        $key = self::key($actorKey, $operation, $idempotencyKey);
        $existing = $this->records[$key] ?? null;

        if ($existing === null) {
            return;
        }

        $this->records[$key] = new OnboardingWriteIdempotencyRecord(
            actorKey: $existing->actorKey,
            operation: $existing->operation,
            idempotencyKey: $existing->idempotencyKey,
            requestHash: $existing->requestHash,
            state: $state,
            platformOperationId: $existing->platformOperationId,
            resultPublicId: $resultPublicId ?? $existing->resultPublicId,
            createdAtUtc: $existing->createdAtUtc,
            updatedAtUtc: $existing->updatedAtUtc,
            failureReason: $failureReason ?? $existing->failureReason,
        );
    }

    private static function key(string $actorKey, string $operation, string $idempotencyKey): string
    {
        return $actorKey . '|' . $operation . '|' . $idempotencyKey;
    }
}
