<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskBoardStore;
use TriCab\Core\Finance\Task\Contract\TaskSetupStore;
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskHistoryRow;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskNotFound;
use TriCab\Core\Finance\Task\Domain\TaskProject;
use TriCab\Core\Finance\Task\Domain\TaskRecord;

/**
 * Tasks (routes 10 to 19), from `store.py`: the visibility rule, the derived status,
 * dependencies, history, the Bin and analytics.
 *
 * - **Visibility (decision D15).** Without `READ_ALL` a person sees only the tasks they prepare
 *   or review; any other task is a 404, never a 403, on every read and write, history included.
 * - **Own versus any (decision D14).** The route is gated on the weaker permission; here
 *   `UPDATE` or (`UPDATE_OWN` and the task is theirs) may change it, and likewise `DELETE` and
 *   `DELETE_OWN`.
 * - **Overdue is derived**, in the configured time zone, and setting it is refused.
 * - **A status change** (complete, or reopen) is refused with `blockers` while a dependency is
 *   unfinished and not binned; creating a task runs no such check. Cycles are refused.
 * - **History** is written in the same transaction as the change.
 * - **Bulk update** is one transaction: one bad row rolls all back.
 */
final class TaskService
{
    public function __construct(
        private readonly TaskStore $tasks,
        private readonly TaskSetupStore $setup,
        private readonly FinanceTaskMemberDirectory $members,
        private readonly TaskTime $time,
        // Task groups (13 Oct 2026). Without it a task cannot be put in a group, as before the board.
        private readonly ?TaskBoardStore $board = null,
    ) {
    }

    // ==================================================================================
    // Reads
    // ==================================================================================

    /**
     * @return list<array<string, mixed>> every live task (or every binned one) the caller may see; no cap
     */
    public function list(TaskActor $actor, bool $deleted = false): array
    {
        $rows = $this->tasks->tasks($deleted, $this->visibleTo($actor));

        return $this->dicts($rows);
    }

    /** @return array<string, mixed> */
    public function get(TaskActor $actor, string $id): array
    {
        return $this->dict($this->visibleTask($actor, $id));
    }

    /** @return list<array<string, mixed>> */
    public function history(TaskActor $actor, string $id): array
    {
        $this->visibleTask($actor, $id);

        return array_map(TaskPresenter::history(...), $this->tasks->historyFor($id));
    }

    /**
     * Every history row (route 18), optionally only those into one status. Gated on ANALYTICS.READ.
     *
     * @return list<array<string, mixed>>
     */
    public function historyByStatus(?string $toStatus): array
    {
        return array_map(TaskPresenter::history(...), $this->tasks->historyAll($toStatus === '' ? null : $toStatus));
    }

    /**
     * What the Analytics page works from (route 19): every live task the caller may see, the zone and today.
     *
     * @return array<string, mixed>
     */
    public function analytics(TaskActor $actor): array
    {
        return ['tasks' => $this->list($actor), 'timezone' => $this->time->zoneName(), 'today' => $this->time->today()];
    }

    // ==================================================================================
    // Writes
    // ==================================================================================

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function create(TaskActor $actor, array $data): array
    {
        if (!$actor->can(Module::PERMISSION_TASK_TASKS_CREATE)) {
            throw new TaskForbidden('Permission denied');
        }
        [$fields, $dependencies] = $this->clean($data, false);
        [$fields, $reviewDue] = $this->linkProgress($actor, $fields, TaskRules::DEFAULT_STATUS, 0, [self::nullableInt($fields, 'reviewer_user_id')]);
        $now = $this->time->now();
        $status = is_string($fields['status'] ?? null) ? $fields['status'] : TaskRules::DEFAULT_STATUS;
        // A task given no project goes in "General" (item 3, Manusha 13 Oct 2026): anyone can allocate one
        // without a project existing first.
        $projectId = self::nullableStr($fields, 'project_id') ?? $this->generalProjectId($now);
        $this->guardCompletion($actor, '', $status, [self::nullableInt($fields, 'reviewer_user_id')]);
        $task = new TaskRecord(
            TaskRules::newId(),
            (string) $fields['name'],
            self::str($fields, 'description'),
            self::str($fields, 'requirements'),
            self::str($fields, 'notes'),
            self::str($fields, 'category', 'routine'),
            self::str($fields, 'finance_statement_area'),
            self::nullableStr($fields, 'entity_id'),
            $projectId,
            self::nullableInt($fields, 'preparer_user_id'),
            self::nullableInt($fields, 'reviewer_user_id'),
            $status,
            self::int($fields, 'progress'),
            self::nullableStr($fields, 'due_date'),
            ($fields['due_date_requested'] ?? false) === true,
            ($fields['urgent'] ?? false) === true,
            $status === 'completed' ? $now : null,
            $actor->userId,
            null,
            null,
            $now,
            $now,
            self::nullableStr($fields, 'priority'),
            $this->groupIn(self::nullableStr($fields, 'group_id'), $projectId),
        );

        $this->tasks->transactional(function () use ($task, $dependencies, $actor, $now): void {
            $this->tasks->insertTask($task);
            if ($dependencies !== null && $dependencies !== []) {
                $this->setDependencies($task->id, $dependencies, $now);
            }
            $this->recordStatusChange($actor, $task->id, null, $task->status, $now);
        });

        return $this->dict($task) + ($reviewDue ? ['review_due' => true] : []);
    }

