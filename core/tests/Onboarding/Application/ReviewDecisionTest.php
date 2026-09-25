<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use PHPUnit\Framework\TestCase;
use TriCab\Core\Identity\Application\DTO\UserManagement\ActorContext;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewItemRecord;
use TriCab\Core\Onboarding\Application\DTO\Admin\ReviewQueueEntry;
use TriCab\Core\Onboarding\Application\Service\DecideReviewService;
use TriCab\Core\Onboarding\Application\Service\ListReviewQueueService;
use TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAssignmentNotFound;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingWriteConflict;
use TriCab\Core\Onboarding\Domain\Exception\ReviewDecisionNotPermitted;
use TriCab\Core\Onboarding\Domain\Exception\SelfReviewNotPermitted;
use TriCab\Core\Onboarding\Domain\ValueObject\AdminEventType;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingAdminAuditLog;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReviewRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Phase 4.3 — the reviewer queue and a reviewer's decision.
 *
 * What is held here: invariant 7 from the reviewer's side (only a review
 * completes submitted work, and only somebody else's), invariant 13 (every
 * decision and every refusal leaves a row, and no row carries the note), and
 * "concurrent administrative edits fail safely".
 */
final class ReviewDecisionTest extends TestCase
{
    private const REVIEWER = 40;
    private const TRAINEE = 7;
    private const FORM = 'aaaaaaaa-0000-4000-8000-000000000001';

    private InMemoryOnboardingReviewRepository $reviews;
    private InMemoryOnboardingAdminAuditLog $audit;
    private DecideReviewService $service;
    private RowVersionToken $token;

    protected function setUp(): void
    {
        $this->reviews = new InMemoryOnboardingReviewRepository();
        $this->audit = new InMemoryOnboardingAdminAuditLog();
        $this->service = new DecideReviewService($this->reviews, $this->audit);
        $this->token = RowVersionToken::fromBinary("\x00\x00\x00\x00\x00\x00\x0B\xB8");
        $this->withItem(self::FORM, self::TRAINEE, AssignmentStatus::submitted());
    }

    // --------------------------------------------------------------- the queue

    public function test_the_queue_holds_only_work_waiting_on_a_person_stuck_first_then_longest_waiting(): void
    {
        $this->withItem('aaaaaaaa-0000-4000-8000-000000000002', 8, AssignmentStatus::submitted(), '2026-09-01T00:00:00Z');
        $this->withItem('aaaaaaaa-0000-4000-8000-000000000003', 9, AssignmentStatus::needHelp(), '2026-09-22T00:00:00Z');
        $this->withItem('aaaaaaaa-0000-4000-8000-000000000004', 10, AssignmentStatus::completed());
        $this->withItem('aaaaaaaa-0000-4000-8000-000000000005', 11, AssignmentStatus::inProgress());

        $page = (new ListReviewQueueService($this->reviews))->list(1, 25);

        self::assertSame(3, $page['total']);
        self::assertSame(
            ['aaaaaaaa-0000-4000-8000-000000000003', 'aaaaaaaa-0000-4000-8000-000000000002', self::FORM],
            array_map(static fn (ReviewQueueEntry $e) => $e->assignmentPublicId, $page['items']),
        );
    }

    public function test_the_queue_page_size_is_bounded(): void
    {
        $page = (new ListReviewQueueService($this->reviews))->list(0, 10_000);

        self::assertSame(1, $page['page']);
        self::assertSame(ListReviewQueueService::MAX_PAGE_SIZE, $page['pageSize']);
    }

    // ------------------------------------------------------------ the decision

