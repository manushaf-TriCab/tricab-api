<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/**
 * The latest calendar invite sent for one task (Nick Han, 23 Oct 2026: "send calendar invites by due date"). One per
 * task: a re-send replaces it with the next `sequence`, because the same `uid` makes people's calendars update the
 * event instead of adding a second one. `dueDateSent` is the due date (`yyyy-MM-dd`) the invite carried, so a task
 * whose due date has moved since is out of date.
 */
final readonly class TaskCalendarInvite
{
    /** @param list<string> $recipients the attendee addresses, lower-case */
    public function __construct(
        public string $taskId,
        public string $uid,
        public int $sequence,
        public string $dueDateSent,
        public ?int $sentBy,
        public string $organizerEmail,
        public array $recipients,
        public DateTimeImmutable $sentAt,
    ) {
    }
}
