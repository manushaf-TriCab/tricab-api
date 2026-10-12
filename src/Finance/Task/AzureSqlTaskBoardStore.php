<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use JsonException;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Finance\Task\Contract\TaskBoardStore;
use TriCab\Core\Finance\Task\Domain\TaskGroup;
use TriCab\Core\Finance\Task\Domain\TaskProjectTemplate;

/**
 * Task groups and project templates in `finance.task_groups` and `finance.task_project_templates`
 * (`alter-finance-task-board.sql`). A template's groups and tasks are one JSON document, read and written whole.
 */
final class AzureSqlTaskBoardStore extends AzureSqlTaskSupport implements TaskBoardStore
{
    private const NAME_TAKEN = 'A project template with that name already exists';

    public function groupsFor(string $projectId): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [project_id], [name], [color], [sort_order], [created_date], [updated_date]
             FROM [finance].[task_groups] WHERE [project_id] = :project ORDER BY [sort_order], [id]',
            ['project' => $projectId],
        );

        return array_map(self::group(...), $rows);
    }

    public function findGroup(string $id): ?TaskGroup
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [project_id], [name], [color], [sort_order], [created_date], [updated_date] FROM [finance].[task_groups] WHERE [id] = :id',
            ['id' => $id],
        );

        return $rows === [] ? null : self::group($rows[0]);
    }

    public function insertGroup(TaskGroup $group): void
    {
        $this->connection->execute(
            'INSERT INTO [finance].[task_groups] ([id], [project_id], [name], [color], [sort_order], [created_date], [updated_date])
             VALUES (:id, :project, :name, :color, :sort, :created, :updated)',
            self::groupParams($group),
        );
    }

    public function updateGroup(TaskGroup $group): void
    {
        $this->connection->execute(
            'UPDATE [finance].[task_groups] SET [project_id] = :project, [name] = :name, [color] = :color, [sort_order] = :sort,
                    [created_date] = :created, [updated_date] = :updated
             WHERE [id] = :id',
            self::groupParams($group),
        );
    }

    public function deleteGroup(string $id): void
    {
        $this->connection->execute('DELETE FROM [finance].[task_groups] WHERE [id] = :id', ['id' => $id]);
    }

    public function projectTemplates(): array
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [name], [description], [color], [structure], [created_by_user_id], [created_date], [updated_date]
             FROM [finance].[task_project_templates] ORDER BY [name], [id]',
        );

        return array_map(self::template(...), $rows);
    }

    public function findProjectTemplate(string $id): ?TaskProjectTemplate
    {
        $rows = $this->connection->fetchAll(
            'SELECT [id], [name], [description], [color], [structure], [created_by_user_id], [created_date], [updated_date]
             FROM [finance].[task_project_templates] WHERE [id] = :id',
            ['id' => $id],
        );

        return $rows === [] ? null : self::template($rows[0]);
    }

    public function insertProjectTemplate(TaskProjectTemplate $template): void
    {
        $this->write(
            'INSERT INTO [finance].[task_project_templates] ([id], [name], [description], [color], [structure], [created_by_user_id], [created_date], [updated_date])
             VALUES (:id, :name, :description, :color, :structure, :createdBy, :created, :updated)',
            self::templateParams($template),
            self::NAME_TAKEN,
        );
    }

    public function updateProjectTemplate(TaskProjectTemplate $template): void
    {
        $this->write(
            'UPDATE [finance].[task_project_templates] SET [name] = :name, [description] = :description, [color] = :color, [structure] = :structure,
                    [created_by_user_id] = :createdBy, [created_date] = :created, [updated_date] = :updated
             WHERE [id] = :id',
            self::templateParams($template),
            self::NAME_TAKEN,
        );
    }

    public function deleteProjectTemplate(string $id): void
    {
        $this->connection->execute('DELETE FROM [finance].[task_project_templates] WHERE [id] = :id', ['id' => $id]);
    }

    /** @param array<string, mixed> $row */
    private static function group(array $row): TaskGroup
    {
        return new TaskGroup(
            self::id($row['id']),
            self::id($row['project_id']),
            self::text($row['name']),
            self::textOrNull($row['color']),
            AzureSqlValueMapper::intValue($row['sort_order']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
        );
    }

    /** @return array<string, scalar|null> */
    private static function groupParams(TaskGroup $g): array
    {
        return [
            'id' => $g->id,
            'project' => $g->projectId,
            'name' => $g->name,
            'color' => $g->color,
            'sort' => $g->sortOrder,
            'created' => self::at($g->createdDate),
            'updated' => self::at($g->updatedDate),
        ];
    }

    /** @param array<string, mixed> $row */
    private static function template(array $row): TaskProjectTemplate
    {
        try {
            $groups = json_decode(self::text($row['structure']), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $groups = [];
        }
        /** @var list<array<string, mixed>> $list */
        $list = is_array($groups) && array_is_list($groups) ? array_values(array_filter($groups, 'is_array')) : [];

        return new TaskProjectTemplate(
            self::id($row['id']),
            self::text($row['name']),
            self::text($row['description']),
            self::textOrNull($row['color']),
            $list,
            AzureSqlValueMapper::nullableInt($row['created_by_user_id']),
            self::when($row['created_date'], 'created_date'),
            self::when($row['updated_date'], 'updated_date'),
        );
    }

    /** @return array<string, scalar|null> */
    private static function templateParams(TaskProjectTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'color' => $t->color,
            'structure' => json_encode($t->groups, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'createdBy' => $t->createdByUserId,
            'created' => self::at($t->createdDate),
            'updated' => self::at($t->updatedDate),
        ];
    }
}