    public function test_accepting_completes_the_work_and_audits_it_without_the_note(): void
    {
        $detail = $this->service->decide($this->actor(), self::FORM, $this->token, AssignmentStatus::completed(), '  Looks right to me, Sam.  ');

        self::assertSame('completed', $detail->status()->toString());
        self::assertCount(1, $this->reviews->decisions);

        $transition = $this->reviews->decisions[0]['transition'];
        self::assertSame('reviewer', $transition->actor->toString());
        self::assertSame(self::REVIEWER, $transition->actorUserId);
        self::assertSame('Looks right to me, Sam.', $transition->note, 'the note goes with the status event, trimmed');

        $event = $this->reviews->decisions[0]['event'];
        self::assertSame(AdminEventType::REVIEW_DECIDED, $event->eventType);
        self::assertSame('succeeded', $event->outcome);
        self::assertSame(self::TRAINEE, $event->subjectUserId);
        self::assertSame(['itemType' => 'form', 'itemPublicId' => 'item-form', 'fromStatus' => 'submitted', 'toStatus' => 'completed', 'revisionNumber' => 2], $event->details);
        self::assertStringNotContainsString('Sam', $event->summary . $event->detailsJson());

        self::assertSame([], $this->audit->entries, 'a success is written with the change, not on its own');
    }

    public function test_sending_back_needs_a_reason_and_a_missing_one_changes_and_records_nothing(): void
    {
        try {
            $this->service->decide($this->actor(), self::FORM, $this->token, AssignmentStatus::fixAndResubmit(), '   ');
            self::fail('Sent back with no reason.');
        } catch (InvalidOnboardingValue) {
        }

        self::assertSame([], $this->reviews->decisions);
        self::assertSame([], $this->audit->entries, 'a malformed request is not a decision refused');

        $detail = $this->service->decide($this->actor(), self::FORM, $this->token, AssignmentStatus::fixAndResubmit(), 'The start date is before the offer date.');
        self::assertSame('fix_and_resubmit', $detail->status()->toString());
    }

    public function test_nobody_reviews_their_own_work_and_the_attempt_is_audited(): void
    {
        $own = 'aaaaaaaa-0000-4000-8000-0000000000ff';
        $this->withItem($own, self::REVIEWER, AssignmentStatus::submitted());

        try {
            $this->service->decide($this->actor(), $own, $this->token, AssignmentStatus::completed(), null);
            self::fail('Reviewed their own submission.');
        } catch (SelfReviewNotPermitted) {
        }

        self::assertSame([], $this->reviews->decisions);
        self::assertCount(1, $this->audit->entries);
        $event = $this->audit->entries[0]['event'];
        self::assertSame(AdminEventType::REVIEW_REFUSED, $event->eventType);
        self::assertSame('refused', $event->outcome);
        self::assertSame('self_review', $event->details['reason']);
    }

    public function test_self_review_is_refused_before_anything_else_is_checked(): void
    {
        // Even an item that is not queued answers "not yours to decide" first.
        $own = 'aaaaaaaa-0000-4000-8000-0000000000fe';
        $this->withItem($own, self::REVIEWER, AssignmentStatus::completed());

        $this->expectException(SelfReviewNotPermitted::class);
        $this->service->decide($this->actor(), $own, $this->token, AssignmentStatus::fixAndResubmit(), 'x');
    }

    public function test_an_item_that_has_left_the_queue_is_refused_and_audited(): void
    {
        $done = 'aaaaaaaa-0000-4000-8000-000000000010';
        $this->withItem($done, self::TRAINEE, AssignmentStatus::completed());

        try {
            $this->service->decide($this->actor(), $done, $this->token, AssignmentStatus::fixAndResubmit(), 'Reopen.');
            self::fail('Decided an item that was not queued.');
        } catch (ReviewDecisionNotPermitted $refusal) {
            self::assertSame(ReviewDecisionNotPermitted::REASON_NOT_AWAITING_REVIEW, $refusal->reason);
        }

        self::assertSame('not_awaiting_review', $this->audit->entries[0]['event']->details['reason']);
    }

