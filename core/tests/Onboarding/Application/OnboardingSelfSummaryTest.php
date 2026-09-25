<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Application\DTO\OnboardingAssignmentView;
use TriCab\Core\Onboarding\Application\DTO\OnboardingTraineeRecord;
use TriCab\Core\Onboarding\Application\Service\BuildOnboardingSelfSummaryService;
use TriCab\Core\Onboarding\Domain\Exception\OnboardingAccessWindowClosed;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingSelfServiceRepository;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/**
 * Phase 3.1/3.2 — one person's own onboarding, and only ever their own.
 *
 * The service takes a user id and nothing else, so the isolation tests here are
 * not "does it check ownership" but "is there any argument that widens it".
 * There is not, and the shape of the API is what guarantees it rather than a
 * branch somebody could invert.
 */
final class OnboardingSelfSummaryTest extends TestCase
{
    private const TODAY = '2026-09-22 09:00:00';

    private const CANDIDATE = 501;
    private const SOMEBODY_ELSE = 502;

    private InMemoryOnboardingSelfServiceRepository $repository;

    protected function setUp(): void
    {
        $this->repository = new InMemoryOnboardingSelfServiceRepository();
    }

    private function service(string $now = self::TODAY): BuildOnboardingSelfSummaryService
    {
        return new BuildOnboardingSelfSummaryService($this->repository, FixedClock::utc($now));
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function record(?string $departedAtUtc = null): OnboardingTraineeRecord
    {
        return new OnboardingTraineeRecord(
            '11111111-1111-4111-8111-111111111111',
            '2026-10-01',
            $departedAtUtc === null ? null : $this->utc($departedAtUtc),
        );
    }

    private function assignment(
        string $publicId,
        string $status,
        ?string $dueDate = null,
        string $itemType = 'form',
        string $allocatedAtUtc = '2026-09-01T00:00:00+00:00',
    ): OnboardingAssignmentView {
        return OnboardingAssignmentView::fromStorage(
            $publicId,
            $itemType,
            'aaaaaaaa-0000-4000-8000-000000000001',
            'Synthetic item',
            1,
            $status,
            $dueDate,
            $allocatedAtUtc,
            null,
            null,
        );
    }

    // --------------------------------------------------------- no record

    /**
     * Most of the platform has no induction. That is an empty summary, not a
     * 404: "you have no onboarding" answers the question, and a 404 would have
     * the SPA render an error page to every ordinary employee who followed the
     * link.
     */
    public function test_somebody_with_no_induction_gets_an_empty_summary(): void
    {
        $summary = $this->service()->forUser(self::CANDIDATE);

        self::assertFalse($summary->hasRecord);
        self::assertSame([], $summary->assignments);
        self::assertSame(0, $summary->progressPercent);
        self::assertTrue($summary->maySubmit);
    }

    public function test_a_record_with_nothing_allocated_is_not_the_same_as_no_record(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record());

        $summary = $this->service()->forUser(self::CANDIDATE);

        self::assertTrue($summary->hasRecord);
        self::assertSame([], $summary->assignments);
    }

    // --------------------------------------------------------- isolation

