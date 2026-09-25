<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\AssignmentStatusIsFinal;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\StatusChangeNotPermitted;
use TriCab\Core\Onboarding\Domain\Service\AssignmentProgress;
use TriCab\Core\Onboarding\Domain\Service\AssignmentStatusPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusActor;

/**
 * Phase 2.1 — the six-state assignment machine, ported from the source's
 * `api/_lib/status.ts` and its nine tests (plan 0.7 section C).
 *
 * Exhaustive by construction: the transition table below covers all
 * 3 actors × 6 from-states × 6 to-states = 108 combinations, so a rule added or
 * relaxed later cannot pass unnoticed. The named tests above it say *why* the
 * interesting cells are what they are.
 */
final class AssignmentStatusMachineTest extends TestCase
{
    // ---------------------------------------------------------------- vocabulary

    public function test_the_vocabulary_is_exactly_the_six_ported_statuses(): void
    {
        self::assertSame([
            'not_started',
            'in_progress',
            'need_help',
            'submitted',
            'fix_and_resubmit',
            'completed',
        ], AssignmentStatus::ALL);
    }

    public function test_labels_are_the_words_people_already_recognise(): void
    {
        self::assertSame('Waiting on review', AssignmentStatus::submitted()->label());
        self::assertSame('Changes requested', AssignmentStatus::fixAndResubmit()->label());
        self::assertSame('Needs help', AssignmentStatus::needHelp()->label());
        self::assertSame('Complete', AssignmentStatus::completed()->label());
    }

    public function test_fromString_rejects_anything_outside_the_vocabulary(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        AssignmentStatus::fromString('definitely_done');
    }

    public function test_fromString_normalises_case_and_whitespace(): void
    {
        self::assertSame('need_help', AssignmentStatus::fromString('  NEED_HELP ')->toString());
    }

    /**
     * A row somebody hand-edited, or one predating a vocabulary change, must not
     * lock a person out of their own induction. It reads as work still to do.
     */
    public function test_fromStorage_tolerates_a_value_that_should_not_be_there(): void
    {
        self::assertSame('submitted', AssignmentStatus::fromStorage('submitted')->toString());
        self::assertSame('not_started', AssignmentStatus::fromStorage('nonsense')->toString());
        self::assertSame('not_started', AssignmentStatus::fromStorage(null)->toString());
        self::assertSame('not_started', AssignmentStatus::fromStorage(42)->toString());
        self::assertSame('not_started', AssignmentStatus::fromStorage('')->toString());
    }

    /** The tolerant reader must never invent a completion. */
    public function test_fromStorage_never_falls_back_to_a_finished_state(): void
    {
        self::assertFalse(AssignmentStatus::fromStorage('who knows')->isFinal());
    }

    public function test_actor_rejects_an_invented_actor(): void
    {
        $this->expectException(InvalidOnboardingValue::class);
        StatusActor::fromString('manager');
    }

    // ------------------------------------------------------------------- the sets

    public function test_completed_is_the_only_final_status(): void
    {
        foreach (AssignmentStatus::all() as $status) {
            self::assertSame(
                $status->toString() === AssignmentStatus::COMPLETED,
                $status->isFinal(),
                $status->toString() . ' finality',
            );
        }
    }

    /**
     * `need_help` is in the queue deliberately: someone stuck cannot make
     * progress at all, and a reviewer who only watches submissions would miss
     * them.
     */
    public function test_the_review_queue_holds_submissions_and_people_who_are_stuck(): void
    {
        $inQueue = array_map(
            static fn (AssignmentStatus $status): string => $status->toString(),
            AssignmentStatus::needsReviewSet(),
        );
        sort($inQueue);

        self::assertSame(['need_help', 'submitted'], $inQueue);
    }

    public function test_someone_stuck_outranks_a_submission_in_the_queue(): void
    {
        self::assertLessThan(
            AssignmentStatus::submitted()->queueOrder(),
            AssignmentStatus::needHelp()->queueOrder(),
        );
    }

