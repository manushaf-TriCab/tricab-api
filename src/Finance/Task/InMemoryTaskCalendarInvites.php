<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use TriCab\Core\Finance\Task\Contract\TaskCalendarInvites;
use TriCab\Core\Finance\Task\Domain\TaskCalendarInvite;

/** The latest invite per task, held in memory. */
final class InMemoryTaskCalendarInvites implements TaskCalendarInvites
{
    /** @var array<string, TaskCalendarInvite> */
    private array $invites = [];

    public function all(): array
    {
        return $this->invites;
    }

    public function find(string $taskId): ?TaskCalendarInvite
    {
        return $this->invites[$taskId] ?? null;
    }

    public function save(TaskCalendarInvite $invite): void
    {
        $this->invites[$invite->taskId] = $invite;
    }
}
