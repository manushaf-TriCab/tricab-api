<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Exception\AllocationNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\WithdrawalNotPermitted;
use TriCab\Core\Onboarding\Domain\Service\AllocationPolicy;
use TriCab\Core\Onboarding\Domain\Service\SupersessionPolicy;
use TriCab\Core\Onboarding\Domain\Service\WithdrawalPolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * Phase 2.3 — explicit allocation, evidence-preserving withdrawal, and what a
 * new version does to people already mid-way through the old one.
 */
final class AllocationAndWithdrawalTest extends TestCase
{
    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    private function now(): DateTimeImmutable
    {
        return $this->at('2026-09-21 09:00:00');
    }

    // ------------------------------------------------------------- allocation

    public function test_a_published_live_version_may_be_allocated(): void
    {
        $this->expectNotToPerformAssertions();

        AllocationPolicy::assertAllocatable(
            isPublished: true,
            isRetired: false,
            hasLiveAllocation: false,
            departedAtUtc: null,
            nowUtc: $this->now(),
        );
    }

    public function test_a_draft_version_cannot_be_allocated(): void
    {
        try {
            AllocationPolicy::assertVersionAllocatable(isPublished: false, isRetired: false);
            self::fail('A draft should not be allocatable.');
        } catch (AllocationNotPermitted $e) {
            self::assertSame(AllocationNotPermitted::REASON_NOT_PUBLISHED, $e->reason);
        }
    }

    /**
     * Draft and retired are separate refusals because the remedies differ —
     * publish it, versus allocate the current one. An HR user told only
     * "cannot allocate" has to guess which.
     */
    public function test_a_retired_version_refuses_differently_from_a_draft(): void
    {
        try {
            AllocationPolicy::assertVersionAllocatable(isPublished: true, isRetired: true);
            self::fail('A retired version should not be allocatable.');
        } catch (AllocationNotPermitted $e) {
            self::assertSame(AllocationNotPermitted::REASON_RETIRED, $e->reason);
            self::assertStringContainsString('current one', $e->getMessage());
        }
    }

    public function test_the_same_item_cannot_be_allocated_twice_while_live(): void
    {
        try {
            AllocationPolicy::assertNotAlreadyAllocated(hasLiveAllocation: true);
            self::fail('A duplicate live allocation should be refused.');
        } catch (AllocationNotPermitted $e) {
            self::assertSame(AllocationNotPermitted::REASON_ALREADY_ALLOCATED, $e->reason);
        }
    }

    /**
     * Re-allocating after a withdrawal is ordinary. The evidence of the first
     * time stays where it is, which is why the schema's uniqueness is filtered
     * on `WithdrawnAtUtc IS NULL` rather than absolute.
     */
    public function test_an_item_may_be_allocated_again_after_being_withdrawn(): void
    {
        $this->expectNotToPerformAssertions();

        AllocationPolicy::assertNotAlreadyAllocated(hasLiveAllocation: false);
    }

    public function test_a_leaver_cannot_be_given_new_work(): void
    {
        try {
            AllocationPolicy::assertPersonAllocatable(
                $this->at('2026-09-01 17:00:00'),
                $this->now(),
            );
            self::fail('A departed person should not be allocatable.');
        } catch (AllocationNotPermitted $e) {
            self::assertSame(AllocationNotPermitted::REASON_DEPARTED, $e->reason);
        }
    }

    /**
     * Serving out notice is not departure. Somebody working their last month is
     * a current employee who may still be finishing their induction, and
     * refusing to allocate to them would be refusing to onboard somebody who is
     * still here.
     */
    public function test_somebody_serving_out_notice_may_still_be_allocated_work(): void
    {
        $this->expectNotToPerformAssertions();

        AllocationPolicy::assertPersonAllocatable(
            $this->at('2026-12-24 17:00:00'),
            $this->now(),
        );
    }

    /**
     * Version faults are reported before person faults. "That version is a
     * draft" is true whoever it was aimed at, so it saves HR fixing the wrong
     * end of the problem.
     */
    public function test_a_draft_is_reported_before_a_departure(): void
    {
        try {
            AllocationPolicy::assertAllocatable(
                isPublished: false,
                isRetired: false,
                hasLiveAllocation: true,
                departedAtUtc: $this->at('2026-09-01 17:00:00'),
                nowUtc: $this->now(),
            );
            self::fail('Expected a refusal.');
        } catch (AllocationNotPermitted $e) {
            self::assertSame(AllocationNotPermitted::REASON_NOT_PUBLISHED, $e->reason);
        }
    }

