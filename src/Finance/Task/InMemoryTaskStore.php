<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use DateTimeImmutable;
use TriCab\Core\Finance\Task\Contract\TaskStore;
use TriCab\Core\Finance\Task\Domain\TaskHistoryRow;
use TriCab\Core\Finance\Task\Domain\TaskRecord;

/**
 * Tasks, dependencies and history held in memory, on top of the reference lists
 * ({@see InMemoryTaskSetupStore}), so the project, area and entity counts see the same tasks.
 * One object serves both contracts. The order is the SQL's: newest change first, then id.
 */
final class InMemoryTaskStore extends InMemoryTaskSetupStore implements TaskStore
{
    /** @var array<string, TaskRecord> */
    protected array $taskRecords = [];

    /** @var array<string, list<string>> task id to the ids it waits for */
    protected array $dependencies = [];

    /** @var list<TaskHistoryRow> */
    protected array $history = [];

    protected function taskRows(): array
    {
        return array_values($this->taskRecords);
    }

    public function tasks(bool $deleted, ?int $visibleToUserId): array
    {
        $out = [];
        foreach ($this->taskRecords as $task) {
            if (($task->deletedAt !== null) !== $deleted) {
                continue;
            }
            if ($visibleToUserId !== null && !$task->isOwnedBy($visibleToUserId)) {
                continue;
            }
            $out[] = clone $task;
        }
        usort($out, static fn (TaskRecord $a, TaskRecord $b): int => [$b->updatedDate->format('Y-m-d H:i:s.u'), $a->id] <=> [$a->updatedDate->format('Y-m-d H:i:s.u'), $b->id]);

        return $out;
    }

    public function findTask(string $id): ?TaskRecord
    {
        return isset($this->taskRecords[$id]) ? clone $this->taskRecords[$id] : null;
    }

    public function liveTaskIdentities(): array
    {
        $out = [];
        foreach ($this->taskRecords as $task) {
            if ($task->deletedAt === null) {
                $out[] = ['name' => $task->name, 'project_id' => $task->projectId, 'entity_id' => $task->entityId];
            }
        }

        return $out;
    }

    public function tasksByIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            if (isset($this->taskRecords[$id])) {
                $out[] = clone $this->taskRecords[$id];
            }
        }

        return $out;
    }

    public function dueOnOrBefore(string $today): array
    {
        $out = [];
        foreach ($this->taskRecords as $task) {
            if ($task->deletedAt === null && $task->status !== 'completed' && $task->dueDate !== null && $task->dueDate !== '' && $task->dueDate <= $today) {
                $out[] = clone $task;
            }
        }
        usort($out, static fn (TaskRecord $a, TaskRecord $b): int => [(string) $a->dueDate, mb_strtolower($a->name), $a->id] <=> [(string) $b->dueDate, mb_strtolower($b->name), $b->id]);

        return $out;
    }

    public function insertTask(TaskRecord $task): void
    {
        $this->taskRecords[$task->id] = clone $task;
    }

    public function updateTask(TaskRecord $task): void
    {
        $this->taskRecords[$task->id] = clone $task;
    }

    public function deleteTaskPermanently(string $id): void
    {
        unset($this->taskRecords[$id], $this->dependencies[$id]);
        foreach ($this->dependencies as $taskId => $waitsFor) {
            $kept = array_values(array_filter($waitsFor, static fn (string $dep): bool => $dep !== $id));
            if ($kept === []) {
                unset($this->dependencies[$taskId]);
            } else {
                $this->dependencies[$taskId] = $kept;
            }
        }
        $this->history = array_values(array_filter($this->history, static fn (TaskHistoryRow $row): bool => $row->taskId !== $id));
    }

    public function dependencyMap(): array
    {
        return $this->dependencies;
    }

    public function replaceDependencies(string $taskId, array $dependencyIds, DateTimeImmutable $now): void
    {
        if ($dependencyIds === []) {
            unset($this->dependencies[$taskId]);

            return;
        }
        $this->dependencies[$taskId] = $dependencyIds;
    }

    public function addHistory(TaskHistoryRow $row): void
    {
        $this->history[] = $row;
    }

    public function historyFor(string $taskId): array
    {
        return self::newestFirst(array_values(array_filter($this->history, static fn (TaskHistoryRow $row): bool => $row->taskId === $taskId)));
    }

    public function historyAll(?string $toStatus): array
    {
        return self::newestFirst(array_values(array_filter(
            $this->history,
            static fn (TaskHistoryRow $row): bool => $toStatus === null || $row->toStatus === $toStatus,
        )));
    }

    /**
     * @param list<TaskHistoryRow> $rows
     * @return list<TaskHistoryRow>
     */
    private static function newestFirst(array $rows): array
    {
        usort($rows, static fn (TaskHistoryRow $a, TaskHistoryRow $b): int => [$b->changedAt->format('Y-m-d H:i:s.u'), $a->id] <=> [$a->changedAt->format('Y-m-d H:i:s.u'), $b->id]);

        return $rows;
    }
}
