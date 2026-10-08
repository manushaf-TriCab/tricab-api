<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Jobs;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Finance\Shared\Jobs\FinanceJobSchedule;

/**
 * When a slot is due, in the time zone the job states: Melbourne by default, and
 * the apps disagree today (Tri-AP scans on Sydney time and marks overdue on Melbourne time).
 */
final class FinanceJobScheduleTest extends TestCase
{
    private static function utc(string $time): DateTimeImmutable
    {
        return new DateTimeImmutable($time, new DateTimeZone('UTC'));
    }

    public function test_a_daily_job_is_not_due_before_its_time_in_its_zone_and_is_after(): void
    {
        $schedule = FinanceJobSchedule::dailyAt('06:00');

        // 7 Oct 2026, Melbourne is on daylight time (UTC+11): 06:00 there is 19:00 UTC on the 6th.
        self::assertNull($schedule->dueSlot(self::utc('2026-10-06 18:59:59')));
        self::assertSame('D2026-10-07@06:00@Australia/Melbourne', $schedule->dueSlot(self::utc('2026-10-06 19:00:00')));
        self::assertSame('D2026-10-07@06:00@Australia/Melbourne', $schedule->dueSlot(self::utc('2026-10-07 12:00:00')), 'a runner back at 11 pm still runs this morning\'s job once');
    }

    public function test_the_slot_changes_at_midnight_in_the_jobs_zone_not_in_utc(): void
    {
        $schedule = FinanceJobSchedule::dailyAt('00:30');

        // 13:00 UTC on the 6th is midnight on the 7th in Melbourne; 13:30 UTC is 00:30.
        self::assertNull($schedule->dueSlot(self::utc('2026-10-06 13:00:00')));
        self::assertSame('D2026-10-07@00:30@Australia/Melbourne', $schedule->dueSlot(self::utc('2026-10-06 13:30:00')));
    }

    public function test_a_job_can_state_another_zone(): void
    {
        $sydney = FinanceJobSchedule::dailyAt('06:00', 'Australia/Sydney');
        $perth = FinanceJobSchedule::dailyAt('06:00', 'Australia/Perth');
        $utc = FinanceJobSchedule::dailyAt('06:00', 'UTC');
        // 21:00 UTC on the 6th: 08:00 on the 7th in Sydney (UTC+11), 05:00 on the 7th in Perth (UTC+8).
        $at = self::utc('2026-10-06 21:00:00');

        self::assertSame('D2026-10-07@06:00@Australia/Sydney', $sydney->dueSlot($at));
        self::assertNull($perth->dueSlot($at), 'it is 05:00 in Perth');
        self::assertSame('D2026-10-06@06:00@UTC', $utc->dueSlot($at), 'it is 21:00 on the 6th in UTC, after that day\'s 06:00');
    }

    public function test_the_same_instant_is_a_different_slot_in_a_different_zone(): void
    {
        $at = self::utc('2026-10-06 19:30:00');

        self::assertSame('D2026-10-07@06:00@Australia/Melbourne', FinanceJobSchedule::dailyAt('06:00')->dueSlot($at));
        self::assertSame('D2026-10-06@06:00@UTC', FinanceJobSchedule::dailyAt('06:00', 'UTC')->dueSlot($at));
    }

    public function test_the_clocks_going_forward_does_not_skip_a_day(): void
    {
        // Melbourne's daylight time began at 02:00 on Sunday 4 Oct 2026: 02:00 became 03:00 (16:00 UTC on the 3rd).
        $sixAm = FinanceJobSchedule::dailyAt('06:00');
        self::assertNull($sixAm->dueSlot(self::utc('2026-10-03 18:59:00')), '05:59 daylight time on the 4th');
        self::assertSame('D2026-10-04@06:00@Australia/Melbourne', $sixAm->dueSlot(self::utc('2026-10-03 19:00:00')));
        self::assertSame('D2026-10-05@06:00@Australia/Melbourne', $sixAm->dueSlot(self::utc('2026-10-04 19:00:00')));

        // A job at 02:30, a time that does not exist that day, still has its slot, once the clock is past where it falls.
        $halfPastTwo = FinanceJobSchedule::dailyAt('02:30');
        self::assertNull($halfPastTwo->dueSlot(self::utc('2026-10-03 15:59:00')), '01:59 standard time');
        self::assertSame('D2026-10-04@02:30@Australia/Melbourne', $halfPastTwo->dueSlot(self::utc('2026-10-03 16:31:00')));
    }

    public function test_the_clocks_going_back_does_not_give_a_day_two_slots(): void
    {
        // Daylight time ended at 03:00 on Sunday 5 Apr 2026: 02:00 to 03:00 happened twice. A job at a time inside
        // that hour has one slot for the day: PHP reads the ambiguous time as its second occurrence (standard
        // time), so the job becomes due on the second pass through the hour and is never due twice.
        $halfPastTwo = FinanceJobSchedule::dailyAt('02:30');

        self::assertNull($halfPastTwo->dueSlot(self::utc('2026-04-04 15:45:00')), '02:45 daylight time, the first time round');
        $second = $halfPastTwo->dueSlot(self::utc('2026-04-04 16:45:00')); // 02:45 standard time, the second
        self::assertSame('D2026-04-05@02:30@Australia/Melbourne', $second);
        self::assertSame($second, $halfPastTwo->dueSlot(self::utc('2026-04-05 03:00:00')), 'and the same slot for the rest of the day');
    }

    public function test_an_interval_job_has_one_slot_per_boundary_the_same_for_everyone(): void
    {
        $schedule = FinanceJobSchedule::everyMinutes(15);

        $a = $schedule->dueSlot(self::utc('2026-10-07 01:00:00'));
        self::assertSame($a, $schedule->dueSlot(self::utc('2026-10-07 01:14:59')));
        self::assertNotSame($a, $schedule->dueSlot(self::utc('2026-10-07 01:15:00')));
        self::assertStringStartsWith('I15:', (string) $a);
    }

    public function test_a_malformed_schedule_is_refused(): void
    {
        foreach (['6:00', '24:00', '06:60', 'six', ''] as $time) {
            try {
                FinanceJobSchedule::dailyAt($time);
                self::fail('Accepted ' . $time);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        foreach ([0, -1, 1441] as $minutes) {
            try {
                FinanceJobSchedule::everyMinutes($minutes);
                self::fail('Accepted ' . $minutes);
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
