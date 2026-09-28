<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Rfq\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Rfq\Domain\Exception\RfqTransitionNotPermitted;
use TriCab\Core\Rfq\Domain\Service\SyncAttemptTransitionPolicy;
use TriCab\Core\Rfq\Domain\ValueObject\SyncAttemptState;
use TriCab\Core\Rfq\Domain\ValueObject\SyncHeaderState;

/**
 * Plan 1.2, invariant 8 — the sync-attempt machine. Each case names the
 * source behaviour it carries over (`test_durable_sync`, `sync_jobs.py`).
 */
final class SyncAttemptTransitionPolicyTest extends TestCase
{
    private static function a(string $state): SyncAttemptState
    {
        return SyncAttemptState::fromString($state);
    }

    private static function h(string $state): SyncHeaderState
    {
        return SyncHeaderState::fromString($state);
    }

    // ------------------------------------------------------------------ sweep

    /** The header was in flight when the worker died: never retried blind. */
    public function test_an_interrupted_header_with_no_quote_number_becomes_uncertain(): void
    {
        self::assertSame('uncertain', SyncAttemptTransitionPolicy::sweepOutcome(self::h('sent'), false, 1)->toString());
        self::assertSame('uncertain', SyncAttemptTransitionPolicy::sweepOutcome(self::h('unknown'), false, 1)->toString());
    }

    /** Uncertainty wins even when the attempts are used up: parking it would lose the "go and look" signal. */
    public function test_uncertainty_outranks_the_attempt_cap(): void
    {
        self::assertSame('uncertain', SyncAttemptTransitionPolicy::sweepOutcome(self::h('sent'), false, 99)->toString());
    }

    /** A known quote number resumes against the same quote. */
    public function test_a_confirmed_header_is_requeued_to_resume(): void
    {
        self::assertSame('queued', SyncAttemptTransitionPolicy::sweepOutcome(self::h('confirmed'), true, 1)->toString());
        self::assertSame('queued', SyncAttemptTransitionPolicy::sweepOutcome(self::h('pending'), false, 2)->toString());
    }

    public function test_an_attempt_that_used_its_tries_is_parked_as_failed(): void
    {
        self::assertSame('failed', SyncAttemptTransitionPolicy::sweepOutcome(self::h('confirmed'), true, 3)->toString());
    }

    // ----------------------------------------------------------------- finish

    public function test_success_needs_a_confirmed_header(): void
    {
        SyncAttemptTransitionPolicy::assertMayFinish(self::a('succeeded'), self::h('confirmed'), true);

        $this->expectException(RfqTransitionNotPermitted::class);
        SyncAttemptTransitionPolicy::assertMayFinish(self::a('succeeded'), self::h('sent'), false);
    }

    /** A timeout after sending is not a failure. It is not knowable. */
    public function test_failed_is_refused_while_a_quote_may_exist_with_no_number(): void
    {
        $this->expectException(RfqTransitionNotPermitted::class);
        SyncAttemptTransitionPolicy::assertMayFinish(self::a('failed'), self::h('unknown'), false);
    }

    public function test_failed_is_allowed_when_4js_certainly_did_nothing(): void
    {
        $this->expectNotToPerformAssertions();

        SyncAttemptTransitionPolicy::assertMayFinish(self::a('failed'), self::h('rejected'), false);
        SyncAttemptTransitionPolicy::assertMayFinish(self::a('failed'), self::h('pending'), false);
        // Lines rejected on a quote whose number we hold: a partial, known outcome.
        SyncAttemptTransitionPolicy::assertMayFinish(self::a('failed'), self::h('confirmed'), true);
    }

    // ------------------------------------------------------ supersede, enqueue

    /** Source `supersede_sync_attempts`: queued only; uncertain is never cleared. */
    public function test_only_a_queued_attempt_is_superseded(): void
    {
        self::assertTrue(SyncAttemptTransitionPolicy::maySupersede(self::a('queued')));
        self::assertFalse(SyncAttemptTransitionPolicy::maySupersede(self::a('running')));
        self::assertFalse(SyncAttemptTransitionPolicy::maySupersede(self::a('uncertain')));
    }

    /** Source `blocking_attempt`: while one is uncertain, no second push for the same RFQ. */
    public function test_an_uncertain_attempt_blocks_a_new_one(): void
    {
        self::assertFalse(SyncAttemptTransitionPolicy::mayEnqueue([self::a('failed'), self::a('uncertain')]));
    }

    /** The open attempt is the lock here; the source had a second one. */
    public function test_an_open_attempt_blocks_a_new_one(): void
    {
        self::assertFalse(SyncAttemptTransitionPolicy::mayEnqueue([self::a('queued')]));
        self::assertFalse(SyncAttemptTransitionPolicy::mayEnqueue([self::a('running')]));
    }

    public function test_finished_attempts_do_not_block_a_new_one(): void
    {
        self::assertTrue(SyncAttemptTransitionPolicy::mayEnqueue([]));
        self::assertTrue(SyncAttemptTransitionPolicy::mayEnqueue([self::a('failed'), self::a('succeeded'), self::a('superseded')]));
    }

    public function test_the_system_cannot_move_an_uncertain_attempt(): void
    {
        $this->expectException(RfqTransitionNotPermitted::class);
        SyncAttemptTransitionPolicy::assertSystemMay(self::a('uncertain'), self::a('queued'));
    }

    // -------------------------------------------------------------- reconcile

    public function test_adopting_the_quote_found_in_4js_resumes_against_it(): void
    {
        [$state, $header] = SyncAttemptTransitionPolicy::reconcile(self::a('uncertain'), 'Q123456');

        self::assertSame('queued', $state->toString());
        self::assertSame('confirmed', $header->toString());
    }

    public function test_confirming_there_is_no_quote_sends_again_from_the_start(): void
    {
        [$state, $header] = SyncAttemptTransitionPolicy::reconcile(self::a('uncertain'), null);

        self::assertSame('queued', $state->toString());
        self::assertSame('pending', $header->toString());
    }

    public function test_only_an_uncertain_attempt_is_reconciled(): void
    {
        $this->expectException(RfqTransitionNotPermitted::class);
        SyncAttemptTransitionPolicy::reconcile(self::a('failed'), null);
    }

    public function test_adopting_needs_the_quote_number(): void
    {
        $this->expectException(RfqTransitionNotPermitted::class);
        SyncAttemptTransitionPolicy::reconcile(self::a('uncertain'), '  ');
    }

    /** A stored state nobody can read might have created a quote. */
    public function test_an_unreadable_stored_state_reads_as_uncertain(): void
    {
        self::assertSame('uncertain', SyncAttemptState::fromStorage('half-sent')->toString());
        self::assertSame('unknown', SyncHeaderState::fromStorage(null)->toString());
    }
}
