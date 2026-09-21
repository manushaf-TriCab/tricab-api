<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Onboarding\Domain;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TriCab\Core\Onboarding\Domain\Service\LeaverPolicy;

/**
 * The leaver policy: fourteen days of read access after departure.
 *
 * Owner decision, 21 September 2026, answering open question 3.
 *
 * Two things are being held in place here, and the second is the one that will
 * be got wrong if it is not tested. The first is the window itself. The second
 * is that the window is READ-ONLY and ends submitting immediately: evidence
 * dated after somebody's last day is worse than no evidence, because it looks
 * like evidence.
 *
 * Nothing here is about how long records are KEPT. That is a separate clock
 * measured in years, it is still owed by the owner, and until it is stated the
 * retention job deletes nothing.
 */
final class LeaverPolicyTest extends TestCase
{
    private const DEPARTED = '2026-09-01 09:00:00';

    private function at(string $utc): DateTimeImmutable
    {
        return new DateTimeImmutable($utc, new \DateTimeZone('UTC'));
    }

    public function test_the_window_is_fourteen_days(): void
    {
        self::assertSame(14, LeaverPolicy::ACCESS_WINDOW_DAYS);
    }

    public function test_the_window_ends_fourteen_days_after_departure(): void
    {
        self::assertSame(
            '2026-09-15 09:00:00',
            LeaverPolicy::accessEndsAt($this->at(self::DEPARTED))->format('Y-m-d H:i:s'),
        );
    }

    // -----------------------------------------------------------------
    // Somebody who has not left
    // -----------------------------------------------------------------

    public function test_a_current_employee_has_not_departed(): void
    {
        self::assertFalse(LeaverPolicy::hasDeparted(null, $this->at('2030-01-01 00:00:00')));
        self::assertTrue(LeaverPolicy::mayReadOwnRecords(null, $this->at('2030-01-01 00:00:00')));
        self::assertTrue(LeaverPolicy::maySubmit(null, $this->at('2030-01-01 00:00:00')));
    }

    /**
     * HR records a last day as soon as it is known, often weeks ahead. Somebody
     * serving out notice is a current employee who may still be finishing their
     * induction, and reading a future date as "gone" would lock them out of work
     * they are still expected to do.
     */
    public function test_a_future_last_day_means_serving_out_notice_not_gone(): void
    {
        $lastDay = $this->at('2026-10-31 17:00:00');
        $today = $this->at(self::DEPARTED);

        self::assertFalse(LeaverPolicy::hasDeparted($lastDay, $today));
        self::assertTrue(LeaverPolicy::mayReadOwnRecords($lastDay, $today));
        self::assertTrue(LeaverPolicy::maySubmit($lastDay, $today));
    }

    // -----------------------------------------------------------------
    // Inside the window
    // -----------------------------------------------------------------

    public function test_a_leaver_may_still_read_on_the_departure_instant(): void
    {
        self::assertTrue(LeaverPolicy::mayReadOwnRecords(
            $this->at(self::DEPARTED),
            $this->at(self::DEPARTED),
        ));
    }

    public function test_a_leaver_may_still_read_on_the_last_day_of_the_window(): void
    {
        self::assertTrue(LeaverPolicy::mayReadOwnRecords(
            $this->at(self::DEPARTED),
            $this->at('2026-09-15 08:59:59'),
        ));
    }

    /**
     * The one that pays for the decision: a leaver can fetch their own signed
     * induction paperwork on the way out without HR doing it by hand.
     */
    public function test_a_leaver_may_read_a_week_after_leaving(): void
    {
        self::assertTrue(LeaverPolicy::mayReadOwnRecords(
            $this->at(self::DEPARTED),
            $this->at('2026-09-08 09:00:00'),
        ));
    }

    // -----------------------------------------------------------------
    // The window closing
    // -----------------------------------------------------------------

    public function test_the_window_is_exclusive_of_its_end(): void
    {
        $departed = $this->at(self::DEPARTED);
        $endsAt = LeaverPolicy::accessEndsAt($departed);

        self::assertFalse(LeaverPolicy::mayReadOwnRecords($departed, $endsAt));
        self::assertTrue(LeaverPolicy::hasWindowClosed($departed, $endsAt));
    }

    public function test_a_leaver_is_refused_after_the_window(): void
    {
        self::assertFalse(LeaverPolicy::mayReadOwnRecords(
            $this->at(self::DEPARTED),
            $this->at('2026-09-16 09:00:00'),
        ));
    }

    public function test_a_current_employee_never_has_a_closed_window(): void
    {
        self::assertFalse(LeaverPolicy::hasWindowClosed(null, $this->at('2099-01-01 00:00:00')));
    }

    // -----------------------------------------------------------------
    // Read, not write
    // -----------------------------------------------------------------

    /**
     * The whole point of the read/write split. During the grace window a leaver
     * can read everything they did and add nothing to it.
     */
    public function test_submitting_stops_at_the_departure_instant_although_reading_continues(): void
    {
        $departed = $this->at(self::DEPARTED);
        $duringWindow = $this->at('2026-09-05 09:00:00');

        self::assertTrue(LeaverPolicy::mayReadOwnRecords($departed, $duringWindow));
        self::assertFalse(LeaverPolicy::maySubmit($departed, $duringWindow));
    }

    public function test_submitting_stops_exactly_on_departure(): void
    {
        $departed = $this->at(self::DEPARTED);

        self::assertTrue(LeaverPolicy::maySubmit($departed, $this->at('2026-09-01 08:59:59')));
        self::assertFalse(LeaverPolicy::maySubmit($departed, $departed));
    }

    // -----------------------------------------------------------------
    // Counting down
    // -----------------------------------------------------------------

    public function test_days_remaining_counts_down_through_the_window(): void
    {
        $departed = $this->at(self::DEPARTED);

        self::assertSame(14, LeaverPolicy::daysRemaining($departed, $departed));
        self::assertSame(7, LeaverPolicy::daysRemaining($departed, $this->at('2026-09-08 09:00:00')));
        self::assertSame(1, LeaverPolicy::daysRemaining($departed, $this->at('2026-09-14 09:00:00')));
    }

    public function test_days_remaining_is_zero_once_the_window_has_closed(): void
    {
        $departed = $this->at(self::DEPARTED);

        self::assertSame(0, LeaverPolicy::daysRemaining($departed, $this->at('2026-09-15 09:00:00')));
        self::assertSame(0, LeaverPolicy::daysRemaining($departed, $this->at('2027-01-01 00:00:00')));
    }

    /**
     * "No days left" and "no countdown running" are different states, and a
     * screen that renders both as "0 days remaining" is lying to one of them.
     * The caller tells them apart with hasDeparted().
     */
    public function test_days_remaining_is_zero_for_somebody_who_has_not_left(): void
    {
        $now = $this->at('2026-09-08 09:00:00');

        self::assertSame(0, LeaverPolicy::daysRemaining(null, $now));
        self::assertFalse(LeaverPolicy::hasDeparted(null, $now));
    }
}