    public function test_finished_work_sorts_last_in_the_queue(): void
    {
        foreach (AssignmentStatus::all() as $status) {
            if ($status->isFinal()) {
                continue;
            }
            self::assertLessThan(
                AssignmentStatus::completed()->queueOrder(),
                $status->queueOrder(),
                $status->toString() . ' must outrank completed work',
            );
        }
    }

    public function test_every_status_has_a_queue_order_and_a_progress_weight(): void
    {
        $orders = [];
        foreach (AssignmentStatus::all() as $status) {
            $orders[] = $status->queueOrder();
            self::assertGreaterThanOrEqual(0, $status->progressWeightPercent());
            self::assertLessThanOrEqual(100, $status->progressWeightPercent());
        }

        // Distinct, or two statuses would sort arbitrarily against each other.
        self::assertCount(count(AssignmentStatus::ALL), array_unique($orders));
    }

    public function test_a_status_is_either_the_persons_own_work_or_somebody_elses(): void
    {
        foreach (AssignmentStatus::all() as $status) {
            $claims = (int) $status->isToBeWorkedOn()
                + (int) $status->needsReview()
                + (int) $status->isFinal();

            // need_help is both the person's own work and in the review queue —
            // they are stuck, and somebody has to unstick them.
            $expected = $status->toString() === AssignmentStatus::NEED_HELP ? 2 : 1;
            self::assertSame($expected, $claims, $status->toString() . ' set membership');
        }
    }

    // ------------------------------------------------------------ who may set what

    /** The property that makes the whole record worth keeping. */
    public function test_a_person_cannot_mark_their_own_induction_complete(): void
    {
        self::assertFalse(AssignmentStatusPolicy::canSet(
            StatusActor::trainee(),
            AssignmentStatus::submitted(),
            AssignmentStatus::completed(),
        ));

        $settable = array_map(
            static fn (AssignmentStatus $status): string => $status->toString(),
            AssignmentStatusPolicy::settableBy(StatusActor::trainee()),
        );
        self::assertNotContains(AssignmentStatus::COMPLETED, $settable);
    }

    public function test_a_person_may_start_ask_for_help_and_hand_in(): void
    {
        $trainee = StatusActor::trainee();

        self::assertTrue(AssignmentStatusPolicy::canSet(
            $trainee,
            AssignmentStatus::notStarted(),
            AssignmentStatus::inProgress(),
        ));
        self::assertTrue(AssignmentStatusPolicy::canSet(
            $trainee,
            AssignmentStatus::inProgress(),
            AssignmentStatus::needHelp(),
        ));
        self::assertTrue(AssignmentStatusPolicy::canSet(
            $trainee,
            AssignmentStatus::inProgress(),
            AssignmentStatus::submitted(),
        ));
    }

    /**
     * Preserved from the source rather than tightened: a form filled in one
     * sitting is submitted straight from `not_started`, and a resubmission
     * after a browser retry must not fail.
     */
    public function test_handing_in_does_not_require_passing_through_in_progress(): void
    {
        self::assertTrue(AssignmentStatusPolicy::canSet(
            StatusActor::trainee(),
            AssignmentStatus::notStarted(),
            AssignmentStatus::submitted(),
        ));
        self::assertTrue(AssignmentStatusPolicy::canSet(
            StatusActor::trainee(),
            AssignmentStatus::submitted(),
            AssignmentStatus::submitted(),
        ));
    }

    public function test_a_reviewer_may_accept_or_send_back(): void
    {
        self::assertTrue(AssignmentStatusPolicy::canSet(
            StatusActor::reviewer(),
            AssignmentStatus::submitted(),
            AssignmentStatus::completed(),
        ));
        self::assertTrue(AssignmentStatusPolicy::canSet(
            StatusActor::reviewer(),
            AssignmentStatus::submitted(),
            AssignmentStatus::fixAndResubmit(),
        ));
    }

