<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Service;

use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Domain\ValueObject\SyncHeaderState;

/**
 * The 4js sync-attempt machine. Pure: the worker, the sweep and the reconcile
 * endpoint ask this, and the persistence adapter enforces the answer inside
 * one locked transaction (Phase 2.4).
 *
 * Ported from `sync_jobs.py` and the `rfq_sync_attempts` methods of
 * `draft_store.py` at the pinned source, which is the best-evidenced part of
 * the source (`test_durable_sync`, 41 tests):
 *
 * ```
 *   queued ──► running ──► succeeded
 *     │  ▲        │  └───► failed        (nothing created, or lines failed on a known quote)
 *     │  └────────┤  └───► uncertain ──► queued   (only by reconcile, only by a person)
 *     ▼   sweep:  │
 *  superseded     └ requeue if the header is pending or confirmed
 * ```
 *
 * The three rules everything else serves (invariant 8):
 *
 *  1. An attempt that may have created a quote whose number nobody has is
 *     `uncertain`, never `failed` and never re-queued automatically.
 *  2. `uncertain` is never superseded. Nothing about the RFQ changing makes it
 *     knowable whether 4js acted.
 *  3. While one is uncertain, no new attempt for the same RFQ is created.
 */
final class SyncAttemptTransitionPolicy
{
    private const MACHINE = 'sync attempt';

    /** Source `RFQ_SYNC_STALE_MINUTES` default. */
    public const STALE_AFTER_MINUTES = 15;

    /** Source `RFQ_SYNC_MAX_ATTEMPTS` default. */
    public const MAX_ATTEMPTS = 3;

    /** @var array<string, list<string>> */
    private const SYSTEM_GRAPH = [
        SyncAttemptState::QUEUED => [SyncAttemptState::RUNNING, SyncAttemptState::SUPERSEDED],
        SyncAttemptState::RUNNING => [
            SyncAttemptState::SUCCEEDED,
            SyncAttemptState::FAILED,
            SyncAttemptState::UNCERTAIN,
            SyncAttemptState::QUEUED,
        ],
    ];

    /** @throws RfqTransitionNotPermitted */
    public static function assertSystemMay(SyncAttemptState $from, SyncAttemptState $to): void
    {
        if (!in_array($to->toString(), self::SYSTEM_GRAPH[$from->toString()] ?? [], true)) {
            throw RfqTransitionNotPermitted::fromState(self::MACHINE, $from->toString(), $to->toString());
        }
    }

    /**
     * Closing a running attempt. `failed` is refused while the header may
     * have created a quote nobody has the number of: the caller must record
     * `uncertain` instead. `succeeded` needs a confirmed header.
     *
     * @throws RfqTransitionNotPermitted
     */
    public static function assertMayFinish(
        SyncAttemptState $to,
        SyncHeaderState $header,
        bool $hasQuoteNumber,
    ): void {
        self::assertSystemMay(SyncAttemptState::fromString(SyncAttemptState::RUNNING), $to);

        if ($to->is(SyncAttemptState::SUCCEEDED) && !$header->is(SyncHeaderState::CONFIRMED)) {
            throw RfqTransitionNotPermitted::fromState(self::MACHINE, 'header ' . $header->toString(), $to->toString());
        }

        if ($to->is(SyncAttemptState::FAILED) && $header->mayHaveCreatedQuote() && !$hasQuoteNumber) {
            throw RfqTransitionNotPermitted::fromState(self::MACHINE, 'header ' . $header->toString(), $to->toString());
        }
    }

    /**
     * What the sweep does with a `running` attempt nobody finished — a worker
     * killed by a deploy, a recycle or an out-of-memory. Source
     * `sweep_stale_sync_attempts`, in the same order:
     *
     *  - header in flight and no quote number → `uncertain`;
     *  - attempts used up → `failed` (parked, with the reason on it);
     *  - otherwise → `queued`, to resume against the same quote.
     */
    public static function sweepOutcome(
        SyncHeaderState $header,
        bool $hasQuoteNumber,
        int $attemptNo,
        int $maxAttempts = self::MAX_ATTEMPTS,
    ): SyncAttemptState {
        if ($header->mayHaveCreatedQuote() && !$hasQuoteNumber) {
            return SyncAttemptState::fromString(SyncAttemptState::UNCERTAIN);
        }

        if ($attemptNo >= $maxAttempts) {
            return SyncAttemptState::fromString(SyncAttemptState::FAILED);
        }

        return SyncAttemptState::queued();
    }

    /**
     * Whether a queued attempt may be stood down because the RFQ no longer
     * wants it (a new revision, an archive). Only `queued`: a running attempt
     * is finished by its worker, and an uncertain one waits for a person.
     */
    public static function maySupersede(SyncAttemptState $state): bool
    {
        return $state->is(SyncAttemptState::QUEUED);
    }

    /**
     * Whether a new attempt may be created for an RFQ that already has these.
     *
     * The source refuses only while one is `uncertain` and leaves queued and
     * running to its per-draft sync lock. The platform has no second lock: the
     * open attempt *is* the lock, so queued and running refuse here as well.
     *
     * @param list<SyncAttemptState> $existing
     */
    public static function mayEnqueue(array $existing): bool
    {
        foreach ($existing as $state) {
            if ($state->isOneOf([SyncAttemptState::QUEUED, SyncAttemptState::RUNNING, SyncAttemptState::UNCERTAIN])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reconciling an uncertain attempt, by a person holding
     * `RFQ.QUOTE.RECONCILE` who has looked in 4js. Two answers, and only two:
     *
     *  - **adopt** the quote they found: the header becomes `confirmed` with
     *    that number, and the attempt resumes against it;
     *  - **no quote**: the header returns to `pending` and the attempt is sent
     *    again from the start. The one transition that is unsafe on a guess.
     *
     * Either way the attempt goes back to `queued`. The returned pair is
     * `[attempt state, header state]`.
     *
     * @return array{0: SyncAttemptState, 1: SyncHeaderState}
     *
     * @throws RfqTransitionNotPermitted
     */
    public static function reconcile(SyncAttemptState $from, ?string $adoptedQuoteNumber): array
    {
        if (!$from->is(SyncAttemptState::UNCERTAIN)) {
            throw RfqTransitionNotPermitted::fromState(self::MACHINE, $from->toString(), SyncAttemptState::QUEUED);
        }

        if ($adoptedQuoteNumber !== null && trim($adoptedQuoteNumber) === '') {
            throw RfqTransitionNotPermitted::missingInput(self::MACHINE, 'an adopted quote needs its 4js quote number.');
        }

        return [
            SyncAttemptState::queued(),
            SyncHeaderState::fromString($adoptedQuoteNumber !== null ? SyncHeaderState::CONFIRMED : SyncHeaderState::PENDING),
        ];
    }
}
