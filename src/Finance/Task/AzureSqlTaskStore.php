<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\TaskHistoryRow;
use TriCab\Core\Finance\Task\Domain\TaskRecord;
use TriCab\Core\Finance\Task\Domain\TaskUserState;

/**
 * Tasks, dependencies and history in `finance.task_tasks`, `task_dependencies` and `task_history`
 * (`create-finance-task-schema.sql`), and the people on each in `task_assignees` (`alter-finance-task-assignees.sql`: the
 * source of truth for who prepares and reviews). The old single columns are written too, with the first of each list, and
 * read when a task has no link rows (a row an older API wrote). Lists sort on `updated_date` then `id`, never on a long text
 * column. A permanent delete removes the children first (the foreign keys have no cascade).
 */
final class AzureSqlTaskStore extends AzureSqlTaskSupport implements TaskStore
{
    private const COLUMNS = '[id], [name], [description], [requirements], [notes], [category], [finance_statement_area], [entity_id], [project_id],
        [preparer_user_id], [reviewer_user_id], [status], [progress], [due_date], [due_date_requested], [urgent], [completed_at],
        [created_by_user_id], [deleted_at], [deleted_by], [created_date], [updated_date], [priority], [group_id], [sort_order], [time_spent]';

    private const IN_LIMIT = 1000;

    public function tasks(bool $deleted, ?int $visibleToUserId): array
    {
        $params = [];
        $visible = '';
        if ($visibleToUserId !== null) {
            // The link table is who is on the task; the old columns (the first of each list, written with it) also count,
            // for a row an older API wrote with no link rows.
            $visible = ' AND (EXISTS (SELECT 1 FROM [finance].[task_assignees] a WHERE a.[task_id] = [finance].[task_tasks].[id] AND a.[user_id] = :person)'
                . ' OR [preparer_user_id] = :preparer OR [reviewer_user_id] = :reviewer)';
            $params = ['person' => $visibleToUserId, 'preparer' => $visibleToUserId, 'reviewer' => $visibleToUserId];
        }
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [finance].[task_tasks] WHERE [deleted_at] IS ' . ($deleted ? 'NOT NULL' : 'NULL')
            . $visible . ' ORDER BY [updated_date] DESC, [id]',
            $params,
        );

