<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Task\Domain\TaskHistoryRow;
use TriCab\Core\Finance\Task\Domain\TaskRecord;
use TriCab\Core\Finance\Task\Domain\TaskUserState;

/**
 * Storage for tasks, their dependencies and their history (T2.4). The rules (who may see or
 * change what, what a status change writes, what blocks it) are `TaskService`'s, so both
 * adapters behave the same; the store keeps rows and runs the use case's work in one
 * transaction (`transactional`).
 *
 * Nothing here sorts on a long text column: tasks come by `updated_date` then `id`.
 */
interface TaskStore extends TaskTransactions
{
    /**
     * The live tasks, or the Bin, newest change first.
     *
     * @param ?int $visibleToUserId null means every task; otherwise only tasks that person prepares or reviews
     * @return list<TaskRecord>
     */
    public function tasks(bool $deleted, ?int $visibleToUserId): array;

    public function findTask(string $id): ?TaskRecord;

    /**
     * The name, project and entity of every live task, whoever may see it: the import uses it to avoid
     * loading a task a second time, and a task someone else holds is still a duplicate.
     *
     * @return list<array{name: string, project_id: ?string, entity_id: ?string}>
     */
    public function liveTaskIdentities(): array;

    /**
     * @param list<string> $ids
     * @return list<TaskRecord> those that exist, live or binned
     */
    public function tasksByIds(array $ids): array;

    /**
     * Every live task that is not completed and is due on or before `$today` (`yyyy-MM-dd`): overdue and due today.
     * No cap, whoever may see it: the daily summary is the whole team's.
     *
     * @return list<TaskRecord> by due date, then name
     */
    public function dueOnOrBefore(string $today): array;

    public function insertTask(TaskRecord $task): void;

    public function updateTask(TaskRecord $task): void;

    /** Removes the task, its history and every dependency that names it, either way round. */
    public function deleteTaskPermanently(string $id): void;

    /**
     * Every dependency edge: a task id to the ids it waits for, including binned tasks' edges
     * (a cycle check still reads them).
     *
     * @return array<string, list<string>>
     */
    public function dependencyMap(): array;

    /**
     * Makes `$taskId` wait for exactly these tasks. The use case has already checked them.
     *
     * @param list<string> $dependencyIds
     */
    public function replaceDependencies(string $taskId, array $dependencyIds, DateTimeImmutable $now): void;

    /**
     * One person's marks on the tasks they have touched (read, unread, flag, pin): a task with no entry has none.
     * One query however many tasks, so a list costs nothing per row.
     *
     * @return array<string, TaskUserState> by task id
     */
    public function userStates(int $userId): array;

    /**
     * When the per-person unread state began: a task created after it is unread for everyone but its creator
     * until they open it, an older one counts as read. Null (no marker stored) means nothing is unread by default.
     */
    public function unreadCutoff(): ?DateTimeImmutable;

    /**
     * Sets this person's marks on one task; a null leaves that mark as it is. Marking read stamps `read_at` and
     * clears a "mark as unread"; marking unread sets that and clears `read_at`. A flag or pin already set keeps its
     * time, so pins keep their order.
     */
    public function saveUserState(int $userId, string $taskId, ?bool $read, ?bool $flagged, ?bool $pinned, DateTimeImmutable $now): void;

    public function addHistory(TaskHistoryRow $row): void;

    /** @return list<TaskHistoryRow> a task's changes, every field's, newest first */
    public function historyFor(string $taskId): array;

    /**
     * @param ?string $toStatus only changes into this status
     * @return list<TaskHistoryRow> every status change (no other field's), newest first; no limit
     */
    public function historyAll(?string $toStatus): array;
}
