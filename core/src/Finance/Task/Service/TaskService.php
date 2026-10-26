<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use Throwable;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Contract\TaskBoardStore;
use TriCab\Core\Finance\Task\Contract\TaskCalendarInvites;
use TriCab\Core\Finance\Task\Contract\TaskSetupStore;
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\TaskActor;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskForbidden;
use TriCab\Core\Finance\Task\Domain\TaskHistoryRow;
use TriCab\Core\Finance\Task\Domain\TaskInvalid;
use TriCab\Core\Finance\Task\Domain\TaskMember;
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
        // The latest calendar invite per task (23 Oct 2026). Without it no task carries one.
        private readonly ?TaskCalendarInvites $invites = null,
        // Files on tasks (23 Oct 2026): the paperclip count on every task, and removal of the files when a task is deleted for good.
        private readonly ?TaskFileService $files = null,
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

        return $this->dicts($rows, $actor);
    }

    /** @return array<string, mixed> */
    public function get(TaskActor $actor, string $id): array
    {
        return $this->dict($this->visibleTask($actor, $id), $actor);
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
        [$fields, $reviewDue] = $this->linkProgress($actor, $fields, TaskRules::DEFAULT_STATUS, 0, [self::ids($fields, 'reviewer_user_ids')]);
        $now = $this->time->now();
        $status = is_string($fields['status'] ?? null) ? $fields['status'] : TaskRules::DEFAULT_STATUS;
        // A task given no project goes in "General" (item 3, Manusha 13 Oct 2026): anyone can allocate one
        // without a project existing first.
        $projectId = self::nullableStr($fields, 'project_id') ?? $this->generalProjectId($now);
        $this->guardCompletion($actor, '', $status, [self::ids($fields, 'reviewer_user_ids')]);
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
            self::ids($fields, 'preparer_user_ids')[0] ?? null,
            self::ids($fields, 'reviewer_user_ids')[0] ?? null,
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
            null,
            self::ids($fields, 'preparer_user_ids'),
            self::ids($fields, 'reviewer_user_ids'),
            self::str($fields, 'time_spent'),
        );

        $this->tasks->transactional(function () use ($task, $dependencies, $actor, $now): void {
            $this->tasks->insertTask($task);
            if ($dependencies !== null && $dependencies !== []) {
                $this->setDependencies($task->id, $dependencies, $now);
            }
            $this->recordStatusChange($actor, $task->id, null, $task->status, $now);
            $this->markMentioned($actor, $task, '', $now);
        });

        return $this->dict($task, $actor) + ($reviewDue ? ['review_due' => true] : []);
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

        return $this->dict($task, $actor) + ($reviewDue ? ['review_due' => true] : []);
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
            $this->dicts($touched, $actor),
        );
    }

    /**
     * Into the Bin; `$permanent` only from the Bin, and then the dependencies and history go too.
     *
     * @return array<string, bool>
     */
    public function delete(TaskActor $actor, string $id, bool $permanent = false): array
    {
        $result = $this->tasks->transactional(function () use ($actor, $id, $permanent): array {
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

        // The task is gone for good only now that the transaction has committed: take its files off (bytes, then
        // rows). A failure here leaves files nothing can reach, so it is reported, never turned into an error.
        if ($permanent && $this->files !== null) {
            try {
                $this->files->purge([$id]);
            } catch (Throwable $failure) {
                error_log('Tri-Task: files of deleted task ' . $id . ' were left behind: ' . $failure->getMessage());
                $result['files_orphaned'] = true;
            }
        }

        return $result;
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
                    $this->requireProjectOutOfBin($task);
                    $task->deletedAt = null;
                    $task->deletedBy = null;
                    $task->updatedDate = $now;
                    $this->tasks->updateTask($task);
                }
                $out[] = $task;
            }

            return $out;
        });

        return $this->dicts($restored, $actor);
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
        if ($task->deletedAt !== null) {
            $this->requireProjectOutOfBin($task);
        }
        $task->deletedAt = null;
        $task->deletedBy = null;
        $task->updatedDate = $this->time->now();
        $this->tasks->updateTask($task);

        return $this->dict($task, $actor);
    }

    /**
     * A task whose project is in the Bin comes back with the project (Nick Han, 23 Oct 2026), not on
     * its own: it would otherwise sit live under a project nobody can see.
     */
    private function requireProjectOutOfBin(TaskRecord $task): void
    {
        $project = $task->projectId === null ? null : $this->setup->findProject($task->projectId);
        if ($project !== null && $project->deletedAt !== null) {
            throw new TaskConflict('Its project "' . $project->name . '" is in the Bin. Restore the project from the Bin first.');
        }
    }

    // ==================================================================================
    // Unread, Flag and Pin (each person's own, like a mailbox)
    // ==================================================================================

    /**
     * Sets the signed-in person's own marks on one task: `read` (true on opening it, false for "Mark as unread"),
     * `flagged`, `pinned`. A mark left out is left as it is. Anyone who can see the task may; nobody else's
     * marks change, and the task itself is not touched (no `updated_date`, no history).
     *
     * @param array<string, mixed> $marks
     * @return array<string, mixed> the task as the person now sees it
     */
    public function setUserState(TaskActor $actor, string $id, array $marks): array
    {
        $marks = self::marks($marks);
        $task = $this->tasks->transactional(function () use ($actor, $id, $marks): TaskRecord {
            $task = $this->visibleTask($actor, $id);
            $this->tasks->saveUserState($actor->userId, $task->id, $marks[0], $marks[1], $marks[2], $this->time->now());

            return $task;
        });

        return $this->dict($task, $actor);
    }

    /**
     * {@see setUserState} for several tasks at once, all or nothing.
     *
     * @param array<string, mixed> $marks
     * @return list<array<string, mixed>>
     */
    public function bulkSetUserState(TaskActor $actor, mixed $ids, array $marks): array
    {
        $ids = self::bulkIds($ids);
        $marks = self::marks($marks);
        $tasks = $this->tasks->transactional(function () use ($actor, $ids, $marks): array {
            $now = $this->time->now();
            $out = [];
            foreach ($ids as $id) {
                $task = $this->visibleTask($actor, $id);
                $this->tasks->saveUserState($actor->userId, $task->id, $marks[0], $marks[1], $marks[2], $now);
                $out[] = $task;
            }

            return $out;
        });

        return $this->dicts($tasks, $actor);
    }

    /**
     * @param array<string, mixed> $marks
     * @return array{0: ?bool, 1: ?bool, 2: ?bool} read, flagged, pinned
     */
    private static function marks(array $marks): array
    {
        $out = [];
        foreach (['read', 'flagged', 'pinned'] as $key) {
            $value = $marks[$key] ?? null;
            if ($value !== null && !is_bool($value)) {
                throw new TaskInvalid('"' . $key . '" must be true or false');
            }
            $out[] = $value;
        }
        if ($out === [null, null, null]) {
            throw new TaskInvalid('Send read, flagged or pinned');
        }

        return $out;
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
        if ($task === null || !$actor->maySee($task)) {
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
        return $actor->mayEdit($task);
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
        $fields = $this->keepPeopleOlderClientsCannotName($task, $fields);
        $oldStatus = TaskRules::status($task->status);
        $reviewerSets = [
            $task->reviewerUserIds,
            array_key_exists('reviewer_user_ids', $fields) ? self::ids($fields, 'reviewer_user_ids') : $task->reviewerUserIds,
        ];
        [$fields, $reviewDue] = $this->linkProgress($actor, $fields, $oldStatus, $task->progress, $reviewerSets);
        $newStatus = is_string($fields['status'] ?? null) ? $fields['status'] : $oldStatus;
        $now = $this->time->now();

        // The reviewers before this save count as well as after it, so one save cannot drop the reviewers and close the task.
        $this->guardCompletion($actor, $oldStatus, $newStatus, $reviewerSets);
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

        $before = clone $task;
        $notesBefore = $task->notes;
        $dependenciesChanged = false;
        if ($dependencies !== null) {
            $old = $this->tasks->dependencyMap()[$task->id] ?? [];
            $dependenciesChanged = array_diff($old, $dependencies) !== [] || array_diff($dependencies, $old) !== [];
            $this->setDependencies($task->id, $dependencies, $now);
        }
        $groupBefore = $task->groupId;
        $projectBefore = $task->projectId;
        foreach ($fields as $key => $value) {
            match ($key) {
                'name' => $task->name = (string) $value,
                'description' => $task->description = (string) $value,
                'requirements' => $task->requirements = (string) $value,
                'notes' => $task->notes = (string) $value,
                'time_spent' => $task->timeSpent = is_string($value) ? $value : '',
                'finance_statement_area' => $task->financeStatementArea = (string) $value,
                'category' => $task->category = (string) $value,
                'status' => $task->status = (string) $value,
                'progress' => $task->progress = is_int($value) ? $value : 0,
                'due_date' => $task->dueDate = is_string($value) ? $value : null,
                'due_date_requested' => $task->dueDateRequested = $value === true,
                'urgent' => $task->urgent = $value === true,
                'entity_id' => $task->entityId = is_string($value) ? $value : null,
                'project_id' => $task->projectId = is_string($value) ? $value : null,
                'preparer_user_ids' => $task->setPreparers(self::intList($value)),
                'reviewer_user_ids' => $task->setReviewers(self::intList($value)),
                'priority' => $task->priority = is_string($value) ? $value : null,
                default => null,
            };
        }
        // A group belongs to one project: a group sent must be the task's project's, and moving the task to another
        // project leaves its old group behind. A task that lands in another group goes to its end (its place on the
        // board is the old group's).
        if (array_key_exists('group_id', $fields)) {
            $task->groupId = $this->groupIn(self::nullableStr($fields, 'group_id'), $task->projectId);
        } elseif ($task->groupId !== null && array_key_exists('project_id', $fields)) {
            $group = $this->board?->findGroup($task->groupId);
            if ($group === null || $group->projectId !== $task->projectId) {
                $task->groupId = null;
            }
        }
        if ($task->groupId !== $groupBefore || $task->projectId !== $projectBefore) {
            $task->sortOrder = null;
        }
        if ($newStatus !== $oldStatus) {
            $task->completedAt = $newStatus === 'completed' ? $now : null;
            $this->recordStatusChange($actor, $task->id, $oldStatus, $newStatus, $now);
        }
        $this->recordChanges($actor, $before, $task, $dependenciesChanged, $now);
        $task->updatedDate = $now;
        $this->tasks->updateTask($task);
        $this->markMentioned($actor, $task, $notesBefore, $now);

        return $reviewDue;
    }

    /**
     * An @mention in the notes (Nick Han, 23 Oct 2026) makes the task unread for the person mentioned, as new mail
     * is: no email, and only for a mention the save adds (one already in the old notes, or removed, changes nothing).
     * Mentioning yourself does nothing. Only a member who can see the task is told: someone on it, or a Manager or
     * Admin; the mention never widens who sees it.
     */
    private function markMentioned(TaskActor $actor, TaskRecord $task, string $notesBefore, DateTimeImmutable $now): void
    {
        $added = array_diff(TaskMentions::ids($task->notes), TaskMentions::ids($notesBefore), [$actor->userId]);
        foreach ($added as $userId) {
            $member = $this->members->find($userId);
            if ($member === null || ($member->accessRole === TaskMember::ROLE_USER && !$task->isOwnedBy($userId))) {
                continue;
            }
            $this->tasks->saveUserState($userId, $task->id, false, null, null, $now);
        }
    }

    /**
     * A task dragged into another group on its board (TaskBoardService::orderTasks) is logged as a change of group,
     * as the same move made in the form is. A new place in the same group is not a change to the task.
     */
    public function recordGroupMove(TaskActor $actor, string $taskId, ?string $from, ?string $to, DateTimeImmutable $now): void
    {
        if ($from !== $to) {
            $this->recordChange($actor, $taskId, 'group', $this->groupName($from), $this->groupName($to), $now);
        }
    }

    /**
     * The review step (13 Oct 2026, item 5): a task with reviewers is marked Done by any one of them, or by a Manager or
     * Administrator (who hold TASKS.UPDATE); no sign-off from the others (23 Oct 2026, several reviewers). Anyone else
     * presses Send for review instead.
     *
     * @param list<list<int>> $reviewerSets the task's reviewers before this save and after it
     */
    private function guardCompletion(TaskActor $actor, string $oldStatus, string $newStatus, array $reviewerSets): void
    {
        if ($newStatus === 'completed' && $oldStatus !== 'completed' && !$this->mayComplete($actor, $reviewerSets)) {
            throw new TaskForbidden('Only the reviewer can mark this task done. Use Send for review instead.');
        }
    }

    /**
     * A set with reviewers must include the actor; a set with none puts no one in the way.
     *
     * @param list<list<int>> $reviewerSets
     */
    private function mayComplete(TaskActor $actor, array $reviewerSets): bool
    {
        if ($actor->can(Module::PERMISSION_TASK_TASKS_UPDATE)) {
            return true;
        }
        foreach ($reviewerSets as $reviewers) {
            if ($reviewers !== [] && !in_array($actor->userId, $reviewers, true)) {
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
     * @param list<list<int>> $reviewerSets
     * @return array{0: array<string, mixed>, 1: bool} the fields, and whether the reviewers should be asked
     */
    private function linkProgress(TaskActor $actor, array $fields, string $oldStatus, int $oldProgress, array $reviewerSets): array
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
            if ($this->mayComplete($actor, $reviewerSets)) {
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
     * One history row for each field a save changed, besides the status (which has its own). People, the entity, the
     * project and the group are kept by name as they were at the time, so the log still reads after a rename or a
     * delete; codes (category, priority) stay codes for the page to label. Long text is logged as changed, without
     * its words.
     */
    private function recordChanges(TaskActor $actor, TaskRecord $before, TaskRecord $after, bool $dependenciesChanged, DateTimeImmutable $now): void
    {
        $values = [
            'name' => [$before->name, $after->name],
            'category' => [$before->category, $after->category],
            'priority' => [$before->priority, $after->priority],
            'finance_statement_area' => [$before->financeStatementArea, $after->financeStatementArea],
            'progress' => [(string) $before->progress, (string) $after->progress],
            'due_date' => [$before->dueDate, $after->dueDate],
            'due_date_requested' => [self::flag($before->dueDateRequested), self::flag($after->dueDateRequested)],
            'urgent' => [self::flag($before->urgent), self::flag($after->urgent)],
            'time_spent' => [self::blankAsNone($before->timeSpent), self::blankAsNone($after->timeSpent)],
        ];
        foreach ($values as $field => [$from, $to]) {
            if (($from ?? '') !== ($to ?? '')) {
                $this->recordChange($actor, $after->id, $field, $from, $to, $now);
            }
        }
        $references = [
            'project' => [$before->projectId, $after->projectId, fn (string $id): ?string => $this->setup->findProject($id)?->name],
            'group' => [$before->groupId, $after->groupId, fn (string $id): ?string => $this->groupName($id)],
            'entity' => [$before->entityId, $after->entityId, fn (string $id): ?string => $this->setup->findEntity($id)?->name],
        ];
        foreach ($references as $field => [$from, $to, $name]) {
            if ($from !== $to) {
                $this->recordChange($actor, $after->id, $field, $from === null ? null : $name($from), $to === null ? null : $name($to), $now);
            }
        }
        // People are logged as the names on the task, "Una User, Max Manager", before and after.
        foreach (['preparer' => [$before->preparerUserIds, $after->preparerUserIds], 'reviewer' => [$before->reviewerUserIds, $after->reviewerUserIds]] as $field => [$from, $to]) {
            if ($from !== $to) {
                $this->recordChange($actor, $after->id, $field, $this->peopleNames($from), $this->peopleNames($to), $now);
            }
        }
        foreach (['description' => [$before->description, $after->description], 'requirements' => [$before->requirements, $after->requirements], 'notes' => [$before->notes, $after->notes]] as $field => [$from, $to]) {
            if ($from !== $to) {
                $this->recordChange($actor, $after->id, $field, null, null, $now);
            }
        }
        if ($dependenciesChanged) {
            $this->recordChange($actor, $after->id, 'dependencies', null, null, $now);
        }
    }

    /** @param list<int> $userIds */
    private function peopleNames(array $userIds): ?string
    {
        if ($userIds === []) {
            return null;
        }

        return implode(', ', array_map(fn (int $id): string => $this->members->find($id)->name ?? ('#' . $id), $userIds));
    }

    private function recordChange(TaskActor $actor, string $taskId, string $field, ?string $from, ?string $to, DateTimeImmutable $now): void
    {
        $name = $actor->historyName();
        $this->tasks->addHistory(new TaskHistoryRow(
            TaskRules::newId(),
            $taskId,
            null,
            null,
            $actor->userId,
            $name === '' ? null : mb_substr($name, 0, 200),
            $now,
            $field,
            $from === null ? null : mb_substr($from, 0, 400),
            $to === null ? null : mb_substr($to, 0, 400),
        ));
    }

    private function groupName(?string $groupId): ?string
    {
        return $groupId === null ? null : $this->board?->findGroup($groupId)?->name;
    }

    private static function flag(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private static function blankAsNone(string $value): ?string
    {
        return $value === '' ? null : $value;
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
        if (array_key_exists('time_spent', $data)) {
            $out['time_spent'] = TaskRules::text($data, 'time_spent', TaskRules::TIME_SPENT_LIMIT) ?? '';
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
        foreach (['preparer' => ['preparer_ids', 'preparer_id'], 'reviewer' => ['reviewer_ids', 'reviewer_id']] as $role => [$listKey, $singleKey]) {
            $ids = TaskRules::people($data, $listKey, $singleKey);
            if ($ids === null) {
                continue;
            }
            foreach ($ids as $userId) {
                if ($this->members->find($userId) === null) {
                    throw new TaskInvalid('The preparer and reviewer must be members of Tri-Task');
                }
            }
            $out[$role . '_user_ids'] = $ids;
            // Only the single key was sent: a client from before the lists. Whether it changes anything is for the task to say.
            if (!array_key_exists($listKey, $data)) {
                $out[$role . '_single_only'] = true;
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

    /**
     * A client from before the lists sends the one person it knows (`preparer_id`). On a task that has several, the
     * same first person sent back unchanged must not drop the rest: if that person is the task's first the people stay
     * as they are; a different person, or none, replaces them (the old behaviour).
     *
     * @param array<string, mixed> $fields cleaned
     * @return array<string, mixed>
     */
    private function keepPeopleOlderClientsCannotName(TaskRecord $task, array $fields): array
    {
        foreach (['preparer' => $task->preparerUserIds, 'reviewer' => $task->reviewerUserIds] as $role => $current) {
            $single = $role . '_single_only';
            $list = $role . '_user_ids';
            if (($fields[$single] ?? false) === true && isset($fields[$list]) && is_array($fields[$list]) && $fields[$list] !== []
                && $fields[$list][0] === ($current[0] ?? null)) {
                unset($fields[$list]);
            }
            unset($fields[$single]);
        }

        return $fields;
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
    private function dict(TaskRecord $task, ?TaskActor $actor = null): array
    {
        return $this->dicts([$task], $actor)[0];
    }

    /**
     * @param list<TaskRecord> $rows
     * @param ?TaskActor $actor whose own Unread, Flag and Pin each row carries
     * @return list<array<string, mixed>>
     */
    private function dicts(array $rows, ?TaskActor $actor = null): array
    {
        $states = $actor === null ? [] : $this->tasks->userStates($actor->userId);
        $cutoff = $actor === null ? null : $this->tasks->unreadCutoff();
        $dependencies = $this->tasks->dependencyMap();
        $projects = $this->setup->projectNames();
        $invites = $this->invites?->all() ?? [];
        $today = $this->time->today();
        $fileCounts = $this->files?->counts() ?? [];
        $out = [];
        foreach ($rows as $task) {
            $out[] = TaskPresenter::task(
                $task,
                $dependencies,
                $projects,
                $today,
                $actor === null ? null : TaskPresenter::mine($task, $states[$task->id] ?? null, $actor->userId, $cutoff),
                TaskPresenter::calendarInvite($invites[$task->id] ?? null, $task->dueDate),
            ) + ['file_count' => $fileCounts[$task->id] ?? 0];
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

    /**
     * @param array<string, mixed> $fields
     * @return list<int>
     */
    private static function ids(array $fields, string $key): array
    {
        return self::intList($fields[$key] ?? []);
    }

    /** @return list<int> */
    private static function intList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_int')) : [];
    }

    /** @param array<string, mixed> $fields */
    private static function int(array $fields, string $key): int
    {
        $value = $fields[$key] ?? 0;

        return is_int($value) ? $value : 0;
    }
}
