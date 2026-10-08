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

    /** What a history row records as the person's name at the time. */
    public function historyName(): string
    {
        return $this->name !== '' ? $this->name : $this->email;
    }
}
