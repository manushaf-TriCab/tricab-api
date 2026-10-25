<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use DateTimeImmutable;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Task\Contract\TaskSetupStore;
use TriCab\Core\Finance\Task\Domain\TaskEntity;
use TriCab\Core\Finance\Task\Domain\TaskProject;
use TriCab\Core\Finance\Task\Domain\TaskProjectGroup;
use TriCab\Core\Finance\Task\Domain\TaskStatementArea;
use TriCab\Core\Finance\Task\Domain\TaskTemplate;

/**
 * The four reference lists in `finance.task_projects`, `task_templates`, `task_statement_areas`
 * and `task_entities` (`create-finance-task-schema.sql`), and the project groups in
 * `task_project_groups` (`alter-finance-task-project-groups.sql`, 19 Oct 2026). The filtered unique indexes are the
 * last word on a name (decision Q4); a clash is a `TaskConflict`. Every list sorts on a bounded
 * column; no `ORDER BY` touches an `NVARCHAR(MAX)`.
 */
final class AzureSqlTaskSetupStore extends AzureSqlTaskSupport implements TaskSetupStore
{
    private const PROJECT_COLUMNS = '[id], [name], [description], [color], [status], [target_date], [deleted_at], [deleted_by], [created_date], [updated_date], [group_id]';
    private const PROJECT_GROUP_COLUMNS = '[id], [name], [color], [sort_order], [created_date], [updated_date]';
    private const TEMPLATE_COLUMNS = '[id], [name], [description], [requirements], [category], [finance_statement_area], [preparer_user_id], [reviewer_user_id], [default_progress], [due_date], [due_date_requested], [created_date], [updated_date]';
    private const AREA_COLUMNS = '[id], [name], [category], [description], [sort_order], [created_date], [updated_date]';
    private const ENTITY_COLUMNS = '[id], [name], [code], [description], [color], [is_active], [created_date], [updated_date]';

    // ---- projects ----------------------------------------------------------------------

    public function projectNames(): array
    {
        $names = [];
        foreach ($this->connection->fetchAll('SELECT [id], [name] FROM [finance].[task_projects]') as $row) {
            $names[self::id($row['id'])] = self::text($row['name']);
        }

        return $names;
    }

