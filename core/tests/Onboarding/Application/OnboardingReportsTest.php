<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Application;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Application\DTO\Report\ItemComplianceRow;
use TriCab\Core\Onboarding\Application\DTO\Report\OverdueWorkRow;
use TriCab\Core\Onboarding\Application\Service\ReadOnboardingReportsService;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;
use TriCab\Core\Onboarding\Infrastructure\InMemoryOnboardingReportRepository;
use TriCab\Core\Tests\Identity\Doubles\FixedClock;

/**
 * Phase 4.5: the three reports over one small roster.
 *
 *  - Alex — current, invited. Module done, policy not started and due
 *    23 Sep, form submitted long ago (late on HR's side, not Alex's).
 *  - Priya — current, never invited, nothing allocated.
 *  - Jordan — left on 10 Sep, with unfinished work and a help request.
 *  - Sam — serving notice (leaves 31 Oct), so still current. Everything done.
 *  - Lee — current, no display name, stuck on the policy since it fell due.
 *
 * The clock is 00:30 on 24 Sep in Melbourne and still 23 Sep in UTC, so
 * whether Alex's policy is overdue is exactly the timezone question.
 */
final class OnboardingReportsTest extends TestCase
{
    private const ALEX = 'a1000000-0000-4000-8000-000000000001';
    private const LEE = 'a1000000-0000-4000-8000-000000000005';

    private InMemoryOnboardingReportRepository $repository;

    protected function setUp(): void
    {
        $r = new InMemoryOnboardingReportRepository();
        $r->withPerson(1, self::ALEX, 'Alex Ng');
        $r->withPerson(2, 'a1000000-0000-4000-8000-000000000002', 'Priya Shah', invited: false);
        $r->withPerson(3, 'a1000000-0000-4000-8000-000000000003', 'Jordan Lee', departedAtUtc: self::utc('2026-09-10T14:00:00'));
        $r->withPerson(4, 'a1000000-0000-4000-8000-000000000004', 'Sam Ortiz', departedAtUtc: self::utc('2026-10-31T13:00:00'));
        $r->withPerson(5, self::LEE, null);

        $r->withItem('training', 't1', 'Workplace safety');
        $r->withItem('training', 't2', 'Forklift basics');
        $r->withItem('training', 't3', 'Retired module', offered: false);
        $r->withItem('policy', 'p1', 'Code of conduct');
        $r->withItem('form', 'f1', 'Starter details');

        $r->withAssignment('as-01', 1, 'training', 't1', AssignmentStatus::completed());
        $r->withAssignment('as-02', 1, 'policy', 'p1', AssignmentStatus::notStarted(), '2026-09-23');
        $r->withAssignment('as-03', 1, 'form', 'f1', AssignmentStatus::submitted(), '2026-09-01');
        $r->withAssignment('as-04', 3, 'policy', 'p1', AssignmentStatus::inProgress(), '2026-09-01');
        $r->withAssignment('as-05', 3, 'form', 'f1', AssignmentStatus::needHelp());
        $r->withAssignment('as-06', 4, 'training', 't1', AssignmentStatus::completed());
        $r->withAssignment('as-07', 5, 'policy', 'p1', AssignmentStatus::needHelp(), '2026-09-22');

        $this->repository = $r;
    }

    public function test_the_summary_counts_current_people_and_everybodys_queue(): void
    {
        self::assertSame([
            'asOf' => '2026-09-24',
            'people' => ['current' => 4, 'departed' => 1, 'notYetInvited' => 1, 'nothingAllocated' => 1, 'finished' => 1],
            'work' => [
                'total' => 5,
                'byStatus' => ['not_started' => 1, 'in_progress' => 0, 'need_help' => 1, 'submitted' => 1, 'fix_and_resubmit' => 0, 'completed' => 2],
                'overdue' => 2,
            ],
            // Jordan's help request is still in the reviewer queue, so it is
            // counted here even though Jordan's work is not counted above.
            'queue' => ['waitingOnReview' => 1, 'needHelp' => 2],
        ], $this->service()->summary()->toArray());
    }

    public function test_item_compliance_lists_held_and_offered_items_and_leaves_leavers_out(): void
    {
        $result = $this->service()->itemCompliance(1, 25);

        self::assertSame(4, $result['total']);
        self::assertSame(
            [
                ['form', 'f1', 1, 0, 1, 0, 0, 0],
                ['policy', 'p1', 2, 0, 0, 1, 1, 2],
                ['training', 't2', 0, 0, 0, 0, 0, 0], // "Forklift basics" sorts before
                ['training', 't1', 2, 2, 0, 0, 0, 0], // "Workplace safety"
            ],
            array_map(static fn (ItemComplianceRow $r) => [$r->itemType, $r->itemPublicId, $r->assigned, $r->completed, $r->waitingOnReview, $r->inProgress, $r->notStarted, $r->overdue], $result['items']),
            'Jordan (left) is in no count; t3 is neither held nor offered, so it is not listed',
        );
    }

    public function test_the_overdue_list_is_longest_overdue_first_and_carries_no_contact_details(): void
    {
        $result = $this->service()->overdueWork(1, 25);

        self::assertSame(2, $result['total']);
        self::assertSame(
            [[self::LEE, null, 'p1', 'need_help', '2026-09-22', 2], [self::ALEX, 'Alex Ng', 'p1', 'not_started', '2026-09-23', 1]],
            array_map(static fn (OverdueWorkRow $r) => [$r->personPublicId, $r->displayName, $r->itemPublicId, $r->status->toString(), $r->dueDate, $r->daysOverdue], $result['items']),
        );
        self::assertSame(
            ['personPublicId', 'displayName', 'itemType', 'itemPublicId', 'title', 'status', 'statusLabel', 'dueDate', 'daysOverdue'],
            array_keys($result['items'][0]->toArray()),
        );
    }

    /** An hour and a half earlier it is still the 23rd in Melbourne, and due today is not overdue. */
    public function test_an_item_due_today_in_melbourne_is_not_yet_overdue(): void
    {
        $service = new ReadOnboardingReportsService($this->repository, new FixedClock(self::utc('2026-09-23T13:00:00')));

        self::assertSame([self::LEE], array_map(static fn (OverdueWorkRow $r) => $r->personPublicId, $service->overdueWork(1, 25)['items']));
        self::assertSame('2026-09-23', $service->summary()->asOf);
    }

    public function test_pages_are_bounded(): void
    {
        $service = $this->service();

        $first = $service->overdueWork(0, 1);
        self::assertSame([1, 1, 2], [$first['page'], $first['pageSize'], $first['total']]);
        self::assertSame(self::LEE, $first['items'][0]->personPublicId);
        self::assertSame(self::ALEX, $service->overdueWork(2, 1)['items'][0]->personPublicId);
        self::assertSame([], $service->overdueWork(3, 1)['items']);
        self::assertSame(ReadOnboardingReportsService::MAX_PAGE_SIZE, $service->itemCompliance(1, 10_000)['pageSize']);
    }

    private function service(): ReadOnboardingReportsService
    {
        return new ReadOnboardingReportsService($this->repository, new FixedClock(self::utc('2026-09-23T14:30:00')));
    }

    private static function utc(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }
}
