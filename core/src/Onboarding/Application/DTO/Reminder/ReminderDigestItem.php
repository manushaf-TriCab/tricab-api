<?php

declare(strict_types=1);

namespace TriCab\Core\Onboarding\Application\DTO\Reminder;

/** One line of a reminder email: a title and when it was due. */
final readonly class ReminderDigestItem
{
    public function __construct(
        public string $title,
        public ?string $dueDate,
        public bool $overdue,
    ) {
    }
}
