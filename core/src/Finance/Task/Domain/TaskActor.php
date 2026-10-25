<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use TriCab\Core\Finance\Module;

/**
 * The signed-in person, as the use cases need them: the platform user id (decision
 * D7), the name the history records, and the platform permission codes they hold.
 * The portal's `Actor`, with `identity.Users.UserId` for its id.
 */
final readonly class TaskActor
{
    /**
     * @param list<string> $permissions platform codes (`FINANCE.TASK.TASKS.READ`)
     */
    public function __construct(
        public int $userId,
        public string $name,
        public string $email,
        public array $permissions,
    ) {
    }

    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /** Without READ_ALL a person sees only the tasks they prepare or review (decision D15). */
    public function seesAll(): bool
    {
        return $this->can(Module::PERMISSION_TASK_TASKS_READ_ALL);
    }

    /** D15: without READ_ALL only a task the person prepares or reviews is visible (a 404 otherwise). */
    public function maySee(TaskRecord $task): bool
    {
        return $this->seesAll() || $task->isOwnedBy($this->userId);
    }

    /** `can_edit_task` (D14): UPDATE, or UPDATE_OWN on a task that is theirs. Files follow the same rule. */
    public function mayEdit(TaskRecord $task): bool
    {
        return $this->can(Module::PERMISSION_TASK_TASKS_UPDATE)
            || ($this->can(Module::PERMISSION_TASK_TASKS_UPDATE_OWN) && $task->isOwnedBy($this->userId));
    }

    /** What a history row records as the person's name at the time. */
    public function historyName(): string
    {
        return $this->name !== '' ? $this->name : $this->email;
    }
}