    public function test_explicit_is_the_only_allocation_source(): void
    {
        self::assertSame('explicit', AllocationPolicy::SOURCE_EXPLICIT);
    }

    // ------------------------------------------------------------ withdrawal

    public function test_a_live_allocation_may_be_withdrawn(): void
    {
        $this->expectNotToPerformAssertions();

        WithdrawalPolicy::assertWithdrawable(isAlreadyWithdrawn: false);
    }

    public function test_withdrawing_twice_is_refused(): void
    {
        $this->expectException(WithdrawalNotPermitted::class);

        WithdrawalPolicy::assertWithdrawable(isAlreadyWithdrawn: true);
    }

    /**
     * Owner decision, 21 Sep 2026. Withdrawal means "not required from here on",
     * never "this did not happen", so Skill Matrix does not forget a
     * qualification because an allocation was tidied up.
     */
    public function test_withdrawing_completed_work_leaves_the_completion_standing(): void
    {
        $completed = AssignmentStatus::completed();

        self::assertTrue(WithdrawalPolicy::completionSurvives($completed));
        self::assertFalse(WithdrawalPolicy::removesFromCompletionFeed($completed));
    }

    /**
     * Handed in is not finished. Recording a completion for work nobody reviewed
     * would be inventing evidence.
     */
    public function test_withdrawing_unfinished_work_records_no_completion(): void
    {
        foreach ([
            AssignmentStatus::notStarted(),
            AssignmentStatus::inProgress(),
            AssignmentStatus::needHelp(),
            AssignmentStatus::submitted(),
            AssignmentStatus::fixAndResubmit(),
        ] as $status) {
            self::assertFalse(
                WithdrawalPolicy::completionSurvives($status),
                $status->label() . ' is not a completion.',
            );
            self::assertTrue(WithdrawalPolicy::removesFromCompletionFeed($status));
        }
    }

    public function test_withdrawal_never_touches_evidence(): void
    {
        self::assertTrue(WithdrawalPolicy::preservesEvidence());
    }

    // ----------------------------------------------------------- supersession

    /**
     * The default. A typo fix must not reset three people to zero, or HR learns
     * to avoid publishing.
     */
    public function test_a_minor_revision_disturbs_nobody(): void
    {
        self::assertTrue(SupersessionPolicy::isMinorRevision(false));

        foreach (AssignmentStatus::all() as $status) {
            self::assertFalse(
                SupersessionPolicy::shouldReissue(false, $status),
                $status->label() . ' should be left alone by a minor revision.',
            );
        }
    }

    public function test_a_superseding_version_reissues_work_in_progress(): void
    {
        foreach ([
            AssignmentStatus::notStarted(),
            AssignmentStatus::inProgress(),
            AssignmentStatus::needHelp(),
            AssignmentStatus::submitted(),
            AssignmentStatus::fixAndResubmit(),
        ] as $status) {
            self::assertTrue(
                SupersessionPolicy::shouldReissue(true, $status),
                $status->label() . ' is work in progress and should move to the new version.',
            );
        }
    }

    /**
     * The property that stops publishing rewriting history. Somebody who
     * finished the old version finished it, on a date, against wording preserved
     * on the version row they were pointed at.
     */
    public function test_completed_work_is_never_reissued(): void
    {
        self::assertFalse(SupersessionPolicy::shouldReissue(true, AssignmentStatus::completed()));
    }

    public function test_a_withdrawn_allocation_is_nobodys_work_in_progress(): void
    {
        self::assertFalse(
            SupersessionPolicy::shouldReissue(true, AssignmentStatus::inProgress(), isWithdrawn: true),
        );
    }

    /**
     * Carrying `submitted` across a reissue would claim somebody handed in
     * something they have not read.
     */
    public function test_a_reissued_assignment_starts_again(): void
    {
        self::assertTrue(
            SupersessionPolicy::statusAfterReissue()->equals(AssignmentStatus::notStarted()),
        );
    }
}