    public function test_an_outcome_not_offered_is_refused_and_audited(): void
    {
        $this->expectException(ReviewDecisionNotPermitted::class);

        try {
            $this->service->decide($this->actor(), self::FORM, $this->token, AssignmentStatus::inProgress(), 'Carry on.');
        } finally {
            self::assertSame('outcome_not_offered', $this->audit->entries[0]['event']->details['reason'] ?? null);
            self::assertSame([], $this->reviews->decisions);
        }
    }

    public function test_a_stale_token_is_a_conflict_and_writes_nothing(): void
    {
        // The item moved between this reviewer's read and their write — the
        // trainee resubmitted, or another reviewer's decision was reopened.
        $stale = RowVersionToken::fromBinary("\x00\x00\x00\x00\x00\x00\x00\x01");

        try {
            $this->service->decide($this->actor(), self::FORM, $stale, AssignmentStatus::completed(), null);
            self::fail('A stale token was accepted.');
        } catch (OnboardingWriteConflict $conflict) {
            self::assertSame(OnboardingWriteConflict::STALE_TOKEN, $conflict->errorCode);
        }

        self::assertSame([], $this->reviews->decisions);
        self::assertSame('submitted', $this->reviews->findReviewItem(self::FORM)?->status->toString());
    }

    public function test_a_second_reviewer_arriving_after_the_first_is_told_the_item_has_left_the_queue(): void
    {
        $this->service->decide($this->actor(), self::FORM, $this->token, AssignmentStatus::completed(), null);

        // Same token the first reviewer used, as a second tab would still hold.
        // The item is re-read before the rules run, so the answer is the
        // explanatory one rather than a bare conflict.
        try {
            $this->service->decide(new ActorContext(41, 'other', 'other@example.test'), self::FORM, $this->token, AssignmentStatus::fixAndResubmit(), 'Second opinion.');
            self::fail('Decided twice.');
        } catch (ReviewDecisionNotPermitted $refusal) {
            self::assertSame(ReviewDecisionNotPermitted::REASON_NOT_AWAITING_REVIEW, $refusal->reason);
        }

        self::assertCount(1, $this->reviews->decisions);
    }

    public function test_an_unknown_item_is_not_found(): void
    {
        $this->expectException(OnboardingAssignmentNotFound::class);

        $this->service->decide($this->actor(), 'bbbbbbbb-0000-4000-8000-000000000000', $this->token, AssignmentStatus::completed(), null);
    }

    public function test_the_page_offers_no_decision_on_ones_own_work(): void
    {
        $own = 'aaaaaaaa-0000-4000-8000-0000000000fd';
        $this->withItem($own, self::REVIEWER, AssignmentStatus::submitted());

        $mine = $this->reviews->reviewDetail($own)?->toArray(self::REVIEWER);
        $theirs = $this->reviews->reviewDetail(self::FORM)?->toArray(self::REVIEWER);

        self::assertSame([], $mine['outcomes'] ?? null);
        self::assertTrue(is_array($mine['item'] ?? null) && $mine['item']['isOwn'] === true);
        self::assertSame(['completed', 'fix_and_resubmit'], array_column(is_array($theirs['outcomes'] ?? null) ? $theirs['outcomes'] : [], 'status'));
    }

    // --------------------------------------------------------------- helpers

    private function withItem(string $publicId, int $subject, AssignmentStatus $status, ?string $waitingSince = '2026-09-10T00:00:00Z'): void
    {
        $this->reviews->withItem(
            new ReviewItemRecord(1, $publicId, $subject, 'form', 'item-form', 'Starter details', $status, $this->token, 2),
            new ReviewQueueEntry($publicId, $subject, 'person-' . $subject, 'Person ' . $subject, 'form', 'item-form', 'Starter details', 1, $status, $waitingSince, null, 2),
        );
    }

    private function actor(): ActorContext
    {
        return new ActorContext(self::REVIEWER, 'reviewer-public-id', 'reviewer@example.test');
    }
}
