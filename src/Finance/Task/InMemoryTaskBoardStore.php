<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use TriCab\Core\Finance\Task\Contract\TaskBoardStore;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskGroup;
use TriCab\Core\Finance\Task\Domain\TaskProjectTemplate;

/** Task groups and project templates held in memory, with the template name unique as the index (case-insensitive). */
final class InMemoryTaskBoardStore implements TaskBoardStore
{
    /** @var array<string, TaskGroup> */
    private array $groups = [];

    /** @var array<string, TaskProjectTemplate> */
    private array $templates = [];

    public function transactional(callable $work): mixed
    {
        return $work();
    }

    public function groupsFor(string $projectId): array
    {
        $out = array_values(array_filter($this->groups, static fn (TaskGroup $g): bool => $g->projectId === $projectId));
        usort($out, static fn (TaskGroup $a, TaskGroup $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]);

        return array_map(static fn (TaskGroup $g): TaskGroup => clone $g, $out);
    }

    public function findGroup(string $id): ?TaskGroup
    {
        return isset($this->groups[$id]) ? clone $this->groups[$id] : null;
    }

    public function insertGroup(TaskGroup $group): void
    {
        $this->groups[$group->id] = clone $group;
    }

    public function updateGroup(TaskGroup $group): void
    {
        $this->groups[$group->id] = clone $group;
    }

    public function deleteGroup(string $id): void
    {
        unset($this->groups[$id]);
    }

    public function projectTemplates(): array
    {
        $out = array_values($this->templates);
        usort($out, static fn (TaskProjectTemplate $a, TaskProjectTemplate $b): int => [mb_strtolower($a->name), $a->id] <=> [mb_strtolower($b->name), $b->id]);

        return array_map(static fn (TaskProjectTemplate $t): TaskProjectTemplate => clone $t, $out);
    }

    public function findProjectTemplate(string $id): ?TaskProjectTemplate
    {
        return isset($this->templates[$id]) ? clone $this->templates[$id] : null;
    }

    public function insertProjectTemplate(TaskProjectTemplate $template): void
    {
        $this->refuseTakenName($template);
        $this->templates[$template->id] = clone $template;
    }

    public function updateProjectTemplate(TaskProjectTemplate $template): void
    {
        $this->refuseTakenName($template);
        $this->templates[$template->id] = clone $template;
    }

    public function deleteProjectTemplate(string $id): void
    {
        unset($this->templates[$id]);
    }

    private function refuseTakenName(TaskProjectTemplate $template): void
    {
        foreach ($this->templates as $other) {
            if ($other->id !== $template->id && mb_strtolower($other->name) === mb_strtolower($template->name)) {
                throw new TaskConflict('A project template with that name already exists');
            }
        }
    }
}
