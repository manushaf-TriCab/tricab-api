<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\OnboardingWriteIdempotencyRecord;

/**
 * Replay protection for the three Onboarding writes that must not happen twice.
 *
 * Follows {@see \TriCab\Core\QuoteOrder\Contract\QuoteWriteIdempotencyStore}'s
 * contract — `tryAcquire` / `markCompleted` / `markFailed`, conflict when a key
 * returns with a different request hash — because the platform already has one
 * shape for this and a second shape would be one more thing to get right twice.
 *
 * ## Which writes, and which ones deliberately not
 *
 * Here: {@see OPERATION_SUBMIT_ATTEMPT}, {@see OPERATION_ACKNOWLEDGE_POLICY},
 * {@see OPERATION_SUBMIT_FORM}. Each produces an immutable row, each is
 * triggered by a button somebody can double-click on a slow connection, and each
 * would otherwise turn one act into two records.
 *
 * Not here: presentation progress, which is a union and is therefore idempotent
 * without a key, and draft saves, which are overwritten anyway and carry a
 * `RowVersionToken` instead. Requiring a key for those would be ceremony that
 * buys nothing and that a client would eventually stop sending correctly.
 *
 * ## The ledger is not the only protection
 *
 * `UX_onboarding_Attempts_IdempotencyKey`,
 * `UX_onboarding_FormSubmissions_IdempotencyKey` and
 * `UQ_onboarding_PolicyAcknowledgements_UserVersion` enforce uniqueness at the
 * database, and they keep working if this store is bypassed, misconfigured or
 * replaced. What the ledger adds on top is the request hash — the thing that
 * distinguishes a retry from a different request reusing a key — and a record of
 * what the first call produced, so the retry can be answered rather than merely
 * refused.
 */
interface OnboardingWriteIdempotencyStore
{
    public const OPERATION_SUBMIT_ATTEMPT = 'onboarding.submitAttempt';

    public const OPERATION_ACKNOWLEDGE_POLICY = 'onboarding.acknowledgePolicy';

    public const OPERATION_SUBMIT_FORM = 'onboarding.submitForm';

    public function find(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
    ): ?OnboardingWriteIdempotencyRecord;

    /**
     * Inserts an `IN_PROGRESS` row, or returns the existing one when the key has
     * been seen with the same request hash.
     *
     * @throws \TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict
     *         when the key exists with a different request hash
     */
    public function tryAcquire(OnboardingWriteIdempotencyRecord $record): OnboardingWriteIdempotencyRecord;

    /**
     * `$resultPublicId` is what the operation produced and is required.
     *
     * Phase 2.5 proved the database does not insist on it, and this contract
     * does: a `COMPLETED` row with no result cannot answer the retry it exists to
     * answer, and the caller would have to redo the write to find out what it had
     * already done.
     */
    public function markCompleted(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $resultPublicId,
    ): void;

    public function markFailed(
        string $actorKey,
        string $operation,
        string $idempotencyKey,
        string $failureReason,
    ): void;
}
