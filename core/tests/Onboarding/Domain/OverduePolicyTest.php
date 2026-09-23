<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Service\OverduePolicy;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

final class OverduePolicyTest extends TestCase
{
    /**
     * The source used the UTC date, so its "today" rolled over at 10 or 11 am
     * in Melbourne. 23 September 2026 is AEST, UTC+10.
     */
    public function test_today_is_the_melbourne_date(): void
    {
        self::assertSame('2026-09-23', OverduePolicy::todayFor(self::utc('2026-09-23T13:59:59')));
        self::assertSame('2026-09-24', OverduePolicy::todayFor(self::utc('2026-09-23T14:00:00')));
        // After daylight saving starts (4 Oct 2026) the rollover is 13:00 UTC.
        self::assertSame('2026-10-06', OverduePolicy::todayFor(self::utc('2026-10-05T13:00:00')));
    }

    public function test_due_today_is_not_overdue_and_due_yesterday_is(): void
    {
        $status = AssignmentStatus::notStarted();

        self::assertFalse(OverduePolicy::isOverdue($status, '2026-09-23', '2026-09-23'));
        self::assertTrue(OverduePolicy::isOverdue($status, '2026-09-22', '2026-09-23'));
        self::assertFalse(OverduePolicy::isOverdue($status, null, '2026-09-23'));
    }

    /**
     * Only work on the person's own plate. A submission is waiting on a
     * reviewer, and complete is finished.
     */
    public function test_only_work_still_with_the_person_can_be_overdue(): void
    {
        $overdue = [];
        foreach (AssignmentStatus::all() as $status) {
            $overdue[$status->toString()] = OverduePolicy::isOverdue($status, '2026-09-01', '2026-09-23');
        }

        self::assertSame([
            'not_started' => true,
            'in_progress' => true,
            'need_help' => true,
            'submitted' => false,
            'fix_and_resubmit' => true,
            'completed' => false,
        ], $overdue);
    }

    public function test_days_overdue_counts_calendar_days_across_a_clock_change(): void
    {
        self::assertSame(3, OverduePolicy::daysOverdue('2026-09-20', '2026-09-23'));
        self::assertSame(2, OverduePolicy::daysOverdue('2026-10-03', '2026-10-05'));
        self::assertSame(0, OverduePolicy::daysOverdue('2026-09-23', '2026-09-23'));
        self::assertSame(0, OverduePolicy::daysOverdue('2026-09-30', '2026-09-23'));
    }

    private static function utc(string $at): DateTimeImmutable
    {
        return new DateTimeImmutable($at, new DateTimeZone('UTC'));
    }
}
