<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use DateTimeImmutable;
use Throwable;
use TriCab\Core\Finance\Task\Contract\TaskSetupStore;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskEntity;
use TriCab\Core\Finance\Task\Domain\TaskProject;
use TriCab\Core\Finance\Task\Domain\TaskProjectGroup;
use TriCab\Core\Finance\Task\Domain\TaskRecord;
use TriCab\Core\Finance\Task\Domain\TaskStatementArea;
use TriCab\Core\Finance\Task\Domain\TaskTemplate;

/**
 * The four reference lists held in memory, with the same uniqueness as the filtered unique
 * indexes (decision Q4), the same ordering as the SQL, and a transaction that restores a
 * snapshot on failure. Records are copied on the way in and out, so a caller cannot change a
 * stored row without saving it.
 *
 * `InMemoryTaskStore` extends this with the tasks; until then `taskRows()` is empty, so every
 * count here is zero.
 */
class InMemoryTaskSetupStore implements TaskSetupStore
{
    /** @var array<string, TaskProject> */
    protected array $projects = [];

    /** @var array<string, TaskProjectGroup> */
    protected array $projectGroups = [];

    /** @var array<string, TaskTemplate> */
    protected array $templates = [];

    /** @var array<string, TaskStatementArea> */
    protected array $areas = [];

    /** @var array<string, TaskEntity> */
    protected array $entities = [];

    protected int $transactionDepth = 0;

    /** @return list<TaskRecord> every task, live or binned */
    protected function taskRows(): array
    {
        return [];
    }

    /** @param list<string> $ids tasks to remove for good, with whatever hangs off them */
    protected function forgetTasks(array $ids): void
    {
    }

    public function transactional(callable $work): mixed
    {
        if ($this->transactionDepth > 0) {
            return $work();
        }
        $snapshot = unserialize(serialize(get_object_vars($this)));
        $this->transactionDepth = 1;
        try {
            return $work();
        } catch (Throwable $failure) {
            if (is_array($snapshot)) {
                foreach ($snapshot as $property => $value) {
                    if (is_string($property)) {
                        $this->{$property} = $value;
                    }
                }
            }
            throw $failure;
        } finally {
            $this->transactionDepth = 0;
        }
    }

    // ---- projects ----------------------------------------------------------------------

    public function projectNames(): array
    {
        $names = [];
        foreach ($this->projects as $project) {
            $names[$project->id] = $project->name;
        }

        return $names;
    }

    public function projects(bool $deleted): array
    {
        $out = [];
        foreach ($this->projects as $project) {
            if (($project->deletedAt !== null) === $deleted) {
                $out[] = clone $project;
            }
        }
        usort($out, static fn (TaskProject $a, TaskProject $b): int => [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id]);

        return $out;
    }

    public function findProject(string $id): ?TaskProject
    {
        return isset($this->projects[$id]) ? clone $this->projects[$id] : null;
    }