    public function projects(bool $deleted): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT ' . self::PROJECT_COLUMNS . ' FROM [finance].[task_projects] WHERE [deleted_at] IS '
            . ($deleted ? 'NOT NULL' : 'NULL') . ' ORDER BY [name], [id]',
        );

        return array_map(self::project(...), $rows);
    }

    public function findProject(string $id): ?TaskProject
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::PROJECT_COLUMNS . ' FROM [finance].[task_projects] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::project($rows[0]);
    }

    public function liveProjectNameTaken(string $name, ?string $exceptId): bool
    {
        $rows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [n] FROM [finance].[task_projects] WHERE [name] = :name AND [deleted_at] IS NULL AND [id] <> :except',
            ['name' => $name, 'except' => $exceptId ?? ''],
        );

        return AzureSqlValueMapper::intValue($rows[0]['n'] ?? 0) > 0;
    }

    public function insertProject(TaskProject $project): void
    {
        $this->write(
            'INSERT INTO [finance].[task_projects] (' . self::PROJECT_COLUMNS . ')
             VALUES (:id, :name, :description, :color, :status, :target, :deletedAt, :deletedBy, :created, :updated, :groupId)',
            self::projectParams($project),
            'A project with that name already exists',
        );
    }

    public function updateProject(TaskProject $project): void
    {
        $this->write(
            'UPDATE [finance].[task_projects] SET [name] = :name, [description] = :description, [color] = :color, [status] = :status,
                    [target_date] = :target, [deleted_at] = :deletedAt, [deleted_by] = :deletedBy, [created_date] = :created, [updated_date] = :updated,
                    [group_id] = :groupId
             WHERE [id] = :id',
            self::projectParams($project),
            'A project with that name already exists',
        );
    }

    public function deleteProject(string $id): void
    {
        // Its board groups go with it (alter-finance-task-board.sql); a project is deleted for good only once it has no tasks.
        $this->connection->execute('DELETE FROM [finance].[task_groups] WHERE [project_id] = :id', ['id' => $id]);
        $this->connection->execute('DELETE FROM [finance].[task_projects] WHERE [id] = :id', ['id' => $id]);
    }

    public function projectTaskCount(string $id): int
    {
        $rows = $this->connection->fetchAll('SELECT COUNT(*) AS [n] FROM [finance].[task_tasks] WHERE [project_id] = :id', ['id' => $id]);

        return AzureSqlValueMapper::intValue($rows[0]['n'] ?? 0);
    }

    public function projectLiveTaskCount(string $id): int
    {
        $rows = $this->connection->fetchAll('SELECT COUNT(*) AS [n] FROM [finance].[task_tasks] WHERE [project_id] = :id AND [deleted_at] IS NULL', ['id' => $id]);

        return AzureSqlValueMapper::intValue($rows[0]['n'] ?? 0);
    }

    public function binProjectTasks(string $projectId, DateTimeImmutable $at, ?int $by): void
    {
        $this->connection->execute(
            'UPDATE [finance].[task_tasks] SET [deleted_at] = :at, [deleted_by] = :by, [updated_date] = :updated
             WHERE [project_id] = :id AND [deleted_at] IS NULL',
            ['at' => self::at($at), 'by' => $by, 'updated' => self::at($at), 'id' => $projectId],
        );
    }

    public function restoreProjectTasks(string $projectId, DateTimeImmutable $now): void
    {
        $this->connection->execute(
            'UPDATE t SET t.[deleted_at] = NULL, t.[deleted_by] = NULL, t.[updated_date] = :now
             FROM [finance].[task_tasks] t INNER JOIN [finance].[task_projects] p ON p.[id] = t.[project_id]
             WHERE p.[id] = :id AND p.[deleted_at] IS NOT NULL AND t.[deleted_at] = p.[deleted_at]',
            ['now' => self::at($now), 'id' => $projectId],
        );
    }

    public function deleteBinnedProjectTasks(string $projectId): void
    {
        // PDO_SQLSRV binds every occurrence of a named parameter separately, so each one gets its own name.
        $binned = static fn (string $name): string => 'SELECT [id] FROM [finance].[task_tasks] WHERE [project_id] = :' . $name . ' AND [deleted_at] IS NOT NULL';
        $this->connection->execute(
            'DELETE FROM [finance].[task_dependencies] WHERE [task_id] IN (' . $binned('a') . ') OR [depends_on_task_id] IN (' . $binned('b') . ')',
            ['a' => $projectId, 'b' => $projectId],
        );
        $this->connection->execute('DELETE FROM [finance].[task_history] WHERE [task_id] IN (' . $binned('a') . ')', ['a' => $projectId]);
        $this->connection->execute('DELETE FROM [finance].[task_tasks] WHERE [project_id] = :project AND [deleted_at] IS NOT NULL', ['project' => $projectId]);
    }

    public function binnedProjectTaskCounts(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT t.[project_id] AS [project_id], COUNT(*) AS [n] FROM [finance].[task_tasks] t
             INNER JOIN [finance].[task_projects] p ON p.[id] = t.[project_id]
             WHERE p.[deleted_at] IS NOT NULL AND t.[deleted_at] = p.[deleted_at] GROUP BY t.[project_id]',
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[self::id($row['project_id'])] = AzureSqlValueMapper::intValue($row['n']);
        }

        return $counts;
    }

    public function projectTaskCounts(?int $visibleToUserId): array
    {
        $params = [];
        $visible = '';
        if ($visibleToUserId !== null) {
            $visible = ' AND ([preparer_user_id] = :preparer OR [reviewer_user_id] = :reviewer)';
            $params = ['preparer' => $visibleToUserId, 'reviewer' => $visibleToUserId];
        }
        $rows = $this->connection->fetchAll(
            'SELECT [project_id], [status], COUNT(*) AS [n] FROM [finance].[task_tasks]
             WHERE [project_id] IS NOT NULL AND [deleted_at] IS NULL' . $visible . ' GROUP BY [project_id], [status]',
            $params,
        );
        $counts = [];
        foreach ($rows as $row) {
            $project = self::id($row['project_id']);
            $n = AzureSqlValueMapper::intValue($row['n']);
            $counts[$project] ??= [0, 0];
            $counts[$project][0] += $n;
            if (self::text($row['status']) === 'completed') {
                $counts[$project][1] += $n;
            }
        }

        return $counts;
    }

    // ---- project groups (Nick Han, 19 Oct 2026) ----------------------------------------

    public function projectGroups(): array
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::PROJECT_GROUP_COLUMNS . ' FROM [finance].[task_project_groups] ORDER BY [sort_order], [id]');

        return array_map(self::projectGroup(...), $rows);
    }

    public function findProjectGroup(string $id): ?TaskProjectGroup
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::PROJECT_GROUP_COLUMNS . ' FROM [finance].[task_project_groups] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::projectGroup($rows[0]);
    }

    public function insertProjectGroup(TaskProjectGroup $group): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[task_project_groups] (' . self::PROJECT_GROUP_COLUMNS . ') VALUES (:id, :name, :color, :sort, :created, :updated)',
            self::projectGroupParams($group),
        );
    }

    public function updateProjectGroup(TaskProjectGroup $group): void
    {
        $this->connection->execute(
            'UPDATE [finance].[task_project_groups] SET [name] = :name, [color] = :color, [sort_order] = :sort,
                    [created_date] = :created, [updated_date] = :updated
             WHERE [id] = :id',
            self::projectGroupParams($group),
        );
    }

    public function deleteProjectGroup(string $id): void
    {
        $this->connection->execute('DELETE FROM [finance].[task_project_groups] WHERE [id] = :id', ['id' => $id]);
    }

    // ---- templates ---------------------------------------------------------------------

    public function templates(): array
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::TEMPLATE_COLUMNS . ' FROM [finance].[task_templates] ORDER BY [name], [id]');

        return array_map(self::template(...), $rows);
    }

    public function findTemplate(string $id): ?TaskTemplate
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::TEMPLATE_COLUMNS . ' FROM [finance].[task_templates] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::template($rows[0]);
    }

    public function insertTemplate(TaskTemplate $template): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[task_templates] (' . self::TEMPLATE_COLUMNS . ')
             VALUES (:id, :name, :description, :requirements, :category, :area, :preparer, :reviewer, :progress, :due, :requested, :created, :updated)',
            self::templateParams($template),
        );
    }

    public function updateTemplate(TaskTemplate $template): void
    {
        $this->connection->execute(
            'UPDATE [finance].[task_templates] SET [name] = :name, [description] = :description, [requirements] = :requirements, [category] = :category,
                    [finance_statement_area] = :area, [preparer_user_id] = :preparer, [reviewer_user_id] = :reviewer, [default_progress] = :progress,
                    [due_date] = :due, [due_date_requested] = :requested, [created_date] = :created, [updated_date] = :updated
             WHERE [id] = :id',
            self::templateParams($template),
        );
    }

    public function deleteTemplate(string $id): void
    {
        $this->connection->execute('DELETE FROM [finance].[task_templates] WHERE [id] = :id', ['id' => $id]);
    }

    // ---- statement areas ---------------------------------------------------------------

    public function areas(): array
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::AREA_COLUMNS . ' FROM [finance].[task_statement_areas] ORDER BY [sort_order], [name], [id]');

        return array_map(self::area(...), $rows);
    }

    public function findArea(string $id): ?TaskStatementArea
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::AREA_COLUMNS . ' FROM [finance].[task_statement_areas] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::area($rows[0]);
    }

    public function areaNameTaken(string $name, ?string $exceptId): bool
    {
        $rows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [n] FROM [finance].[task_statement_areas] WHERE [name] = :name AND [id] <> :except',
            ['name' => $name, 'except' => $exceptId ?? ''],
        );

        return AzureSqlValueMapper::intValue($rows[0]['n'] ?? 0) > 0;
    }

    public function insertArea(TaskStatementArea $area): void
    {
        $this->write(
            'INSERT INTO [finance].[task_statement_areas] (' . self::AREA_COLUMNS . ') VALUES (:id, :name, :category, :description, :sort, :created, :updated)',
            self::areaParams($area),
            'A statement area with that name already exists',
        );
    }

    public function updateArea(TaskStatementArea $area): void
    {
        $this->write(
            'UPDATE [finance].[task_statement_areas] SET [name] = :name, [category] = :category, [description] = :description, [sort_order] = :sort,
                    [created_date] = :created, [updated_date] = :updated
             WHERE [id] = :id',
            self::areaParams($area),
            'A statement area with that name already exists',
        );
    }

    public function deleteArea(string $id): void
    {
        $this->connection->execute('DELETE FROM [finance].[task_statement_areas] WHERE [id] = :id', ['id' => $id]);
    }

    public function areaLiveTaskCount(string $name): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [n] FROM [finance].[task_tasks] WHERE [finance_statement_area] = :name AND [deleted_at] IS NULL',
            ['name' => $name],
        );

        return AzureSqlValueMapper::intValue($rows[0]['n'] ?? 0);
    }

    // ---- entities ----------------------------------------------------------------------

    public function entities(): array
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::ENTITY_COLUMNS . ' FROM [finance].[task_entities] ORDER BY [name], [id]');

        return array_map(self::entity(...), $rows);
    }

    public function findEntity(string $id): ?TaskEntity
    {
        $rows = $this->connection->fetchAll('SELECT ' . self::ENTITY_COLUMNS . ' FROM [finance].[task_entities] WHERE [id] = :id', ['id' => $id]);

        return $rows === [] ? null : self::entity($rows[0]);
    }

    public function entityNameTaken(string $name, ?string $exceptId): bool
    {
        $rows = $this->connection->fetchAll(
            'SELECT COUNT(*) AS [n] FROM [finance].[task_entities] WHERE [name] = :name AND [id] <> :except',
            ['name' => $name, 'except' => $exceptId ?? ''],
        );

        return AzureSqlValueMapper::intValue($rows[0]['n'] ?? 0) > 0;
    }

    public function insertEntity(TaskEntity $entity): void
    {
        $this->write(
            'INSERT INTO [finance].[task_entities] (' . self::ENTITY_COLUMNS . ') VALUES (:id, :name, :code, :description, :color, :active, :created, :updated)',
            self::entityParams($entity),
            'An entity with that name already exists',
        );
    }

    public function updateEntity(TaskEntity $entity): void
    {
        $this->write(
            'UPDATE [finance].[task_entities] SET [name] = :name, [code] = :code, [description] = :description, [color] = :color, [is_active] = :active,
                    [created_date] = :created, [updated_date] = :updated
             WHERE [id] = :id',
            self::entityParams($entity),
            'An entity with that name already exists',
        );
    }

    public function deleteEntity(string $id): void
    {
        $this->connection->execute('DELETE FROM [finance].[task_entities] WHERE [id] = :id', ['id' => $id]);
    }

    public function entityTaskCount(string $id): int
    {
        $rows = $this->connection->fetchAll('SELECT COUNT(*) AS [n] FROM [finance].[task_tasks] WHERE [entity_id] = :id', ['id' => $id]);

        return AzureSqlValueMapper::intValue($rows[0]['n'] ?? 0);
    }

    // ---- row mapping -------------------------------------------------------------------

    /** @param array<string, mixed> $row */
    private static function project(array $row): TaskProject
    {
        return new TaskProject(
            self::id($row['id']),
            self::text($row['name']),
            self::text($row['description']),
            self::text($row['color']),
            self::text($row['status']),
            self::dateText($row['target_date']),
            self::whenOrNull($row['deleted_at']),
            AzureSqlValueMapper::nullableInt($row['deleted_by']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
            AzureSqlValueMapper::nullableString($row['group_id']),
        );
    }

    /** @return array<string, scalar|null> */
    private static function projectParams(TaskProject $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'color' => $p->color,
            'status' => $p->status,
            'target' => $p->targetDate === '' ? null : $p->targetDate,
            'deletedAt' => self::atOrNull($p->deletedAt),
            'deletedBy' => $p->deletedBy,
            'created' => self::at($p->createdDate),
            'updated' => self::at($p->updatedDate),
            'groupId' => $p->groupId,
        ];
    }

    /** @param array<string, mixed> $row */
    private static function projectGroup(array $row): TaskProjectGroup
    {
        return new TaskProjectGroup(
            self::id($row['id']),
            self::text($row['name']),
            self::textOrNull($row['color']),
            AzureSqlValueMapper::intValue($row['sort_order']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
        );
    }

    /** @return array<string, scalar|null> */
    private static function projectGroupParams(TaskProjectGroup $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'color' => $g->color,
            'sort' => $g->sortOrder,
            'created' => self::at($g->createdDate),
            'updated' => self::at($g->updatedDate),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function template(array $row): TaskTemplate
    {
        return new TaskTemplate(
            self::id($row['id']),
            self::text($row['name']),
            self::text($row['description']),
            self::text($row['requirements']),
            self::text($row['category']),
            self::text($row['finance_statement_area']),
            AzureSqlValueMapper::nullableInt($row['preparer_user_id']),
            AzureSqlValueMapper::nullableInt($row['reviewer_user_id']),
            AzureSqlValueMapper::intValue($row['default_progress']),
            self::textOrNull($row['due_date']),
            AzureSqlValueMapper::boolValue($row['due_date_requested']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
        );
    }

    /** @return array<string, scalar|null> */
    private static function templateParams(TaskTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'requirements' => $t->requirements,
            'category' => $t->category,
            'area' => $t->financeStatementArea,
            'preparer' => $t->preparerUserId,
            'reviewer' => $t->reviewerUserId,
            'progress' => $t->defaultProgress,
            'due' => $t->dueDate,
            'requested' => $t->dueDateRequested ? 1 : 0,
            'created' => self::at($t->createdDate),
            'updated' => self::at($t->updatedDate),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function area(array $row): TaskStatementArea
    {
        return new TaskStatementArea(
            self::id($row['id']),
            self::text($row['name']),
            self::text($row['category']),
            self::text($row['description']),
            AzureSqlValueMapper::intValue($row['sort_order']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
        );
    }

    /** @return array<string, scalar|null> */
    private static function areaParams(TaskStatementArea $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'category' => $a->category,
            'description' => $a->description,
            'sort' => $a->sortOrder,
            'created' => self::at($a->createdDate),
            'updated' => self::at($a->updatedDate),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function entity(array $row): TaskEntity
    {
        return new TaskEntity(
            self::id($row['id']),
            self::text($row['name']),
            self::text($row['code']),
            self::text($row['description']),
            self::text($row['color']),
            AzureSqlValueMapper::boolValue($row['is_active']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
        );
    }

    /** @return array<string, scalar|null> */
    private static function entityParams(TaskEntity $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'code' => $e->code,
            'description' => $e->description,
            'color' => $e->color,
            'active' => $e->isActive ? 1 : 0,
            'created' => self::at($e->createdDate),
            'updated' => self::at($e->updatedDate),
        ];
    }
}