    /**
     * A reviewer raising a hand on somebody's behalf puts words in their mouth
     * in a record later used as evidence. A reviewer who thinks someone is
     * stuck sends the work back with a reason, which is attributable.
     */
    public function test_a_reviewer_cannot_raise_a_hand_on_someone_elses_behalf(): void
    {
        self::assertFalse(AssignmentStatusPolicy::canSet(
            StatusActor::reviewer(),
            AssignmentStatus::inProgress(),
            AssignmentStatus::needHelp(),
        ));
    }

    public function test_completed_work_is_final_for_a_person_but_reopenable_by_a_reviewer(): void
    {
        self::assertFalse(AssignmentStatusPolicy::canSet(
            StatusActor::trainee(),
            AssignmentStatus::completed(),
            AssignmentStatus::inProgress(),
        ));
        self::assertTrue(AssignmentStatusPolicy::canSet(
            StatusActor::reviewer(),
            AssignmentStatus::completed(),
            AssignmentStatus::fixAndResubmit(),
        ));
    }

    public function test_the_system_only_ever_opens_or_completes(): void
    {
        $settable = array_map(
            static fn (AssignmentStatus $status): string => $status->toString(),
            AssignmentStatusPolicy::settableBy(StatusActor::system()),
        );
        sort($settable);

        self::assertSame(['completed', 'in_progress'], $settable);
    }

    /** Work does not un-begin. */
    public function test_nobody_may_set_not_started(): void
    {
        foreach ([StatusActor::trainee(), StatusActor::reviewer(), StatusActor::system()] as $actor) {
            self::assertFalse(AssignmentStatusPolicy::canSet(
                $actor,
                AssignmentStatus::inProgress(),
                AssignmentStatus::notStarted(),
            ), $actor->toString() . ' must not be able to reset work to not started');
        }
    }

    public function test_only_a_reviewer_may_leave_a_final_status(): void
    {
        self::assertTrue(AssignmentStatusPolicy::canLeave(
            StatusActor::reviewer(),
            AssignmentStatus::completed(),
        ));
        self::assertFalse(AssignmentStatusPolicy::canLeave(
            StatusActor::trainee(),
            AssignmentStatus::completed(),
        ));
        self::assertFalse(AssignmentStatusPolicy::canLeave(
            StatusActor::system(),
            AssignmentStatus::completed(),
        ));
    }

    // --------------------------------------------------------------- the guard

    /**
     * "You may never set this" is a client bug (403). "Not from here" is almost
     * always a stale tab (409). Collapsing them would hide a real defect behind
     * a reload prompt.
     */
    public function test_the_guard_distinguishes_a_forbidden_change_from_a_stale_one(): void
    {
        try {
            AssignmentStatusPolicy::assertCanSet(
                StatusActor::trainee(),
                AssignmentStatus::inProgress(),
                AssignmentStatus::completed(),
            );
            self::fail('Expected StatusChangeNotPermitted.');
        } catch (StatusChangeNotPermitted $exception) {
            self::assertStringContainsString('cannot set this work to "Complete"', $exception->getMessage());
            self::assertSame('trainee', $exception->actor->toString());
            self::assertSame('completed', $exception->to->toString());
        }

        try {
            AssignmentStatusPolicy::assertCanSet(
                StatusActor::trainee(),
                AssignmentStatus::completed(),
                AssignmentStatus::inProgress(),
            );
            self::fail('Expected AssignmentStatusIsFinal.');
        } catch (AssignmentStatusIsFinal $exception) {
            self::assertStringContainsString('already complete', $exception->getMessage());
            self::assertSame('completed', $exception->from->toString());
        }
    }

    /**
     * Both faults at once — a trainee setting `completed` from `completed` —
     * reports the more serious one, which is true wherever the work sits.
     */
    public function test_a_forbidden_change_is_reported_ahead_of_a_final_state(): void
    {
        $this->expectException(StatusChangeNotPermitted::class);
        AssignmentStatusPolicy::assertCanSet(
            StatusActor::trainee(),
            AssignmentStatus::completed(),
            AssignmentStatus::completed(),
        );
    }

