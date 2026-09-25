<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Domain\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Onboarding\Domain\ValueObject\AssignmentStatus;

/**
 * Which outstanding items a reminder digest names, and how (5.2).
 *
 * Restates the source's nightly rule: an item still on the person's plate that
 * is overdue, due within {@see HORIZON_DAYS}, or has no due date at all. One
 * departure from it, on purpose:
 *
 *  - **`need_help` is not reminded.** The person has already said they are
 *    stuck, and the next move is HR's. Emailing them that the item is "still
 *    waiting for you" tells them something untrue and nags them for HR's delay.
 *    It is in the reviewer queue, which is where it gets seen.
 *
 * "Today" and "overdue" are {@see OverduePolicy}'s, so a reminder calls an item
 * overdue exactly when the reports do: a Melbourne date, overdue from the day
 * after it is due.
 *
 * Pure: no database, no clock. The caller supplies `now`.
 */
final class ReminderPolicy
{
    public const HORIZON_DAYS = 2;

    public const TYPE_OVERDUE = 'onboarding_overdue';
    public const TYPE_DUE_SOON = 'onboarding_due_soon';
    public const TYPE_UNDATED = 'onboarding_undated';

    /** @var list<string> */
    private const REMINDED = [
        AssignmentStatus::NOT_STARTED,
        AssignmentStatus::IN_PROGRESS,
        AssignmentStatus::FIX_AND_RESUBMIT,
    ];

    /** @return list<string> */
    public static function remindedStatuses(): array
    {
        return self::REMINDED;
    }

    /** The last due date that still counts as "due soon" on this local date. */
    public static function horizonDate(string $today): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $today, new DateTimeZone('UTC'));
        if ($date === false) {
            throw new \InvalidArgumentException('today must be YYYY-MM-DD.');
        }

        return $date->modify(sprintf('+%d days', self::HORIZON_DAYS))->format('Y-m-d');
    }

    /**
     * The notification type for an item with this status and due date on this
     * local date, or null when it is not reminded today.
     */
    public static function typeFor(string $status, ?string $dueDate, string $today): ?string
    {
        if (!in_array($status, self::REMINDED, true)) {
            return null;
        }
        if ($dueDate === null || $dueDate === '') {
            return self::TYPE_UNDATED;
        }
        if (OverduePolicy::isOverdue(AssignmentStatus::fromString($status), $dueDate, $today)) {
            return self::TYPE_OVERDUE;
        }

        return $dueDate <= self::horizonDate($today) ? self::TYPE_DUE_SOON : null;
    }
}
