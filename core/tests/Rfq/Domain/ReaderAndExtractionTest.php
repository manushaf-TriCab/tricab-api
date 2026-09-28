<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\Service\ExtractionStatePolicy;
use TriCab\Core\Rfq\Domain\Service\ReaderJobTransitionPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\ExtractionState;
use TriCab\Core\Rfq\Domain\ValueObject\ReaderJobStatus;

/**
 * Plan 1.2 — background reads, and the extraction state derived from them.
 * Source: `draft_store.py` reader-job methods, `reader_jobs.py`.
 */
final class ReaderAndExtractionTest extends TestCase
{
    private static function s(string $status): ReaderJobStatus
    {
        return ReaderJobStatus::fromString($status);
    }

    public function test_the_worker_moves_a_read_through_its_run(): void
    {
        self::assertTrue(ReaderJobTransitionPolicy::workerMay(self::s('queued'), self::s('running')));
        self::assertTrue(ReaderJobTransitionPolicy::workerMay(self::s('running'), self::s('applied')));
        self::assertTrue(ReaderJobTransitionPolicy::workerMay(self::s('running'), self::s('proposed')));
        self::assertTrue(ReaderJobTransitionPolicy::workerMay(self::s('running'), self::s('failed')));
        // A retryable failure is parked for the next tick.
        self::assertTrue(ReaderJobTransitionPolicy::workerMay(self::s('running'), self::s('queued')));
    }

    /** Source `supersede_reader_jobs`: only queued and running reads stand down. */
    public function test_only_unfinished_reads_are_superseded(): void
    {
        self::assertTrue(ReaderJobTransitionPolicy::workerMay(self::s('queued'), self::s('superseded')));
        self::assertTrue(ReaderJobTransitionPolicy::workerMay(self::s('running'), self::s('superseded')));
        self::assertFalse(ReaderJobTransitionPolicy::workerMay(self::s('proposed'), self::s('superseded')));
        self::assertFalse(ReaderJobTransitionPolicy::workerMay(self::s('applied'), self::s('superseded')));
    }

    /** A proposal belongs to the rep: the worker never accepts or dismisses one. */
    public function test_the_worker_cannot_resolve_a_proposal(): void
    {
        self::assertFalse(ReaderJobTransitionPolicy::workerMay(self::s('proposed'), self::s('accepted')));
        self::assertFalse(ReaderJobTransitionPolicy::workerMay(self::s('proposed'), self::s('dismissed')));
    }

    public function test_a_person_accepts_or_dismisses_a_proposal_and_dismisses_a_failure(): void
    {
        self::assertTrue(ReaderJobTransitionPolicy::personMay(self::s('proposed'), self::s('accepted')));
        self::assertTrue(ReaderJobTransitionPolicy::personMay(self::s('proposed'), self::s('dismissed')));
        self::assertTrue(ReaderJobTransitionPolicy::personMay(self::s('failed'), self::s('dismissed')));
    }

    /** Source: "This read failed, so there are no lines to accept." */
    public function test_a_failed_read_has_nothing_to_accept(): void
    {
        $this->expectException(RfqTransitionNotPermitted::class);
        ReaderJobTransitionPolicy::assertPersonMay(self::s('failed'), self::s('accepted'));
    }

    /** Source: "This document is still being read. Wait for it to finish." */
    public function test_a_read_in_flight_cannot_be_resolved(): void
    {
        $this->expectException(RfqTransitionNotPermitted::class);
        ReaderJobTransitionPolicy::assertPersonMay(self::s('running'), self::s('dismissed'));
    }

    public function test_a_person_cannot_requeue_a_read(): void
    {
        try {
            ReaderJobTransitionPolicy::assertPersonMay(self::s('failed'), self::s('queued'));
            self::fail('A person re-queued a read.');
        } catch (RfqTransitionNotPermitted $refused) {
            self::assertSame(RfqTransitionNotPermitted::REASON_ACTOR, $refused->reason);
        }
    }

    public function test_a_stale_read_is_requeued_until_its_attempts_are_used(): void
    {
        self::assertSame('queued', ReaderJobTransitionPolicy::staleOutcome(1)->toString());
        self::assertSame('queued', ReaderJobTransitionPolicy::staleOutcome(2)->toString());
        self::assertSame('failed', ReaderJobTransitionPolicy::staleOutcome(3)->toString());
    }

    // ------------------------------------------------------------ extraction

    public function test_no_documents_and_balanced_coverage_is_complete(): void
    {
        self::assertSame(ExtractionState::COMPLETE, ExtractionStatePolicy::derive([], true)->toString());
    }

    public function test_a_read_in_flight_is_reading(): void
    {
        self::assertSame(
            ExtractionState::READING,
            ExtractionStatePolicy::derive([self::s('applied'), self::s('running')], true)->toString(),
        );
    }

    /** Something a person can act on outranks something still moving. */
    public function test_a_failure_or_proposal_outranks_a_read_in_flight(): void
    {
        self::assertSame(
            ExtractionState::ATTENTION,
            ExtractionStatePolicy::derive([self::s('running'), self::s('failed')], true)->toString(),
        );
        self::assertSame(
            ExtractionState::ATTENTION,
            ExtractionStatePolicy::derive([self::s('proposed')], true)->toString(),
        );
    }

    /** Invariant 3: partial extraction never becomes ready because its rows match. */
    public function test_unbalanced_coverage_is_partial_and_blocks_the_sync(): void
    {
        $state = ExtractionStatePolicy::derive([self::s('applied')], false);

        self::assertSame(ExtractionState::PARTIAL, $state->toString());
        self::assertTrue($state->blocksSync());
    }

    public function test_dismissed_and_superseded_reads_are_settled(): void
    {
        $state = ExtractionStatePolicy::derive([self::s('dismissed'), self::s('superseded'), self::s('accepted')], true);

        self::assertSame(ExtractionState::COMPLETE, $state->toString());
        self::assertFalse($state->blocksSync());
    }
}