    /**
     * The live project named "General", made on first use. Two first uses at once meet the unique name
     * index; the loser reads the winner's.
     */
    private function generalProjectId(DateTimeImmutable $now): string
    {
        $find = function (): ?string {
            foreach ($this->setup->projects(false) as $project) {
                if (mb_strtolower($project->name) === mb_strtolower(TaskRules::GENERAL_PROJECT)) {
                    return $project->id;
                }
            }

            return null;
        };
        $id = $find();
        if ($id !== null) {
            return $id;
        }
        $project = new TaskProject(TaskRules::newId(), TaskRules::GENERAL_PROJECT, 'Tasks that belong to no other project.', '#64748B', 'active', '', null, null, $now, $now);
        try {
            $this->setup->insertProject($project);
        } catch (\Throwable $failure) {
            return $find() ?? throw $failure;
        }

        return $project->id;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(TaskActor $actor, string $id, array $data): array
    {
        $reviewDue = false;
        $task = $this->tasks->transactional(function () use ($actor, $id, $data, &$reviewDue): TaskRecord {
            $task = $this->visibleTask($actor, $id);
            if ($task->deletedAt !== null) {
                throw new TaskConflict('Restore this task from the Bin before changing it');
            }
            $reviewDue = $this->applyUpdate($actor, $task, $data);

            return $task;
        });

        return $this->dict($task) + ($reviewDue ? ['review_due' => true] : []);
    }

    /**
     * All or nothing: one bad row rolls the whole batch back.
     *
     * @return list<array<string, mixed>>
     */
    public function bulkUpdate(TaskActor $actor, mixed $rows): array
    {
        if (!is_array($rows) || $rows === [] || !array_is_list($rows)) {
            throw new TaskInvalid('Send a list of tasks to update');
        }
        if (count($rows) > TaskRules::MAX_BULK) {
            throw new TaskInvalid('Up to ' . TaskRules::MAX_BULK . ' tasks at once');
        }

        $due = [];
        $touched = $this->tasks->transactional(function () use ($actor, $rows, &$due): array {
            $touched = [];
            foreach ($rows as $row) {
                if (!is_array($row) || !isset($row['id']) || $row['id'] === '' || $row['id'] === null || $row['id'] === false || $row['id'] === 0) {
                    throw new TaskInvalid('Every row needs an id');
                }
                $task = $this->visibleTask($actor, is_scalar($row['id']) ? (string) $row['id'] : '');
                if ($task->deletedAt !== null) {
                    throw new TaskConflict($task->name . ' is in the Bin');
                }
                $changes = [];
                foreach ($row as $key => $value) {
                    if ((string) $key !== 'id') {
                        $changes[(string) $key] = $value;
                    }
                }
                if ($this->applyUpdate($actor, $task, $changes)) {
                    $due[$task->id] = true;
                }
                $touched[] = $task;
            }

            return $touched;
        });

        return array_map(
            static fn (array $t): array => isset($due[$t['id']]) ? $t + ['review_due' => true] : $t,
            $this->dicts($touched),
        );
    }

    /**
     * Into the Bin; `$permanent` only from the Bin, and then the dependencies and history go too.
     *
     * @return array<string, bool>
     */
    public function delete(TaskActor $actor, string $id, bool $permanent = false): array
    {
        return $this->tasks->transactional(function () use ($actor, $id, $permanent): array {
            $task = $this->visibleTask($actor, $id);
            if (!$this->canDelete($actor, $task)) {
                throw new TaskForbidden('You do not have permission to delete this task');
            }
            if ($permanent) {
                if ($task->deletedAt === null) {
                    throw new TaskConflict('Move the task to the Bin before deleting it permanently');
                }
                $this->tasks->deleteTaskPermanently($task->id);

                return ['deleted' => true, 'permanent' => true];
            }
            if ($task->deletedAt === null) {
                $now = $this->time->now();
                $task->deletedAt = $now;
                $task->deletedBy = $actor->userId;
                $task->updatedDate = $now;
                $this->tasks->updateTask($task);
            }

            return ['deleted' => true, 'permanent' => false];
        });
    }

    /**
     * Several tasks into the Bin at once (Nick Han, 16 Oct 2026), all or nothing: one the caller may not
     * delete rolls the batch back. A task already in the Bin stays as it is.
     *
     * @return array{deleted: int, ids: list<string>}
     */
    public function bulkDelete(TaskActor $actor, mixed $ids): array
    {
        $ids = self::bulkIds($ids);

        return $this->tasks->transactional(function () use ($actor, $ids): array {
            $now = $this->time->now();
            $deleted = [];
            foreach ($ids as $id) {
                $task = $this->visibleTask($actor, $id);
                if (!$this->canDelete($actor, $task)) {
                    throw new TaskForbidden('You do not have permission to delete ' . $task->name);
                }
                if ($task->deletedAt === null) {
                    $task->deletedAt = $now;
                    $task->deletedBy = $actor->userId;
                    $task->updatedDate = $now;
                    $this->tasks->updateTask($task);
                    $deleted[] = $task->id;
                }
            }

            return ['deleted' => count($deleted), 'ids' => $deleted];
        });
    }

    /**
     * The undo of {@see bulkDelete}: the tasks back out of the Bin, all or nothing.
     *
     * @return list<array<string, mixed>>
     */
    public function bulkRestore(TaskActor $actor, mixed $ids): array
    {
        $ids = self::bulkIds($ids);
        $restored = $this->tasks->transactional(function () use ($actor, $ids): array {
            $now = $this->time->now();
            $out = [];
            foreach ($ids as $id) {
                $task = $this->visibleTask($actor, $id);
                if (!$this->canDelete($actor, $task)) {
                    throw new TaskForbidden('You do not have permission to restore ' . $task->name);
                }
                if ($task->deletedAt !== null) {
                    $task->deletedAt = null;
                    $task->deletedBy = null;
                    $task->updatedDate = $now;
                    $this->tasks->updateTask($task);
                }
                $out[] = $task;
            }

            return $out;
        });

        return $this->dicts($restored);
    }

    /** @return list<string> the distinct ids of a bulk request */
    private static function bulkIds(mixed $ids): array
    {
        if (!is_array($ids) || $ids === [] || !array_is_list($ids)) {
            throw new TaskInvalid('Send a list of task ids');
        }
        $out = [];
        foreach ($ids as $id) {
            $id = is_string($id) || is_int($id) ? (string) $id : '';
            if ($id === '') {
                throw new TaskInvalid('Every task id must be filled in');
            }
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }
        if (count($out) > TaskRules::MAX_BULK) {
            throw new TaskInvalid('Up to ' . TaskRules::MAX_BULK . ' tasks at once');
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function restore(TaskActor $actor, string $id): array
    {
        $task = $this->visibleTask($actor, $id);
        if (!$this->canDelete($actor, $task)) {
            throw new TaskForbidden('You do not have permission to restore this task');
        }
        $task->deletedAt = null;
        $task->deletedBy = null;
        $task->updatedDate = $this->time->now();
        $this->tasks->updateTask($task);

        return $this->dict($task);
    }

    // ==================================================================================
    // Rules
    // ==================================================================================

    private function visibleTo(TaskActor $actor): ?int
    {
        return $actor->seesAll() ? null : $actor->userId;
    }

    /** 404, not 403, for a task the caller may not see, so ids cannot be probed (decision D15). */
    public function requireVisible(TaskActor $actor, string $id): TaskRecord
    {
        return $this->visibleTask($actor, $id);
    }

    /** 404, not 403, for a task the caller may not see, so ids cannot be probed. */
    private function visibleTask(TaskActor $actor, string $id): TaskRecord
    {
        $task = $this->tasks->findTask($id);
        if ($task === null || (!$actor->seesAll() && !$task->isOwnedBy($actor->userId))) {
            throw new TaskNotFound('Task not found');
        }

        return $task;
    }

    /** Whether the caller may change the task: the import checks it before it plans an update. */
    public function mayEdit(TaskActor $actor, TaskRecord $task): bool
    {
        return $this->canEdit($actor, $task);
    }

    /** `can_edit_task`. */
    private function canEdit(TaskActor $actor, TaskRecord $task): bool
    {
        return $actor->can(Module::PERMISSION_TASK_TASKS_UPDATE)
            || ($actor->can(Module::PERMISSION_TASK_TASKS_UPDATE_OWN) && $task->isOwnedBy($actor->userId));
    }

    /** `can_delete_task`. */
    private function canDelete(TaskActor $actor, TaskRecord $task): bool
    {
        return $actor->can(Module::PERMISSION_TASK_TASKS_DELETE)
            || ($actor->can(Module::PERMISSION_TASK_TASKS_DELETE_OWN) && $task->isOwnedBy($actor->userId));
    }

    /**
     * `_apply_update`: checks the right to edit, validates, refuses a status change while blocked,
     * sets the dependencies, writes the task and, on a status change, its history row. Runs inside
     * the caller's transaction.
     *
     * @param array<string, mixed> $data
     */
    private function applyUpdate(TaskActor $actor, TaskRecord $task, array $data): bool
    {
        if (!$this->canEdit($actor, $task)) {
            throw new TaskForbidden('You do not have permission to change this task');
        }
        [$fields, $dependencies] = $this->clean($data, true);
        $oldStatus = TaskRules::status($task->status);
        [$fields, $reviewDue] = $this->linkProgress($actor, $fields, $oldStatus, $task->progress, [
            $task->reviewerUserId,
            array_key_exists('reviewer_user_id', $fields) ? self::nullableInt($fields, 'reviewer_user_id') : $task->reviewerUserId,
        ]);
        $newStatus = is_string($fields['status'] ?? null) ? $fields['status'] : $oldStatus;
        $now = $this->time->now();

        // The reviewer before this save counts as well as after it, so one save cannot drop the reviewer and close the task.
        $this->guardCompletion($actor, $oldStatus, $newStatus, [
            $task->reviewerUserId,
            array_key_exists('reviewer_user_id', $fields) ? self::nullableInt($fields, 'reviewer_user_id') : $task->reviewerUserId,
        ]);
        if ($newStatus !== $oldStatus && in_array($newStatus, TaskRules::BLOCKED_STATUSES, true)) {
            // The browser used to warn ("Start anyway"); the server refuses, listing what is in the way,
            // checked against the dependencies this same request is about to set, if it sends any.
            $blockers = $dependencies !== null
                ? $this->unfinished($this->tasks->tasksByIds($dependencies))
                : $this->unfinished($this->tasks->tasksByIds($this->tasks->dependencyMap()[$task->id] ?? []));
            if ($blockers !== []) {
                $names = implode(', ', array_column($blockers, 'name'));

                throw new TaskConflict('This task is blocked by unfinished tasks: ' . $names, ['blockers' => $blockers]);
            }
        }

        if ($dependencies !== null) {
            $this->setDependencies($task->id, $dependencies, $now);
        }
        foreach ($fields as $key => $value) {
            match ($key) {
                'name' => $task->name = (string) $value,
                'description' => $task->description = (string) $value,
                'requirements' => $task->requirements = (string) $value,
                'notes' => $task->notes = (string) $value,
                'finance_statement_area' => $task->financeStatementArea = (string) $value,
                'category' => $task->category = (string) $value,
                'status' => $task->status = (string) $value,
                'progress' => $task->progress = is_int($value) ? $value : 0,
                'due_date' => $task->dueDate = is_string($value) ? $value : null,
                'due_date_requested' => $task->dueDateRequested = $value === true,
                'urgent' => $task->urgent = $value === true,
                'entity_id' => $task->entityId = is_string($value) ? $value : null,
                'project_id' => $task->projectId = is_string($value) ? $value : null,
                'preparer_user_id' => $task->preparerUserId = is_int($value) ? $value : null,
                'reviewer_user_id' => $task->reviewerUserId = is_int($value) ? $value : null,
                'priority' => $task->priority = is_string($value) ? $value : null,
                default => null,
            };
        }
        // A group belongs to one project: a group sent must be the task's project's, and moving the task to another
        // project leaves its old group behind.
        if (array_key_exists('group_id', $fields)) {
            $task->groupId = $this->groupIn(self::nullableStr($fields, 'group_id'), $task->projectId);
        } elseif ($task->groupId !== null && array_key_exists('project_id', $fields)) {
            $group = $this->board?->findGroup($task->groupId);
            if ($group === null || $group->projectId !== $task->projectId) {
                $task->groupId = null;
            }
        }
        if ($newStatus !== $oldStatus) {
            $task->completedAt = $newStatus === 'completed' ? $now : null;
            $this->recordStatusChange($actor, $task->id, $oldStatus, $newStatus, $now);
        }
        $task->updatedDate = $now;
        $this->tasks->updateTask($task);

        return $reviewDue;
    }

    /**
     * The review step (13 Oct 2026, item 5): a task with a reviewer is marked Done by its reviewer, or by a Manager or
     * Administrator (who hold TASKS.UPDATE). Anyone else presses Send for review instead.
     *
     * @param list<?int> $reviewerUserIds every reviewer the task has had in this save
     */
    private function guardCompletion(TaskActor $actor, string $oldStatus, string $newStatus, array $reviewerUserIds): void
    {
        if ($newStatus === 'completed' && $oldStatus !== 'completed' && !$this->mayComplete($actor, $reviewerUserIds)) {
            throw new TaskForbidden('Only the reviewer can mark this task done. Use Send for review instead.');
        }
    }

    /** @param list<?int> $reviewerUserIds */
    private function mayComplete(TaskActor $actor, array $reviewerUserIds): bool
    {
        if ($actor->can(Module::PERMISSION_TASK_TASKS_UPDATE)) {
            return true;
        }
        foreach ($reviewerUserIds as $reviewerUserId) {
            if ($reviewerUserId !== null && $reviewerUserId !== $actor->userId) {
                return false;
            }
        }

        return true;
    }

    /**
     * Progress and status move together (Manusha, 14 Oct 2026):
     *  - Done sets progress to 100; Active or On Hold at 100 (a task reopened without a new progress) goes to 75;
     *  - progress reaching 100 makes the task Done, when this person may close it; when only the reviewer may, the
     *    task stays Active and the reviewer is asked (the caller sends it, on the returned flag);
     *  - progress below 100 on a Done task makes it Active again; an On Hold task stays On Hold.
     * A status sent with the progress wins, unless it is the status the task already has (a form sends every field).
     * A new task counts as Active before it is saved.
     *
     * @param array<string, mixed> $fields cleaned
     * @param list<?int> $reviewerUserIds
     * @return array{0: array<string, mixed>, 1: bool} the fields, and whether the reviewer should be asked
     */
    private function linkProgress(TaskActor $actor, array $fields, string $oldStatus, int $oldProgress, array $reviewerUserIds): array
    {
        $hasProgress = array_key_exists('progress', $fields);
        $progress = $hasProgress && is_int($fields['progress']) ? $fields['progress'] : $oldProgress;
        $status = is_string($fields['status'] ?? null) ? $fields['status'] : null;
        if ($status !== null && $status !== $oldStatus) {
            if ($status === 'completed') {
                $fields['progress'] = 100;
            } elseif ($oldStatus === 'completed' && $progress >= 100) {
                $fields['progress'] = 75;
            }

            return [$fields, false];
        }
        if (!$hasProgress) {
            return [$fields, false];
        }
        if ($progress >= 100 && $oldStatus !== 'completed') {
            if ($this->mayComplete($actor, $reviewerUserIds)) {
                $fields['status'] = 'completed';

                return [$fields, false];
            }

            return [$fields, $oldProgress < 100];
        }
        if ($progress < 100 && $oldStatus === 'completed') {
            $fields['status'] = 'active';
        }

        return [$fields, false];
    }

    /** The group id when it is one of the project's groups; refused otherwise. */
    private function groupIn(?string $groupId, ?string $projectId): ?string
    {
        if ($groupId === null) {
            return null;
        }
        $group = $this->board?->findGroup($groupId);
        if ($group === null) {
            throw new TaskInvalid('That group does not exist');
        }
        if ($group->projectId !== $projectId) {
            throw new TaskInvalid('That group belongs to another project');
        }

        return $groupId;
    }

    /**
     * Dependencies that stand in the way: live and not completed, by name.
     *
     * @param list<TaskRecord> $dependencies
     * @return list<array{id: string, name: string}>
     */
    private function unfinished(array $dependencies): array
    {
        $out = [];
        foreach ($dependencies as $dependency) {
            if ($dependency->deletedAt === null && $dependency->status !== 'completed') {
                $out[] = ['id' => $dependency->id, 'name' => $dependency->name];
            }
        }
        usort($out, static fn (array $a, array $b): int => [mb_strtolower($a['name']), $a['id']] <=> [mb_strtolower($b['name']), $b['id']]);

        return $out;
    }

    /**
     * `_set_dependencies`: no self-dependency, every dependency exists (binned ones too), and no
     * cycle: walking forward from each new dependency must never reach this task. The walk reads
     * every stored edge, binned tasks' included.
     *
     * @param list<string> $dependencyIds
     */
    private function setDependencies(string $taskId, array $dependencyIds, DateTimeImmutable $now): void
    {
        foreach ($dependencyIds as $dependencyId) {
            if ($dependencyId === $taskId) {
                throw new TaskInvalid('A task cannot depend on itself');
            }
        }
        if (count($this->tasks->tasksByIds($dependencyIds)) !== count($dependencyIds)) {
            throw new TaskInvalid('A dependency does not exist');
        }
        $graph = $this->tasks->dependencyMap();
        $graph[$taskId] = $dependencyIds;
        $seen = [];
        $stack = $dependencyIds;
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node === $taskId) {
                throw new TaskInvalid('Those dependencies would make the task wait on itself');
            }
            if (isset($seen[$node])) {
                continue;
            }
            $seen[$node] = true;
            foreach ($graph[$node] ?? [] as $next) {
                $stack[] = $next;
            }
        }
        $this->tasks->replaceDependencies($taskId, $dependencyIds, $now);
    }

    private function recordStatusChange(TaskActor $actor, string $taskId, ?string $old, ?string $new, DateTimeImmutable $now): void
    {
        $name = $actor->historyName();
        $this->tasks->addHistory(new TaskHistoryRow(
            TaskRules::newId(),
            $taskId,
            $old,
            $new,
            $actor->userId,
            $name === '' ? null : mb_substr($name, 0, 200),
            $now,
        ));
    }

    /**
     * `_clean_task`: the validated column values that were sent (all the required ones when not
     * partial), and the dependency list if one was sent.
     *
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: ?list<string>}
     */
    private function clean(array $data, bool $partial): array
    {
        $out = [];
        if (array_key_exists('name', $data) || !$partial) {
            $out['name'] = TaskRules::text($data, 'name', 300, true);
        }
        foreach (['description', 'requirements', 'notes'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = TaskRules::text($data, $key) ?? '';
            }
        }
        if (array_key_exists('finance_statement_area', $data)) {
            $out['finance_statement_area'] = TaskRules::text($data, 'finance_statement_area', 300) ?? '';
        }
        if (array_key_exists('category', $data)) {
            $category = $data['category'];
            if (!is_string($category) || !in_array($category, TaskRules::CATEGORIES, true)) {
                throw new TaskInvalid('Category must be routine or ad_hoc');
            }
            $out['category'] = $category;
        }
        if (array_key_exists('status', $data)) {
            $status = is_string($data['status']) ? TaskRules::status($data['status']) : $data['status'];
            if ($status === 'overdue') {
                throw new TaskInvalid('Overdue is worked out from the due date and cannot be set');
            }
            if (!is_string($status) || !in_array($status, TaskRules::STATUSES, true)) {
                throw new TaskInvalid('Status must be active, on_hold or completed');
            }
            $out['status'] = $status;
        }
        if (array_key_exists('progress', $data)) {
            $out['progress'] = TaskRules::progress($data['progress']);
        }
        if (array_key_exists('priority', $data)) {
            $priority = $data['priority'];
            if ($priority === null || $priority === '') {
                $out['priority'] = null;
            } elseif (is_string($priority) && in_array($priority, TaskRules::PRIORITIES, true)) {
                $out['priority'] = $priority;
            } else {
                throw new TaskInvalid('Priority must be critical, high, medium or low');
            }
        }
        if (array_key_exists('group_id', $data)) {
            $out['group_id'] = self::identifier($data['group_id']);
        }
        if (array_key_exists('due_date', $data)) {
            $out['due_date'] = TaskRules::date($data['due_date']);
        }
        foreach (['due_date_requested', 'urgent'] as $key) {
            if (array_key_exists($key, $data)) {
                $out[$key] = TaskRules::bool($data[$key]);
            }
        }
        if (array_key_exists('entity_id', $data)) {
            $entityId = self::identifier($data['entity_id']);
            if ($entityId !== null && $this->setup->findEntity($entityId) === null) {
                throw new TaskInvalid('That entity does not exist');
            }
            $out['entity_id'] = $entityId;
        }
        if (array_key_exists('project_id', $data)) {
            $projectId = self::identifier($data['project_id']);
            if ($projectId !== null) {
                $project = $this->setup->findProject($projectId);
                if ($project === null || $project->deletedAt !== null) {
                    throw new TaskInvalid('That project does not exist');
                }
            }
            $out['project_id'] = $projectId;
        }
        foreach (['preparer_id' => 'preparer_user_id', 'reviewer_id' => 'reviewer_user_id'] as $apiKey => $column) {
            if (array_key_exists($apiKey, $data)) {
                $userId = TaskRules::userId($data[$apiKey]);
                if ($userId !== null && $this->members->find($userId) === null) {
                    throw new TaskInvalid('The preparer and reviewer must be members of Tri-Task');
                }
                $out[$column] = $userId;
            }
        }
        $dependencies = null;
        if (array_key_exists('dependencies', $data)) {
            $raw = $data['dependencies'];
            if ($raw === null || $raw === '' || $raw === false || $raw === [] || $raw === 0) {
                $raw = [];
            }
            if (!is_array($raw) || !array_is_list($raw)) {
                throw new TaskInvalid('Dependencies must be a list of task ids');
            }
            $dependencies = [];
            foreach ($raw as $item) {
                $id = is_scalar($item) ? (string) $item : '';
                if (!in_array($id, $dependencies, true)) {
                    $dependencies[] = $id;
                }
            }
        }

        return [$out, $dependencies];
    }

    /** An id from a payload: empty means none. */
    private static function identifier(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false || $value === 0) {
            return null;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    // ==================================================================================
    // Presentation
    // ==================================================================================

    /** @return array<string, mixed> */
    private function dict(TaskRecord $task): array
    {
        return $this->dicts([$task])[0];
    }

    /**
     * @param list<TaskRecord> $rows
     * @return list<array<string, mixed>>
     */
    private function dicts(array $rows): array
    {
        $dependencies = $this->tasks->dependencyMap();
        $projects = $this->setup->projectNames();
        $today = $this->time->today();
        $out = [];
        foreach ($rows as $task) {
            $out[] = TaskPresenter::task($task, $dependencies, $projects, $today);
        }

        return $out;
    }

    /** @param array<string, mixed> $fields */
    private static function str(array $fields, string $key, string $default = ''): string
    {
        $value = $fields[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    /** @param array<string, mixed> $fields */
    private static function nullableStr(array $fields, string $key): ?string
    {
        $value = $fields[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /** @param array<string, mixed> $fields */
    private static function nullableInt(array $fields, string $key): ?int
    {
        $value = $fields[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @param array<string, mixed> $fields */
    private static function int(array $fields, string $key): int
    {
        $value = $fields[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
