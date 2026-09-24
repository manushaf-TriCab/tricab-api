<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Contract;

use TriCab\Core\Onboarding\Application\DTO\Reminder\OutstandingReminderItem;

/**
 * Reads what may be reminded, and records what was (5.2).
 *
 * The record is `onboarding.NotificationLog`, whose unique index allows one row
 * per person, type, item and day. Claiming a row before the send is what stops a
 * retried or doubled job from emailing an item twice (A8).
 */
interface OnboardingReminderRepository
{
    /**
     * Live assignments in a reminded status, due on or before `$horizonDate` or
     * undated, belonging to people who have been invited and have not departed
     * at `$nowUtc`. Ordered by person, then due date (undated last), then title.
     *
     * @param list<string> $statuses
     * @return list<OutstandingReminderItem>
     */
    public function listOutstanding(array $statuses, string $horizonDate, \DateTimeImmutable $nowUtc): array;

    /**
     * Claims today's row for this item. Returns its id, or null when a row
     * already recorded it as sent (or suppressed) today. A row recorded as
     * `failed` today is claimed again, so a same-day retry resends it.
     */
    public function claim(int $userId, string $notificationType, string $subjectType, string $subjectPublicId, string $sentOn): ?int;

    /**
     * Marks claimed rows as failed, keeping them as evidence that a send was
     * attempted. The reason is a short class name, never the provider's body.
     *
     * @param list<int> $notificationLogIds
     */
    public function markFailed(array $notificationLogIds, string $reason): void;
}