    public function test_the_guard_permits_what_canSet_permits(): void
    {
        AssignmentStatusPolicy::assertCanSet(
            StatusActor::reviewer(),
            AssignmentStatus::submitted(),
            AssignmentStatus::completed(),
        );

        self::assertTrue(true, 'assertCanSet did not throw for a permitted change');
    }

    // ----------------------------------------------------- exhaustive transition table

    /**
     * All 108 actor × from × to combinations. The expectation is derived from
     * the two rules — settable-by, and only a reviewer leaves a final state —
     * stated here independently of the implementation, so a change to either
     * rule shows up as a diff in this test rather than as silent new behaviour.
     */
    public function test_every_actor_from_and_to_combination_is_accounted_for(): void
    {
        $settable = [
            'trainee' => ['in_progress', 'need_help', 'submitted'],
            'reviewer' => ['in_progress', 'fix_and_resubmit', 'completed'],
            'system' => ['in_progress', 'completed'],
        ];

        $checked = 0;

        foreach ($settable as $actorName => $permittedTargets) {
            $actor = StatusActor::fromString($actorName);

            foreach (AssignmentStatus::all() as $from) {
                foreach (AssignmentStatus::all() as $to) {
                    $mayLeave = !$from->isFinal() || $actorName === 'reviewer';
                    $expected = $mayLeave && in_array($to->toString(), $permittedTargets, true);

                    self::assertSame(
                        $expected,
                        AssignmentStatusPolicy::canSet($actor, $from, $to),
                        sprintf('%s: %s → %s', $actorName, $from->toString(), $to->toString()),
                    );

                    $checked++;
                }
            }
        }

        self::assertSame(108, $checked);
    }

    // ------------------------------------------------------------------- progress

    public function test_progress_is_zero_for_nothing_allocated(): void
    {
        self::assertSame(0, AssignmentProgress::percent([]));
    }

    public function test_progress_counts_untouched_work_as_zero_and_finished_work_as_full(): void
    {
        self::assertSame(0, AssignmentProgress::percent([
            AssignmentStatus::notStarted(),
            AssignmentStatus::notStarted(),
        ]));
        self::assertSame(100, AssignmentProgress::percent([
            AssignmentStatus::completed(),
            AssignmentStatus::completed(),
        ]));
    }

    /**
     * The case the weighting exists for: everything handed in and waiting on a
     * reviewer must not read as untouched to whoever is chasing it.
     */
    public function test_work_handed_in_earns_partial_credit(): void
    {
        $submitted = AssignmentProgress::percent([
            AssignmentStatus::submitted(),
            AssignmentStatus::submitted(),
        ]);

        self::assertGreaterThan(0, $submitted);
        self::assertLessThan(100, $submitted);
        self::assertSame(75, $submitted);
    }

    public function test_progress_rounds_to_a_whole_percent(): void
    {
        // 0 + 25 + 100 = 125 over three items = 41.67 → 42.
        self::assertSame(42, AssignmentProgress::percent([
            AssignmentStatus::notStarted(),
            AssignmentStatus::inProgress(),
            AssignmentStatus::completed(),
        ]));
    }

    public function test_being_stuck_does_not_cost_progress_already_made(): void
    {
        self::assertSame(
            AssignmentStatus::inProgress()->progressWeightPercent(),
            AssignmentStatus::needHelp()->progressWeightPercent(),
        );
    }

    public function test_work_sent_back_keeps_more_credit_than_work_merely_started(): void
    {
        self::assertGreaterThan(
            AssignmentStatus::inProgress()->progressWeightPercent(),
            AssignmentStatus::fixAndResubmit()->progressWeightPercent(),
        );
        self::assertLessThan(
            AssignmentStatus::submitted()->progressWeightPercent(),
            AssignmentStatus::fixAndResubmit()->progressWeightPercent(),
        );
    }
}
