<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\TaskHistoryRow;
use TriCab\Core\Finance\Task\Domain\TaskRecord;

/**
 * Tasks, dependencies and history in `finance.task_tasks`, `task_dependencies` and `task_history`
 * (`create-finance-task-schema.sql`). Lists sort on `updated_date` then `id`, never on a long text
 * column. A permanent delete removes the children first (the foreign keys have no cascade).
 */
final class AzureSqlTaskStore extends AzureSqlTaskSupport implements TaskStore
{
    private const COLUMNS = '[id], [name], [description], [requirements], [notes], [category], [finance_statement_area], [entity_id], [project_id],
        [preparer_user_id], [reviewer_user_id], [status], [progress], [due_date], [due_date_requested], [urgent], [completed_at],
        [created_by_user_id], [deleted_at], [deleted_by], [created_date], [updated_date], [priority], [group_id], [sort_order]';

    private const IN_LIMIT = 1000;

    public function tasks(bool $deleted, ?int $visibleToUserId): array
    {
        $params = [];
        $visible = '';
        if ($visibleToUserId !== null) {
            $visible = ' AND ([preparer_user_id] = :preparer OR [reviewer_user_id] = :reviewer)';
            $params = ['preparer' => $visibleToUserId, 'reviewer' => $visibleToUserId];
        }
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::COLUMNS . ' FROM [finance].[task_tasks] WHERE [deleted_at] IS ' . ($deleted ? 'NOT NULL' : 'NULL')
            . $visible . ' ORDER BY [updated_date] DESC, [id]',
            $params,
        );

        return array_map(self::task(...), $rows);
    }

    public function findTask(string $id): ?TaskRecord
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::COLUMNS . ' FROM [finance].[task_tasks] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::task($rows[0]);
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
            foreach ($this->connection->fetchAll(
                'SELECT ' . self::COLUMNS . ' FROM [finance].[task_tasks] WHERE [id] IN (' . implode(', ', $marks) . ')',
                $params,
            ) as $row) {
                $out[] = self::task($row);
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

        return array_map(self::task(...), $rows);
    }

    public function insertTask(TaskRecord $task): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[task_tasks] (' . self::COLUMNS . ')
             VALUES (:id, :name, :description, :requirements, :notes, :category, :area, :entity, :project, :preparer, :reviewer, :status, :progress,
                     :due, :requested, :urgent, :completed, :createdBy, :deletedAt, :deletedBy, :created, :updated, :priority, :group, :sortOrder)',
            self::params($task),
        );
    }

    public function updateTask(TaskRecord $task): void
    {
        $this->connection->execute(
            'UPDATE [finance].[task_tasks] SET [name] = :name, [description] = :description, [requirements] = :requirements, [notes] = :notes,
                    [category] = :category, [finance_statement_area] = :area, [entity_id] = :entity, [project_id] = :project,
                    [preparer_user_id] = :preparer, [reviewer_user_id] = :reviewer, [status] = :status, [progress] = :progress,
                    [due_date] = :due, [due_date_requested] = :requested, [urgent] = :urgent, [completed_at] = :completed,
                    [created_by_user_id] = :createdBy, [deleted_at] = :deletedAt, [deleted_by] = :deletedBy,
                    [created_date] = :created, [updated_date] = :updated, [priority] = :priority, [group_id] = :group, [sort_order] = :sortOrder
             WHERE [id] = :id',
            self::params($task),
        );
    }

    public function deleteTaskPermanently(string $id): void
    {
        $this->connection->execute(
            'DELETE FROM [finance].[task_dependencies] WHERE [task_id] = :task OR [depends_on_task_id] = :dependsOn',
            ['task' => $id, 'dependsOn' => $id],
        );
        $this->connection->execute('DELETE FROM [finance].[task_history] WHERE [task_id] = :id', ['id' => $id]);
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

    public function addHistory(TaskHistoryRow $row): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[task_history] ([id], [task_id], [from_status], [to_status], [changed_by_user_id], [changed_by_name], [changed_at], [created_date], [updated_date])
             VALUES (:id, :task, :from, :to, :by, :name, :at, :created, :updated)',
            [
                'id' => $row->id,
                'task' => $row->taskId,
                'from' => $row->fromStatus,
                'to' => $row->toStatus,
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
            'SELECT [id], [task_id], [from_status], [to_status], [changed_by_user_id], [changed_by_name], [changed_at]
             FROM [finance].[task_history] WHERE [task_id] = :id ORDER BY [changed_at] DESC, [id]',
            ['id' => $taskId],
        );

        return array_map(self::history(...), $rows);
    }

    public function historyAll(?string $toStatus): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [task_id], [from_status], [to_status], [changed_by_user_id], [changed_by_name], [changed_at]
             FROM [finance].[task_history]' . ($toStatus !== null ? ' WHERE [to_status] = :to' : '') . ' ORDER BY [changed_at] DESC, [id]',
            $toStatus !== null ? ['to' => $toStatus] : [],
        );

        return array_map(self::history(...), $rows);
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
        );
    }

    /** @param array<string, mixed> $row */
    private static function task(array $row): TaskRecord
    {
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
            AzureSqlValueMapper::nullableInt($row['preparer_user_id']),
            AzureSqlValueMapper::nullableInt($row['reviewer_user_id']),
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
        ];
    }
}
