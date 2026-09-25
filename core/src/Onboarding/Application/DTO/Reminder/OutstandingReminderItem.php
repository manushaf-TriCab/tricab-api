<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Reminder;

/**
 * One live assignment that may go into somebody's reminder digest (5.2).
 *
 * The repository returns every candidate for invited people who have not
 * departed; {@see \TriCab\Core\Onboarding\Domain\Service\ReminderPolicy} decides
 * which of them are reminded today. Titles and dates only — nothing a reminder
 * could leak beyond what the person already sees on their own page.
 */
final readonly class OutstandingReminderItem
{
    /**
     * @param 'training'|'policy'|'form' $itemType
     */
    public function __construct(
        public int $userId,
        public string $emailAddress,
        public string $displayName,
        public string $assignmentPublicId,
        public string $itemType,
        public string $title,
        public string $status,
        public ?string $dueDate,
    ) {
    }
}
