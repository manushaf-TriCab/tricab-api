<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Contract;

use TriCab\Core\Finance\Task\Domain\TaskCalendarInvite;

/** `finance.task_calendar_invites`: the latest calendar invite sent for each task. */
interface TaskCalendarInvites
{
    /**
     * Every task's latest invite in one query, so a task list costs nothing per row.
     *
     * @return array<string, TaskCalendarInvite> by task id
     */
    public function all(): array;

    public function find(string $taskId): ?TaskCalendarInvite;

    /** Stores the invite as the task's latest, replacing an earlier one. */
    public function save(TaskCalendarInvite $invite): void;
}