    public function liveProjectNameTaken(string $name, ?string $exceptId): bool
    {
        foreach ($this->projects as $project) {
            if ($project->deletedAt === null && $project->id !== $exceptId && mb_strtolower($project->name) === mb_strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    public function insertProject(TaskProject $project): void
    {
        if ($project->deletedAt === null && $this->liveProjectNameTaken($project->name, $project->id)) {
            throw new TaskConflict('A project with that name already exists');
        }
        $this->projects[$project->id] = clone $project;
    }

    public function updateProject(TaskProject $project): void
    {
        if ($project->deletedAt === null && $this->liveProjectNameTaken($project->name, $project->id)) {
            throw new TaskConflict('A project with that name already exists');
        }
        $this->projects[$project->id] = clone $project;
    }

    public function deleteProject(string $id): void
    {
        unset($this->projects[$id]);
    }

    public function projectTaskCount(string $id): int
    {
        $n = 0;
        foreach ($this->taskRows() as $task) {
            $n += $task->projectId === $id ? 1 : 0;
        }

        return $n;
    }

    public function projectLiveTaskCount(string $id): int
    {
        $n = 0;
        foreach ($this->taskRows() as $task) {
            $n += $task->projectId === $id && $task->deletedAt === null ? 1 : 0;
        }

        return $n;
    }

    public function binProjectTasks(string $projectId, DateTimeImmutable $at, ?int $by): void
    {
        foreach ($this->taskRows() as $task) {
            if ($task->projectId === $projectId && $task->deletedAt === null) {
                $task->deletedAt = $at;
                $task->deletedBy = $by;
                $task->updatedDate = $at;
            }
        }
    }

    public function restoreProjectTasks(string $projectId, DateTimeImmutable $now): void
    {
        $binnedAt = $this->projects[$projectId]->deletedAt ?? null;
        if ($binnedAt === null) {
            return;
        }
        foreach ($this->taskRows() as $task) {
            if ($task->projectId === $projectId && $task->deletedAt !== null && $task->deletedAt == $binnedAt) {
                $task->deletedAt = null;
                $task->deletedBy = null;
                $task->updatedDate = $now;
            }
        }
    }

    public function deleteBinnedProjectTasks(string $projectId): void
    {
        $ids = [];
        foreach ($this->taskRows() as $task) {
            if ($task->projectId === $projectId && $task->deletedAt !== null) {
                $ids[] = $task->id;
            }
        }
        $this->forgetTasks($ids);
    }

    public function binnedProjectTaskCounts(): array
    {
        $counts = [];
        foreach ($this->taskRows() as $task) {
            $binnedAt = $task->projectId === null ? null : ($this->projects[$task->projectId]->deletedAt ?? null);
            if ($binnedAt !== null && $task->deletedAt !== null && $task->deletedAt == $binnedAt) {
                $counts[$task->projectId] = ($counts[$task->projectId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function projectTaskCounts(?int $visibleToUserId): array
    {
        $counts = [];
        foreach ($this->taskRows() as $task) {
            if ($task->projectId === null || $task->deletedAt !== null) {
                continue;
            }
            if ($visibleToUserId !== null && !$task->isOwnedBy($visibleToUserId)) {
                continue;
            }
            $counts[$task->projectId] ??= [0, 0];
            ++$counts[$task->projectId][0];
            if ($task->status === 'completed') {
                ++$counts[$task->projectId][1];
            }
        }

        return $counts;
    }

    // ---- project groups (Nick Han, 19 Oct 2026) ----------------------------------------

    public function projectGroups(): array
    {
        $out = array_values($this->projectGroups);
        usort($out, static fn (TaskProjectGroup $a, TaskProjectGroup $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]);

        return array_map(static fn (TaskProjectGroup $g): TaskProjectGroup => clone $g, $out);
    }

    public function findProjectGroup(string $id): ?TaskProjectGroup
    {
        return isset($this->projectGroups[$id]) ? clone $this->projectGroups[$id] : null;
    }

    public function insertProjectGroup(TaskProjectGroup $group): void
    {
        $this->projectGroups[$group->id] = clone $group;
    }

    public function updateProjectGroup(TaskProjectGroup $group): void
    {
        $this->projectGroups[$group->id] = clone $group;
    }

    public function deleteProjectGroup(string $id): void
    {
        unset($this->projectGroups[$id]);
    }

    // ---- templates ---------------------------------------------------------------------

    public function templates(): array
    {
        $out = array_map(static fn (TaskTemplate $t): TaskTemplate => clone $t, array_values($this->templates));
        usort($out, static fn (TaskTemplate $a, TaskTemplate $b): int => [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id]);

        return $out;
    }

    public function findTemplate(string $id): ?TaskTemplate
    {
        return isset($this->templates[$id]) ? clone $this->templates[$id] : null;
    }

    public function insertTemplate(TaskTemplate $template): void
    {
        $this->templates[$template->id] = clone $template;
    }

    public function updateTemplate(TaskTemplate $template): void
    {
        $this->templates[$template->id] = clone $template;
    }

    public function deleteTemplate(string $id): void
    {
        unset($this->templates[$id]);
    }

    // ---- statement areas ---------------------------------------------------------------

    public function areas(): array
    {
        $out = array_map(static fn (TaskStatementArea $a): TaskStatementArea => clone $a, array_values($this->areas));
        usort($out, static fn (TaskStatementArea $a, TaskStatementArea $b): int => [$a->sortOrder, mb_strtolower($a->name), $a->id] <=> [$b->sortOrder, mb_strtolower($b->name), $b->id]);

        return $out;
    }

    public function findArea(string $id): ?TaskStatementArea
    {
        return isset($this->areas[$id]) ? clone $this->areas[$id] : null;
    }

    public function areaNameTaken(string $name, ?string $exceptId): bool
    {
        foreach ($this->areas as $area) {
            if ($area->id !== $exceptId && mb_strtolower($area->name) === mb_strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    public function insertArea(TaskStatementArea $area): void
    {
        if ($this->areaNameTaken($area->name, $area->id)) {
            throw new TaskConflict('A statement area with that name already exists');
        }
        $this->areas[$area->id] = clone $area;
    }

    public function updateArea(TaskStatementArea $area): void
    {
        if ($this->areaNameTaken($area->name, $area->id)) {
            throw new TaskConflict('A statement area with that name already exists');
        }
        $this->areas[$area->id] = clone $area;
    }

    public function deleteArea(string $id): void
    {
        unset($this->areas[$id]);
    }

    public function areaLiveTaskCount(string $name): int
    {
        $n = 0;
        foreach ($this->taskRows() as $task) {
            $n += ($task->deletedAt === null && mb_strtolower($task->financeStatementArea) === mb_strtolower($name)) ? 1 : 0;
        }

        return $n;
    }

    // ---- entities ----------------------------------------------------------------------

    public function entities(): array
    {
        $out = array_map(static fn (TaskEntity $e): TaskEntity => clone $e, array_values($this->entities));
        usort($out, static fn (TaskEntity $a, TaskEntity $b): int => [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id]);

        return $out;
    }

    public function findEntity(string $id): ?TaskEntity
    {
        return isset($this->entities[$id]) ? clone $this->entities[$id] : null;
    }

    public function entityNameTaken(string $name, ?string $exceptId): bool
    {
        foreach ($this->entities as $entity) {
            if ($entity->id !== $exceptId && mb_strtolower($entity->name) === mb_strtolower($name)) {
                return true;
            }
        }

        return false;
    }

    public function insertEntity(TaskEntity $entity): void
    {
        if ($this->entityNameTaken($entity->name, $entity->id)) {
            throw new TaskConflict('An entity with that name already exists');
        }
        $this->entities[$entity->id] = clone $entity;
    }

    public function updateEntity(TaskEntity $entity): void
    {
        if ($this->entityNameTaken($entity->name, $entity->id)) {
            throw new TaskConflict('An entity with that name already exists');
        }
        $this->entities[$entity->id] = clone $entity;
    }

    public function deleteEntity(string $id): void
    {
        unset($this->entities[$id]);
    }

    public function entityTaskCount(string $id): int
    {
        $n = 0;
        foreach ($this->taskRows() as $task) {
            $n += $task->entityId === $id ? 1 : 0;
        }

        return $n;
    }
}
