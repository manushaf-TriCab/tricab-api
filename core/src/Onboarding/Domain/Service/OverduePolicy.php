<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * When a piece of allocated work counts as overdue (4.5).
 *
 * The source's only rule was in its reminder job, which compared `due_on` with
 * `new Date().toISOString().slice(0, 10)` — a UTC date, so in Melbourne an item
 * turned overdue at ten or eleven in the morning of the day after it was due
 * rather than at midnight. Here:
 *
 *  - **The due date is a Melbourne calendar date**, as HR entered it, and an
 *    item is overdue from the start of the day AFTER it. Due today is not
 *    overdue — the person still has today.
 *  - **Only work still on the person's plate** can be overdue
 *    ({@see AssignmentStatus::isToBeWorkedOn()}). A submission waiting on a
 *    reviewer is late on HR's side, not theirs, and the review queue already
 *    shows how long it has waited. Complete is never overdue.
 *  - **A leaver's unfinished work is not overdue.** Nobody is going to chase
 *    them for it; the reports count leavers separately. That filter needs the
 *    departure date, so the callers apply it with
 *    {@see LeaverPolicy::hasDeparted()} rather than this class.
 *
 * Mirrored in SQL by `AzureSqlOnboardingReportRepository` as `[DueDate] <
 * :today AND [Status] IN (…toBeWorkedOnSet…)`, and the in-memory repository
 * calls this directly, so the two are held to the same fixtures.
 *
 * Pure: the caller supplies `now`.
 */
final class OverduePolicy
{
    /** Today's date in TriCab's own timezone, as `YYYY-MM-DD`. */
    public static function todayFor(DateTimeImmutable $nowUtc): string
    {
        return $nowUtc->setTimezone(new DateTimeZone(LeaverPolicy::LOCAL_TIMEZONE))->format('Y-m-d');
    }

    public static function isOverdue(AssignmentStatus $status, ?string $dueDate, string $today): bool
    {
        return $dueDate !== null && $status->isToBeWorkedOn() && $dueDate < $today;
    }

    /** Whole days past the due date; 0 for anything not overdue. */
    public static function daysOverdue(string $dueDate, string $today): int
    {
        $due = DateTimeImmutable::createFromFormat('!Y-m-d', $dueDate, new DateTimeZone('UTC'));
        $now = DateTimeImmutable::createFromFormat('!Y-m-d', $today, new DateTimeZone('UTC'));
        if ($due === false || $now === false || $due >= $now) {
            return 0;
        }

        return (int) $due->diff($now)->days;
    }
}
