<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Contract;

use TriCab\Core\Rfq\Application\DTO\SyncAttemptView;
use TriCab\Core\Rfq\Domain\Exception\RfqDraftNotEditable;
use TriCab\Core\Rfq\Domain\Exception\RfqRevisionConflict;
use TriCab\Core\Rfq\Domain\Exception\RfqSyncNeedsReconciliation;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Storage\DraftActor;

/**
 * The durable record of a sync (parity plan E7.3; architecture doc §5.4). One
 * row in `rfq_sync_attempts` per attempt, written **before** 4js is contacted
 * and checkpointed as each step settles, so after any crash there is a row
 * saying what 4js was told.
 *
 * The store decides no business rule. {@see \TriCab\Core\Rfq\Domain\Service\SyncAttemptTransitionPolicy}
 * says which moves are legal and the service asks it; the store enforces the
 * answer under the draft's row lock, in one transaction per method, each with
 * its audit event where the trail needs one.
 *
 * Two orderings carry the design (source `matcher/sync_jobs.py`):
 *
 *  - `noteHeaderIntent` is written before the header request leaves, so a crash
 *    inside it is distinguishable from one before it;
 *  - `noteHeaderOk` is written the instant a quote number comes back, before the
 *    first line is sent, so a retry versions that quote instead of raising a second.
 */
interface RfqSyncStore
{
    /**
     * Start an attempt for the revision the person holds. Under the draft's row
     * lock: the revision is checked, stale attempts of this draft are swept, the
     * draft must not be archived, already in 4js, mid-sync, waiting on a document
     * read, or have an attempt that is open or uncertain; then the sales rep
     * attribution is written, the sync lock taken and the attempt recorded
     * (`running`, header `pending`, plan frozen) with its `sync_queued` event.
     *
     * @param array{quoteDetails: array<string, mixed>, assignee: ?string, authorSource: ?string, assigneeChange: ?array{from: ?string, to: string}} $rep
     * @param array<string, mixed>                                                                                                                    $plan
     *
     * @return string|null the attempt id; null when there is no such draft
     *
     * @throws RfqRevisionConflict
     * @throws RfqDraftNotEditable
     * @throws RfqSyncNeedsReconciliation
     */
    public function begin(string $draftId, int $expectedRevision, DraftActor $actor, array $rep, array $plan, string $payload, string $payloadSha256, string $correlationId, string $origin): ?string;

    public function noteHeaderIntent(string $attemptId): void;

    public function noteHeaderOk(string $attemptId, string $refno, string $refver): void;

    /** @param bool $certain true when 4js answered no (header `rejected`), false when the outcome is not known (`unknown`) */
    public function noteHeaderFailed(string $attemptId, string $error, bool $certain): void;

    /**
     * One line settled. `$detail` is `{code, ...}` for an accepted line and
     * `{code, error, row_number, row_id}` for a rejected one.
     *
     * @param array<string, mixed> $detail
     */
    public function noteLine(string $attemptId, int $index, bool $accepted, array $detail, int $nextLine): void;

    /**
     * Close the attempt and settle the draft in one transaction: `succeeded`
     * marks the draft synced (the system's `completed`) with its quote number;
     * `failed` and `uncertain` mark it failed with the reason, keeping any quote
     * number so a retry versions it. The lock is released and the revision
     * advances (the draft changed), as the source's `mark_synced` and
     * `mark_push_failed` do. Writes `sync_finished`.
     *
     * @param array<string, mixed> $result what happened, kept with the attempt and (trimmed) with the draft
     *
     * @throws \TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted when the policy refuses the move
     */
    public function finishAttempt(string $attemptId, SyncAttemptState $state, array $result, string $error, string $reconcileNote, DraftActor $actor): void;

    public function attempt(string $attemptId): ?SyncAttemptView;

    /** @return list<SyncAttemptView> newest first */
    public function attemptsFor(string $draftId, int $limit): array;

    /**
     * Close the attempts of one draft that were left `running` and have not
     * been touched since `$beforeIso` (a worker killed by a deploy or a crash),
     * by the policy's `sweepOutcome`: header in flight with no quote number is
     * `uncertain`, never retried; anything else is `failed` with the reason, and
     * the draft's lock is released. Nothing resumes a half-sent attempt in this
     * slice; the next sync starts a new one against the same quote if its header
     * landed. Returns how many attempts were closed.
     */
    public function sweepStale(string $draftId, string $beforeIso): int;
}