        return $this->tasksFrom($rows);
    }

    public function findTask(string $id): ?TaskRecord
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[task_tasks] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : $this->tasksFrom($rows)[0];
    }

    public function liveTaskIdentities(): array
    {
        $out = [];
        foreach ($this->connection->fetchAll('SELECT [name], [project_id], [entity_id] FROM [finance].[task_tasks] WHERE [deleted_at] IS NULL') as $row) {
            $out[] = [
                'name' => self::text($row['name']),
                'project_id' => AzureSqlValueMapper::nullableString($row['project_id']),
                'entity_id' => AzureSqlValueMapper::nullableString($row['entity_id']),
            ];
        }

        return $out;
    }

    public function tasksByIds(array $ids): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), self::IN_LIMIT) as $chunk) {
            $params = [];
            $marks = [];
            foreach ($chunk as $i => $id) {
                $params['id' . $i] = $id;
                $marks[] = ':id' . $i;
            }
            foreach ($this->tasksFrom($this->connection->fetchAll(
                'SELECT ' . self::COLUMNS . ' FROM [finance].[task_tasks] WHERE [id] IN (' . implode(', ', $marks) . ')',
                $params,
            )) as $task) {
                $out[] = $task;
            }
        }

        return $out;
    }

    public function dueOnOrBefore(string $today): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [finance].[task_tasks]
             WHERE [deleted_at] IS NULL AND [status] NOT IN (N\'completed\', N\'on_hold\') AND [due_date] IS NOT NULL AND [due_date] <= :today
             ORDER BY [due_date], [name], [id]',
            ['today' => $today],
        );

        return $this->tasksFrom($rows);
    }

    public function insertTask(TaskRecord $task): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[task_tasks] (' . self::COLUMNS . ')
             VALUES (:id, :name, :description, :requirements, :notes, :category, :area, :entity, :project, :preparer, :reviewer, :status, :progress,
                     :due, :requested, :urgent, :completed, :createdBy, :deletedAt, :deletedBy, :created, :updated, :priority, :group, :sortOrder, :timeSpent)',
            self::params($task),
        );
        $this->replaceAssignees($task);
    }

    public function updateTask(TaskRecord $task): void
    {
        $this->connection->execute(
            'UPDATE [finance].[task_tasks] SET [name] = :name, [description] = :description, [requirements] = :requirements, [notes] = :notes,
                    [category] = :category, [finance_statement_area] = :area, [entity_id] = :entity, [project_id] = :project,
                    [preparer_user_id] = :preparer, [reviewer_user_id] = :reviewer, [status] = :status, [progress] = :progress,
                    [due_date] = :due, [due_date_requested] = :requested, [urgent] = :urgent, [completed_at] = :completed,
                    [created_by_user_id] = :createdBy, [deleted_at] = :deletedAt, [deleted_by] = :deletedBy,
                    [created_date] = :created, [updated_date] = :updated, [priority] = :priority, [group_id] = :group, [sort_order] = :sortOrder,
                    [time_spent] = :timeSpent
             WHERE [id] = :id',
            self::params($task),
        );
        $this->replaceAssignees($task);
    }

    public function deleteTaskPermanently(string $id): void
    {
        $this->connection->execute('DELETE FROM [finance].[task_user_state] WHERE [task_id] = :id', ['id' => $id]);
        $this->connection->execute(
            'DELETE FROM [finance].[task_dependencies] WHERE [task_id] = :task OR [depends_on_task_id] = :dependsOn',
            ['task' => $id, 'dependsOn' => $id],
        );
        $this->connection->execute('DELETE FROM [finance].[task_history] WHERE [task_id] = :id', ['id' => $id]);
        $this->connection->execute('DELETE FROM [finance].[task_assignees] WHERE [task_id] = :id', ['id' => $id]);
        $this->connection->execute('DELETE FROM [finance].[task_tasks] WHERE [id] = :id', ['id' => $id]);
    }

    public function dependencyMap(): array
    {
        $map = [];
        foreach ($this->connection->fetchAll('SELECT [task_id], [depends_on_task_id] FROM [finance].[task_dependencies] ORDER BY [created_date], [id]') as $row) {
            $map[self::id($row['task_id'])][] = self::id($row['depends_on_task_id']);
        }

        return $map;
    }

    public function replaceDependencies(string $taskId, array $dependencyIds, DateTimeImmutable $now): void
    {
        $this->connection->execute('DELETE FROM [finance].[task_dependencies] WHERE [task_id] = :id', ['id' => $taskId]);
        foreach ($dependencyIds as $dependencyId) {
            $this->connection->execute(
                'INSERT INTO [finance].[task_dependencies] ([id], [task_id], [depends_on_task_id], [created_date]) VALUES (:id, :task, :dependsOn, :created)',
                ['id' => bin2hex(random_bytes(16)), 'task' => $taskId, 'dependsOn' => $dependencyId, 'created' => self::at($now)],
            );
        }
    }

    public function userStates(int $userId): array
    {
        $out = [];
        foreach ($this->connection->fetchAll(
            'SELECT [task_id], [read_at], [marked_unread], [flagged_at], [pinned_at] FROM [finance].[task_user_state] WHERE [user_id] = :user',
            ['user' => $userId],
        ) as $row) {
            $out[self::id($row['task_id'])] = new TaskUserState(
                self::whenOrNull($row['read_at']),
                (bool) $row['marked_unread'],
                self::whenOrNull($row['flagged_at']),
                self::whenOrNull($row['pinned_at']),
            );
        }

        return $out;
    }

    public function unreadCutoff(): ?DateTimeImmutable
    {
        $rows = $this->connection->fetchAll('SELECT [cutoff_at] FROM [finance].[task_unread_cutoff] WHERE [id] = 1');

        return $rows === [] ? null : self::whenOrNull($rows[0]['cutoff_at']);
    }

    public function saveUserState(int $userId, string $taskId, ?bool $read, ?bool $flagged, ?bool $pinned, DateTimeImmutable $now): void
    {
        // One MERGE that creates the row on the first mark and changes only the marks named.
        $at = self::at($now);
        $this->connection->execute(
            'MERGE [finance].[task_user_state] WITH (HOLDLOCK) AS t
             USING (SELECT :task AS [task_id], :user AS [user_id]) AS s ON t.[task_id] = s.[task_id] AND t.[user_id] = s.[user_id]
             WHEN MATCHED THEN UPDATE SET
                 [read_at] = CASE WHEN :readA = 1 THEN CAST(:readAt AS DATETIME2(6)) WHEN :readB = 0 THEN NULL ELSE t.[read_at] END,
                 [marked_unread] = CASE WHEN :readC = 1 THEN 0 WHEN :readD = 0 THEN 1 ELSE t.[marked_unread] END,
                 [flagged_at] = CASE WHEN :flagA = 1 THEN COALESCE(t.[flagged_at], CAST(:flagAt AS DATETIME2(6))) WHEN :flagB = 0 THEN NULL ELSE t.[flagged_at] END,
                 [pinned_at] = CASE WHEN :pinA = 1 THEN COALESCE(t.[pinned_at], CAST(:pinAt AS DATETIME2(6))) WHEN :pinB = 0 THEN NULL ELSE t.[pinned_at] END
             WHEN NOT MATCHED THEN INSERT ([task_id], [user_id], [read_at], [marked_unread], [flagged_at], [pinned_at])
                 VALUES (s.[task_id], s.[user_id],
                     CASE WHEN :readE = 1 THEN CAST(:readAt2 AS DATETIME2(6)) ELSE NULL END,
                     CASE WHEN :readF = 0 THEN 1 ELSE 0 END,
                     CASE WHEN :flagC = 1 THEN CAST(:flagAt2 AS DATETIME2(6)) ELSE NULL END,
                     CASE WHEN :pinC = 1 THEN CAST(:pinAt2 AS DATETIME2(6)) ELSE NULL END);',
            [
                'task' => $taskId, 'user' => $userId,
                'readA' => self::bit($read), 'readB' => self::bit($read), 'readC' => self::bit($read), 'readD' => self::bit($read),
                'readE' => self::bit($read), 'readF' => self::bit($read), 'readAt' => $at, 'readAt2' => $at,
                'flagA' => self::bit($flagged), 'flagB' => self::bit($flagged), 'flagC' => self::bit($flagged), 'flagAt' => $at, 'flagAt2' => $at,
                'pinA' => self::bit($pinned), 'pinB' => self::bit($pinned), 'pinC' => self::bit($pinned), 'pinAt' => $at, 'pinAt2' => $at,
            ],
        );
    }

    /** A named parameter can be bound once: 1, 0, or null for "leave as it is". */
    private static function bit(?bool $value): ?int
    {
        return $value === null ? null : ($value ? 1 : 0);
    }

    public function addHistory(TaskHistoryRow $row): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[task_history] ([id], [task_id], [from_status], [to_status], [field], [from_value], [to_value], [changed_by_user_id], [changed_by_name], [changed_at], [created_date], [updated_date])
             VALUES (:id, :task, :from, :to, :field, :fromValue, :toValue, :by, :name, :at, :created, :updated)',
            [
                'id' => $row->id,
                'task' => $row->taskId,
                'from' => $row->fromStatus,
                'to' => $row->toStatus,
                'field' => $row->field,
                'fromValue' => $row->fromValue,
                'toValue' => $row->toValue,
                'by' => $row->changedByUserId,
                'name' => $row->changedByName,
                'at' => self::at($row->changedAt),
                'created' => self::at($row->changedAt),
                'updated' => self::at($row->changedAt),
            ],
        );
    }

    public function historyFor(string $taskId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [task_id], [from_status], [to_status], [field], [from_value], [to_value], [changed_by_user_id], [changed_by_name], [changed_at]
             FROM [finance].[task_history] WHERE [task_id] = :id ORDER BY [changed_at] DESC, [id]',
            ['id' => $taskId],
        );

        return array_map(self::history(...), $rows);
    }

    public function historyAll(?string $toStatus): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [task_id], [from_status], [to_status], [field], [from_value], [to_value], [changed_by_user_id], [changed_by_name], [changed_at]
             FROM [finance].[task_history] WHERE [field] IS NULL' . ($toStatus !== null ? ' AND [to_status] = :to' : '') . ' ORDER BY [changed_at] DESC, [id]',
            $toStatus !== null ? ['to' => $toStatus] : [],
        );

        return array_map(self::history(...), $rows);
    }

    /**
     * Makes the task's preparers and reviewers exactly its lists, in order. Only rows that differ are touched, so a
     * save that did not change the people writes nothing here.
     */
    private function replaceAssignees(TaskRecord $task): void
    {
        $now = $this->connection->fetchAll(
            'SELECT [role], [user_id], [sort_order] FROM [finance].[task_assignees] WHERE [task_id] = :id',
            ['id' => $task->id],
        );
        $have = [];
        foreach ($now as $row) {
            $have[self::text($row['role']) . ':' . AzureSqlValueMapper::intValue($row['user_id'])] = AzureSqlValueMapper::intValue($row['sort_order']);
        }
        $want = [];
        foreach (['preparer' => $task->preparerUserIds, 'reviewer' => $task->reviewerUserIds] as $role => $ids) {
            foreach ($ids as $order => $userId) {
                $want[$role . ':' . $userId] = [$role, $userId, $order];
            }
        }
        foreach ($have as $key => $order) {
            if (!isset($want[$key])) {
                [$role, $userId] = explode(':', $key);
                $this->connection->execute(
                    'DELETE FROM [finance].[task_assignees] WHERE [task_id] = :id AND [role] = :role AND [user_id] = :user',
                    ['id' => $task->id, 'role' => $role, 'user' => (int) $userId],
                );
            }
        }
        foreach ($want as $key => [$role, $userId, $order]) {
            if (!isset($have[$key])) {
                $this->connection->execute(
                    'INSERT INTO [finance].[task_assignees] ([task_id], [role], [user_id], [sort_order]) VALUES (:id, :role, :user, :sort)',
                    ['id' => $task->id, 'role' => $role, 'user' => $userId, 'sort' => $order],
                );
            } elseif ($have[$key] !== $order) {
                $this->connection->execute(
                    'UPDATE [finance].[task_assignees] SET [sort_order] = :sort WHERE [task_id] = :id AND [role] = :role AND [user_id] = :user',
                    ['id' => $task->id, 'role' => $role, 'user' => $userId, 'sort' => $order],
                );
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<TaskRecord>
     */
    private function tasksFrom(array $rows): array
    {
        $people = [];
        $ids = array_map(static fn (array $row): string => self::id($row['id']), $rows);
        foreach (array_chunk($ids, self::IN_LIMIT) as $chunk) {
            $params = [];
            $marks = [];
            foreach ($chunk as $i => $id) {
                $params['t' . $i] = $id;
                $marks[] = ':t' . $i;
            }
            foreach ($this->connection->fetchAll(
                'SELECT [task_id], [role], [user_id] FROM [finance].[task_assignees] WHERE [task_id] IN (' . implode(', ', $marks) . ') ORDER BY [sort_order], [user_id]',
                $params,
            ) as $row) {
                $people[self::id($row['task_id'])][self::text($row['role'])][] = AzureSqlValueMapper::intValue($row['user_id']);
            }
        }

        return array_map(static fn (array $row): TaskRecord => self::task($row, $people[self::id($row['id'])] ?? []), $rows);
    }

    /** @param array<string, mixed> $row */
    private static function history(array $row): TaskHistoryRow
    {
        return new TaskHistoryRow(
            self::id($row['id']),
            self::id($row['task_id']),
            self::textOrNull($row['from_status']),
            self::textOrNull($row['to_status']),
            AzureSqlValueMapper::nullableInt($row['changed_by_user_id']),
            self::textOrNull($row['changed_by_name']),
            self::when($row['changed_at'], 'changed_at'),
            self::textOrNull($row['field']),
            self::textOrNull($row['from_value']),
            self::textOrNull($row['to_value']),
        );
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, list<int>> $people the task's `preparer` and `reviewer` user ids; a role with none falls back to the old column
     */
    private static function task(array $row, array $people): TaskRecord
    {
        $preparer = AzureSqlValueMapper::nullableInt($row['preparer_user_id']);
        $reviewer = AzureSqlValueMapper::nullableInt($row['reviewer_user_id']);

        return new TaskRecord(
            self::id($row['id']),
            self::text($row['name']),
            self::text($row['description']),
            self::text($row['requirements']),
            self::text($row['notes']),
            self::text($row['category']),
            self::text($row['finance_statement_area']),
            AzureSqlValueMapper::nullableString($row['entity_id']),
            AzureSqlValueMapper::nullableString($row['project_id']),
            $preparer,
            $reviewer,
            self::text($row['status']),
            AzureSqlValueMapper::intValue($row['progress']),
            self::textOrNull($row['due_date']),
            AzureSqlValueMapper::boolValue($row['due_date_requested']),
            AzureSqlValueMapper::boolValue($row['urgent']),
            self::whenOrNull($row['completed_at']),
            AzureSqlValueMapper::nullableInt($row['created_by_user_id']),
            self::whenOrNull($row['deleted_at']),
            AzureSqlValueMapper::nullableInt($row['deleted_by']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
            self::textOrNull($row['priority']),
            AzureSqlValueMapper::nullableString($row['group_id']),
            AzureSqlValueMapper::nullableInt($row['sort_order']),
            $people['preparer'] ?? ($preparer === null ? [] : [$preparer]),
            $people['reviewer'] ?? ($reviewer === null ? [] : [$reviewer]),
            self::text($row['time_spent']),
        );
    }

    /** @return array<string, scalar|null> */
    private static function params(TaskRecord $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'requirements' => $t->requirements,
            'notes' => $t->notes,
            'category' => $t->category,
            'area' => $t->financeStatementArea,
            'entity' => $t->entityId,
            'project' => $t->projectId,
            'preparer' => $t->preparerUserId,
            'reviewer' => $t->reviewerUserId,
            'status' => $t->status,
            'progress' => $t->progress,
            'due' => $t->dueDate,
            'requested' => $t->dueDateRequested ? 1 : 0,
            'urgent' => $t->urgent ? 1 : 0,
            'completed' => self::atOrNull($t->completedAt),
            'createdBy' => $t->createdByUserId,
            'deletedAt' => self::atOrNull($t->deletedAt),
            'deletedBy' => $t->deletedBy,
            'created' => self::at($t->createdDate),
            'updated' => self::at($t->updatedDate),
            'priority' => $t->priority,
            'group' => $t->groupId,
            'sortOrder' => $t->sortOrder,
            'timeSpent' => $t->timeSpent === '' ? null : $t->timeSpent,
        ];
    }
}