    /**
     * The isolation test that matters: the repository holds two people's work
     * and the service is asked for one. Nothing filters afterwards, because
     * nothing unfiltered is ever fetched.
     */
    public function test_one_person_never_sees_another_person_s_work(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record());
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('mine-1', AssignmentStatus::IN_PROGRESS),
        ]);
        $this->repository->withRecord(self::SOMEBODY_ELSE, $this->record());
        $this->repository->withAssignments(self::SOMEBODY_ELSE, [
            $this->assignment('theirs-1', AssignmentStatus::COMPLETED),
            $this->assignment('theirs-2', AssignmentStatus::SUBMITTED),
        ]);

        $summary = $this->service()->forUser(self::CANDIDATE);

        self::assertCount(1, $summary->assignments);
        self::assertSame('mine-1', $summary->assignments[0]->publicId);
    }

    /** And the summary names nobody, so there is no subject to tamper with. */
    public function test_the_summary_carries_no_subject(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record());

        $keys = array_keys($this->service()->forUser(self::CANDIDATE)->toArray());

        foreach (['userId', 'publicId', 'emailAddress', 'displayName', 'traineeId'] as $forbidden) {
            self::assertNotContains($forbidden, $keys);
        }
    }

    // ------------------------------------------------------------- order

    /**
     * Actionable work first, then what is waiting on somebody else, then what
     * is done — and inside each band, the soonest deadline first.
     */
    public function test_actionable_work_comes_before_waiting_and_finished_work(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record());
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('done', AssignmentStatus::COMPLETED),
            $this->assignment('waiting', AssignmentStatus::SUBMITTED),
            $this->assignment('todo', AssignmentStatus::NOT_STARTED),
        ]);

        $order = array_map(
            static fn (OnboardingAssignmentView $view): string => $view->publicId,
            $this->service()->forUser(self::CANDIDATE)->assignments,
        );

        self::assertSame(['todo', 'waiting', 'done'], $order);
    }

    public function test_the_soonest_deadline_comes_first_within_a_band(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record());
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('later', AssignmentStatus::NOT_STARTED, '2026-12-01'),
            $this->assignment('sooner', AssignmentStatus::IN_PROGRESS, '2026-09-30'),
        ]);

        self::assertSame('sooner', $this->service()->forUser(self::CANDIDATE)->assignments[0]->publicId);
    }

    /**
     * Undated work sorts LAST, not first. A naive string comparison puts null
     * ahead of every date and buries the work that actually has a deadline.
     */
    public function test_undated_work_sorts_after_dated_work(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record());
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('undated', AssignmentStatus::NOT_STARTED, null),
            $this->assignment('dated', AssignmentStatus::NOT_STARTED, '2027-01-01'),
        ]);

        $order = array_map(
            static fn (OnboardingAssignmentView $view): string => $view->publicId,
            $this->service()->forUser(self::CANDIDATE)->assignments,
        );

        self::assertSame(['dated', 'undated'], $order);
    }

    /**
     * Work order §8 asks every list for deterministic ordering. Two items
     * allocated in the same batch share a timestamp and a due date, so the
     * public id is what makes the order total rather than merely usually
     * stable.
     */
    public function test_the_order_is_total_when_everything_else_ties(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record());
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('bbb', AssignmentStatus::NOT_STARTED, '2026-10-01'),
            $this->assignment('aaa', AssignmentStatus::NOT_STARTED, '2026-10-01'),
        ]);

        $order = array_map(
            static fn (OnboardingAssignmentView $view): string => $view->publicId,
            $this->service()->forUser(self::CANDIDATE)->assignments,
        );

        self::assertSame(['aaa', 'bbb'], $order);
    }

    /**
     * The trainee's order is NOT the reviewer's. `need_help` is first in the
     * reviewer queue because somebody stuck is most urgent to a reviewer; to
     * the person themselves it is one more actionable item, and an item due
     * tomorrow outranks it.
     */
    public function test_the_trainee_order_is_not_the_reviewer_queue_order(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record());
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('stuck', AssignmentStatus::NEED_HELP, '2026-12-01'),
            $this->assignment('due-tomorrow', AssignmentStatus::NOT_STARTED, '2026-09-23'),
        ]);

        self::assertSame(
            'due-tomorrow',
            $this->service()->forUser(self::CANDIDATE)->assignments[0]->publicId,
        );
    }

    // ---------------------------------------------------------- progress

    public function test_progress_gives_partial_credit(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record());
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('a', AssignmentStatus::COMPLETED),
            $this->assignment('b', AssignmentStatus::SUBMITTED),
        ]);

        // 100 and 75, averaged.
        self::assertSame(88, $this->service()->forUser(self::CANDIDATE)->progressPercent);
    }

    public function test_actionable_count_excludes_submitted_and_completed_work(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record());
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('a', AssignmentStatus::NOT_STARTED),
            $this->assignment('b', AssignmentStatus::FIX_AND_RESUBMIT),
            $this->assignment('c', AssignmentStatus::SUBMITTED),
            $this->assignment('d', AssignmentStatus::COMPLETED),
        ]);

        self::assertSame(2, $this->service()->forUser(self::CANDIDATE)->actionableCount);
    }

    // ------------------------------------------------------------ leaver

    /** Somebody serving out notice has not left. A future last day changes nothing. */
    public function test_a_future_last_day_is_not_a_departure(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record('2026-10-31 09:00:00'));
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('a', AssignmentStatus::IN_PROGRESS),
        ]);

        $summary = $this->service()->forUser(self::CANDIDATE);

        self::assertTrue($summary->maySubmit);
        self::assertCount(1, $summary->assignments);
    }

    /** Inside the window: they read what they did, and add nothing to it. */
    public function test_a_leaver_inside_the_window_reads_but_cannot_submit(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record('2026-09-20 09:00:00'));
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('a', AssignmentStatus::SUBMITTED),
        ]);

        $summary = $this->service()->forUser(self::CANDIDATE);

        self::assertFalse($summary->maySubmit);
        self::assertCount(1, $summary->assignments);
        self::assertSame('2026-10-04T09:00:00+00:00', $summary->accessEndsAtUtc);
    }

    /**
     * Past the window: a refusal, not an empty list. An empty induction reads
     * as "you have nothing to do", and somebody chasing a signed policy would
     * conclude the records were gone. They are not — retention is a separate
     * figure that has not been set, so nothing has been deleted.
     */
    public function test_a_leaver_past_the_window_is_refused_rather_than_shown_nothing(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record('2026-09-01 09:00:00'));
        $this->repository->withAssignments(self::CANDIDATE, [
            $this->assignment('a', AssignmentStatus::COMPLETED),
        ]);

        $this->expectException(OnboardingAccessWindowClosed::class);

        $this->service()->forUser(self::CANDIDATE);
    }

    public function test_the_refusal_says_the_records_are_retained(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record('2026-09-01 09:00:00'));

        try {
            $this->service()->forUser(self::CANDIDATE);
            self::fail('A closed window should have been refused.');
        } catch (OnboardingAccessWindowClosed $closed) {
            self::assertStringContainsString('retained', $closed->getMessage());
            self::assertSame('2026-09-15', $closed->accessEndedAtUtc->format('Y-m-d'));
        }
    }

    /** The boundary itself: the window ends at the instant, not at the end of that day. */
    public function test_the_window_closes_on_the_instant(): void
    {
        $this->repository->withRecord(self::CANDIDATE, $this->record('2026-09-08 09:00:00'));

        // 2026-09-22 09:00:00 is exactly fourteen days later.
        $this->expectException(OnboardingAccessWindowClosed::class);

        $this->service()->forUser(self::CANDIDATE);
    }

    // -------------------------------------------------------- item types

    public function test_an_unknown_item_type_is_refused_rather_than_guessed(): void
    {
        $this->expectException(\TriCab\Core\Onboarding\Domain\Exception\InvalidOnboardingValue::class);

        $this->assignment('a', AssignmentStatus::NOT_STARTED, null, 'quiz');
    }

    /**
     * A status is forgiving where the item type is not, and the asymmetry is
     * deliberate: a hand-edited status must not lock somebody out of their
     * induction, while there is no safe default for "what kind of thing is
     * this" — guessing would render a policy as a training module.
     */
    public function test_an_unreadable_status_falls_back_rather_than_throwing(): void
    {
        $view = $this->assignment('a', 'definitely_done');

        self::assertSame(AssignmentStatus::NOT_STARTED, $view->status->toString());
    }
}
