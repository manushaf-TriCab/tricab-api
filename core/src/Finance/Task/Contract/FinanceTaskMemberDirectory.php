<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Task\Domain\TaskMember;

/**
 * Who Tri-Task members are, and the one thing Tri-Task stores about them (decision
 * D20; the portal's `list_members_with_permission`).
 *
 * A member is an active platform user holding `FINANCE.TASK.TASKS.READ` through an
 * active role. A platform administrator holding only `FINANCE.ADMIN` is not one
 * (decision D3). The highest of `FINANCE_TASK_ADMIN`, `_MANAGER`, `_USER` held names
 * the person's `accessRole`, else `user`.
 */
interface FinanceTaskMemberDirectory
{
    /**
     * Every member, by name, once each however many roles give them access.
     *
     * @return list<TaskMember>
     */
    public function members(): array;

    public function find(int $userId): ?TaskMember;

    /** Upserts the person's job title (`task_member_profiles`). The caller has checked they are a member. */
    public function setJobTitle(int $userId, string $jobTitle, DateTimeImmutable $now): void;
}
