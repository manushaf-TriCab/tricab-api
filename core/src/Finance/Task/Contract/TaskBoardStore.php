<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Contract;

use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskGroup;
use TriCab\Core\Finance\Task\Domain\TaskProjectTemplate;

/**
 * The project board's own rows (13 Oct 2026): task groups and project templates. Tasks stay in `TaskStore`; a use
 * case that touches both runs in one transaction (the SQL stores share a connection).
 */
interface TaskBoardStore extends TaskTransactions
{
    // ---- groups ------------------------------------------------------------------------

    /** @return list<TaskGroup> a project's groups by sort order, then id */
    public function groupsFor(string $projectId): array;

    public function findGroup(string $id): ?TaskGroup;

    public function insertGroup(TaskGroup $group): void;

    public function updateGroup(TaskGroup $group): void;

    /** Removes the group row only; the caller clears its tasks' `group_id` first. */
    public function deleteGroup(string $id): void;

    // ---- project templates -------------------------------------------------------------

    /** @return list<TaskProjectTemplate> by name */
    public function projectTemplates(): array;

    public function findProjectTemplate(string $id): ?TaskProjectTemplate;

    /** @throws TaskConflict when the name is taken (the unique index) */
    public function insertProjectTemplate(TaskProjectTemplate $template): void;

    /** @throws TaskConflict when the name is taken (the unique index) */
    public function updateProjectTemplate(TaskProjectTemplate $template): void;

    public function deleteProjectTemplate(string $id): void;
}
