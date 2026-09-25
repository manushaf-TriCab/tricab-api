<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO;

use DateTimeImmutable;
use TriCab\Core\QuoteOrder\Domain\ValueObject\QuoteWriteIdempotencyState;

/**
 * One row of `onboarding.OnboardingWriteIdempotency`.
 *
 * The state vocabulary is {@see QuoteWriteIdempotencyState}, reused rather than
 * redeclared. The schema comment says why: "two different shapes of the same
 * idea in one database is how one of them ends up wrong". A second
 * `IN_PROGRESS | COMPLETED | FAILED` enum would drift the first time somebody
 * adds a state to one of them, and the `CHECK` constraints on the two tables are
 * already written from the same three strings.
 *
 * ## The fields that carry the weight
 *
 * {@see $requestHash} is what makes a replay distinguishable from a second,
 * different request wearing the same key. Same key + same hash is the retry a
 * flaky network produces, and the first result is returned. Same key + different
 * hash is a client defect, and answering it with the first result would silently
 * discard the second submission — so it is refused.
 *
 * {@see $resultPublicId} is the id of whatever the operation produced: an
 * acknowledgement, a submission, an attempt. Phase 2.5 found that no CHECK
 * constraint requires a `COMPLETED` row to carry one, and recorded that none
 * should — the constraint would have to allow the window between the write and
 * the ledger update anyway. So it is an obligation of this layer, and it has a
 * test of its own rather than a constraint.
 */
final readonly class OnboardingWriteIdempotencyRecord
{
    public function __construct(
        public string $actorKey,
        public string $operation,
        public string $idempotencyKey,
        public string $requestHash,
        public string $state,
        public string $platformOperationId,
        public ?string $resultPublicId,
        public DateTimeImmutable $createdAtUtc,
        public DateTimeImmutable $updatedAtUtc,
        public ?string $failureReason = null,
    ) {
    }

    public function stateVo(): QuoteWriteIdempotencyState
    {
        return QuoteWriteIdempotencyState::fromString($this->state);
    }

    public function isCompleted(): bool
    {
        return $this->stateVo()->isCompleted();
    }
}
