<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\ReviewDecisionNotPermitted;
use TriCab\Core\Onboarding\Domain\Service\AssignmentStatusPolicy;
use TriCab\Core\Onboarding\Domain\Service\ReviewDecisionPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Domain\ValueObject\StatusActor;

/**
 * Work order §9: "every permitted and forbidden status transition is covered",
 * for the review endpoint. All 36 from × to pairs, each one asserted.
 */
final class ReviewDecisionPolicyTest extends TestCase
{
    /** The whole permitted set. Anything not listed must be refused. */
    private const PERMITTED = [
        'submitted>completed',
        'submitted>fix_and_resubmit',
        'need_help>in_progress',
        'need_help>fix_and_resubmit',
    ];

    public function test_exactly_four_decisions_are_permitted_and_every_other_pair_is_refused(): void
    {
        foreach (AssignmentStatus::all() as $from) {
            foreach (AssignmentStatus::all() as $to) {
                $pair = $from->toString() . '>' . $to->toString();

                if (in_array($pair, self::PERMITTED, true)) {
                    ReviewDecisionPolicy::assertAllowed($from, $to, 'A reason.');
                    self::assertTrue(true);
                    continue;
                }

                try {
                    ReviewDecisionPolicy::assertAllowed($from, $to, 'A reason.');
                    self::fail($pair . ' was permitted.');
                } catch (ReviewDecisionNotPermitted $refusal) {
                    self::assertSame(
                        $from->needsReview()
                            ? ReviewDecisionNotPermitted::REASON_OUTCOME_NOT_OFFERED
                            : ReviewDecisionNotPermitted::REASON_NOT_AWAITING_REVIEW,
                        $refusal->reason,
                        $pair,
                    );
                }
            }
        }
    }

    public function test_every_permitted_decision_is_also_one_a_reviewer_may_make(): void
    {
        // The endpoint's rule must sit inside the invariant, never beside it.
        foreach (self::PERMITTED as $pair) {
            [$from, $to] = explode('>', $pair);
            self::assertTrue(
                AssignmentStatusPolicy::canSet(StatusActor::reviewer(), AssignmentStatus::fromString($from), AssignmentStatus::fromString($to)),
                $pair,
            );
        }
    }

    public function test_nothing_is_offered_for_an_item_that_is_not_queued(): void
    {
        foreach (AssignmentStatus::all() as $from) {
            $offered = array_map(static fn (AssignmentStatus $s) => $s->toString(), ReviewDecisionPolicy::outcomesFrom($from));

            match ($from->toString()) {
                AssignmentStatus::SUBMITTED => self::assertSame(['completed', 'fix_and_resubmit'], $offered),
                AssignmentStatus::NEED_HELP => self::assertSame(['in_progress', 'fix_and_resubmit'], $offered),
                default => self::assertSame([], $offered, $from->toString()),
            };
        }
    }

    public function test_a_reviewer_never_gets_to_mark_somebody_as_needing_help(): void
    {
        foreach (AssignmentStatus::all() as $from) {
            $offered = array_map(static fn (AssignmentStatus $s) => $s->toString(), ReviewDecisionPolicy::outcomesFrom($from));
            self::assertNotContains('need_help', $offered);
            self::assertNotContains('not_started', $offered);
        }
    }

    public function test_sending_back_and_replying_need_a_note_and_accepting_does_not(): void
    {
        ReviewDecisionPolicy::assertAllowed(AssignmentStatus::submitted(), AssignmentStatus::completed(), null);
        self::assertFalse(ReviewDecisionPolicy::requiresNote(AssignmentStatus::completed()));

        foreach ([
            [AssignmentStatus::submitted(), AssignmentStatus::fixAndResubmit()],
            [AssignmentStatus::needHelp(), AssignmentStatus::inProgress()],
            [AssignmentStatus::needHelp(), AssignmentStatus::fixAndResubmit()],
        ] as [$from, $to]) {
            try {
                ReviewDecisionPolicy::assertAllowed($from, $to, null);
                self::fail($from->toString() . '>' . $to->toString() . ' accepted no note.');
            } catch (InvalidOnboardingValue $missing) {
                self::assertStringContainsString('note', $missing->getMessage());
            }
        }
    }

    public function test_not_queued_is_reported_before_a_missing_note(): void
    {
        // A reload fixes the first; typing a note would not.
        $this->expectException(ReviewDecisionNotPermitted::class);

        ReviewDecisionPolicy::assertAllowed(AssignmentStatus::completed(), AssignmentStatus::fixAndResubmit(), null);
    }

    public function test_a_note_is_trimmed_blank_is_none_and_a_long_one_is_refused(): void
    {
        self::assertNull(ReviewDecisionPolicy::normalizeNote(null));
        self::assertNull(ReviewDecisionPolicy::normalizeNote("  \n\t "));
        self::assertSame('Fix the start date.', ReviewDecisionPolicy::normalizeNote('  Fix the start date. '));
        self::assertSame(
            ReviewDecisionPolicy::NOTE_MAX_LENGTH,
            mb_strlen((string) ReviewDecisionPolicy::normalizeNote(str_repeat('é', ReviewDecisionPolicy::NOTE_MAX_LENGTH))),
        );

        $this->expectException(InvalidOnboardingValue::class);
        ReviewDecisionPolicy::normalizeNote(str_repeat('a', ReviewDecisionPolicy::NOTE_MAX_LENGTH + 1));
    }
}
