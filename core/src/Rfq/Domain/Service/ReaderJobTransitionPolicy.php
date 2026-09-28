<?php

declare(strict_types=1);

namespace TriCab\Core\Rfq\Domain\Service;

use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;

/**
 * The background-read machine: a from → to graph, split by who may drive it.
 *
 * Ported from `draft_store.py` (`next_reader_draft`, `finish_reader_job`,
 * `recover_stale_reader_jobs`, `supersede_reader_jobs`, `resolve_reader_job`)
 * and `reader_jobs.py`.
 *
 * ```
 *            ┌──────────── superseded ◄──────────┐
 *            │                                   │
 *  queued ──► running ──► applied                │
 *    ▲          │  │  └──► proposed ──► accepted  │   (person)
 *    │          │  │            └─────► dismissed │   (person)
 *    └──────────┘  └──► failed ─────► dismissed   │   (person)
 *   retryable or                                  │
 *   stale, under                                  │
 *   the attempt cap          queued, running ─────┘
 * ```
 *
 * The worker drives everything except `accepted` and `dismissed`; a person
 * drives only those two. A person cannot re-run a read by moving it back to
 * `queued` — re-reading is a new job on a new upload, which the source also
 * requires (supersede, then enqueue).
 */
final class ReaderJobTransitionPolicy
{
    private const MACHINE = 'reader job';

    /** Source `reader_jobs.py`: a running claim is stale after 30 minutes. */
    public const STALE_AFTER_MINUTES = 30;

    /** Source `reader_jobs.py`: a job is parked as failed after 3 attempts. */
    public const MAX_ATTEMPTS = 3;

    /** @var array<string, list<string>> */
    private const WORKER_GRAPH = [
        ReaderJobStatus::QUEUED => [ReaderJobStatus::RUNNING, ReaderJobStatus::SUPERSEDED],
        ReaderJobStatus::RUNNING => [
            ReaderJobStatus::APPLIED,
            ReaderJobStatus::PROPOSED,
            ReaderJobStatus::FAILED,
            ReaderJobStatus::QUEUED,
            ReaderJobStatus::SUPERSEDED,
        ],
    ];

    /** @var array<string, list<string>> */
    private const PERSON_GRAPH = [
        ReaderJobStatus::PROPOSED => [ReaderJobStatus::ACCEPTED, ReaderJobStatus::DISMISSED],
        // Dismissing a failure is how a rep says "I have read why, and added
        // the lines by hand or decided the file does not matter".
        ReaderJobStatus::FAILED => [ReaderJobStatus::DISMISSED],
    ];

    public static function workerMay(ReaderJobStatus $from, ReaderJobStatus $to): bool
    {
        return in_array($to->toString(), self::WORKER_GRAPH[$from->toString()] ?? [], true);
    }

    public static function personMay(ReaderJobStatus $from, ReaderJobStatus $to): bool
    {
        return in_array($to->toString(), self::PERSON_GRAPH[$from->toString()] ?? [], true);
    }

    /** @throws RfqTransitionNotPermitted */
    public static function assertWorkerMay(ReaderJobStatus $from, ReaderJobStatus $to): void
    {
        if (!self::workerMay($from, $to)) {
            throw RfqTransitionNotPermitted::fromState(self::MACHINE, $from->toString(), $to->toString());
        }
    }

    /**
     * A person resolving a read. "Accept" needs a proposal; a failed read has
     * no rows to accept, and the source says so rather than accepting nothing.
     *
     * @throws RfqTransitionNotPermitted
     */
    public static function assertPersonMay(ReaderJobStatus $from, ReaderJobStatus $to): void
    {
        if (!in_array($to->toString(), [ReaderJobStatus::ACCEPTED, ReaderJobStatus::DISMISSED], true)) {
            throw RfqTransitionNotPermitted::forActor(self::MACHINE, 'a person', $to->toString());
        }

        if (!self::personMay($from, $to)) {
            throw RfqTransitionNotPermitted::fromState(self::MACHINE, $from->toString(), $to->toString());
        }
    }

    /**
     * What a stale `running` job becomes: back in the queue while it has
     * attempts left, parked as `failed` once it has used them.
     */
    public static function staleOutcome(int $attempts, int $maxAttempts = self::MAX_ATTEMPTS): ReaderJobStatus
    {
        return ReaderJobStatus::fromString(
            $attempts >= $maxAttempts ? ReaderJobStatus::FAILED : ReaderJobStatus::QUEUED,
        );
    }
}
