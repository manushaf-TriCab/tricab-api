<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Service;

use DateTimeImmutable;
use DateTimeZone;
use TriCab\Core\Finance\Task\Domain\TaskEntity;
use TriCab\Core\Finance\Task\Domain\TaskGroup;
use TriCab\Core\Finance\Task\Domain\TaskHistoryRow;
use TriCab\Core\Finance\Task\Domain\TaskProjectTemplate;
use TriCab\Core\Finance\Task\Domain\TaskProject;
use TriCab\Core\Finance\Task\Domain\TaskProjectGroup;
use TriCab\Core\Finance\Task\Domain\TaskRecord;
use TriCab\Core\Finance\Task\Domain\TaskStatementArea;
use TriCab\Core\Finance\Task\Domain\TaskTemplate;
use TriCab\Core\Finance\Task\Domain\TaskUserState;

/**
 * Every record as the source's `*_dict` functions shaped it, which is what the ported pages
 * read. Two deliberate differences, both for the platform's ids: a person id is **text**
 * (`"12"`, so the pages' strict comparisons between a task's `preparer_id` and a member's
 * `id` still hold), and a record id is 32 hex characters rather than a UUID.
 */
final class TaskPresenter
{
    /** A stored UTC timestamp as ISO 8601 with its offset (`+00:00`): JavaScript reads an offset-less one as local time. */
    public static function iso(?DateTimeImmutable $value): ?string
    {
        return $value?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.uP');
    }

    public static function person(?int $userId): ?string
    {
        return $userId === null ? null : (string) $userId;
    }

    /** `display_status`: completed, on_hold (even when past due), overdue (Active and due before today), or active. */
    public static function displayStatus(string $status, ?string $dueDate, string $today): string
    {
        $status = TaskRules::status($status);
        if ($status === 'completed' || $status === 'on_hold') {
            return $status;
        }
        if ($dueDate !== null && $dueDate !== '' && $dueDate < $today) {
            return 'overdue';
        }

        return $status;
    }

    /**
     * @param array{0?: int, 1?: int} $counts total and completed live tasks the caller may see
     * @param int $binnedTaskCount for a project in the Bin: the tasks that went there with it and come back with it
     * @return array<string, mixed>
     */
    public static function project(TaskProject $p, array $counts, int $binnedTaskCount = 0): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'description' => $p->description,
            'color' => $p->color,
            'status' => $p->status,
            'target_date' => $p->targetDate,
            'group_id' => $p->groupId,
            'task_count' => $counts[0] ?? 0,
            'completed_count' => $counts[1] ?? 0,
            'binned_task_count' => $binnedTaskCount,
            'is_deleted' => $p->deletedAt !== null,
            'deleted_at' => self::iso($p->deletedAt),
            'created_date' => self::iso($p->createdDate),
            'updated_date' => self::iso($p->updatedDate),
        ];
    }

    /** @return array<string, mixed> */
    public static function template(TaskTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'requirements' => $t->requirements,
            'category' => $t->category,
            'finance_statement_area' => $t->financeStatementArea,
            'preparer_id' => self::person($t->preparerUserId),
            'reviewer_id' => self::person($t->reviewerUserId),
            'progress' => $t->defaultProgress,
            'due_date' => $t->dueDate ?? '',
            'due_date_requested' => $t->dueDateRequested,
            'created_date' => self::iso($t->createdDate),
            'updated_date' => self::iso($t->updatedDate),
        ];
    }

    /** @return array<string, mixed> */
    public static function area(TaskStatementArea $a): array
    {
        return [
            'id' => $a->id,
            'name' => $a->name,
            'category' => $a->category,
            'description' => $a->description,
            'sort_order' => $a->sortOrder,
            'created_date' => self::iso($a->createdDate),
            'updated_date' => self::iso($a->updatedDate),
        ];
    }

    /** @return array<string, mixed> */
    public static function entity(TaskEntity $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'code' => $e->code,
            'description' => $e->description,
            'color' => $e->color,
            'is_active' => $e->isActive,
            'created_date' => self::iso($e->createdDate),
            'updated_date' => self::iso($e->updatedDate),
        ];
    }

    /**
     * @param array<string, list<string>> $dependencies task id to the ids it waits for
     * @param array<string, string> $projectNames project id to name
     * @param ?array{is_unread: bool, is_flagged: bool, pinned_at: ?string} $mine the signed-in person's own marks, when there is one
     * @return array<string, mixed>
     */
    public static function task(TaskRecord $t, array $dependencies, array $projectNames, string $today, ?array $mine = null): array
    {
        $project = $t->projectId !== null ? ($projectNames[$t->projectId] ?? '') : '';

        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'requirements' => $t->requirements,
            'notes' => $t->notes,
            'category' => $t->category,
            'finance_statement_area' => $t->financeStatementArea,
            'entity_id' => $t->entityId,
            'project_id' => $t->projectId,
            'project_name' => $project,
            // The pre-rewrite UI grouped by a project *name* string.
            'project' => $project,
            'preparer_id' => self::person($t->preparerUserId),
            'reviewer_id' => self::person($t->reviewerUserId),
            'status' => TaskRules::status($t->status),
            'display_status' => self::displayStatus($t->status, $t->dueDate, $today),
            'priority' => $t->priority,
            'group_id' => $t->groupId,
            'sort_order' => $t->sortOrder,
            'progress' => $t->progress,
            'due_date' => $t->dueDate ?? '',
            'due_date_requested' => $t->dueDateRequested,
            'urgent' => $t->urgent,
            'dependencies' => $dependencies[$t->id] ?? [],
            'completed_at' => self::iso($t->completedAt),
            'created_by_id' => self::person($t->createdByUserId),
            'is_deleted' => $t->deletedAt !== null,
            'deleted_at' => self::iso($t->deletedAt),
            'created_date' => self::iso($t->createdDate),
            'updated_date' => self::iso($t->updatedDate),
        ] + ($mine ?? []);
    }

    /**
     * The signed-in person's own Unread, Flag and Pin on a task (Nick Han, 23 Oct 2026), the same for a mailbox:
     * unread until they first open it, or after they mark it unread; a task created before `$cutoff` counts as
     * read, and a person's own new task is never unread to them.
     *
     * @return array{is_unread: bool, is_flagged: bool, pinned_at: ?string}
     */
    public static function mine(TaskRecord $t, ?TaskUserState $state, int $userId, ?DateTimeImmutable $cutoff): array
    {
        $unread = $state !== null && $state->markedUnread
            || ($state?->readAt === null && $cutoff !== null && $t->createdDate > $cutoff && $t->createdByUserId !== $userId);

        return [
            'is_unread' => $unread,
            'is_flagged' => $state?->flaggedAt !== null,
            'pinned_at' => self::iso($state?->pinnedAt),
        ];
    }

    /** @return array<string, mixed> */
    public static function group(TaskGroup $g): array
    {
        return [
            'id' => $g->id,
            'project_id' => $g->projectId,
            'name' => $g->name,
            'color' => $g->color,
            'sort_order' => $g->sortOrder,
            'created_date' => self::iso($g->createdDate),
            'updated_date' => self::iso($g->updatedDate),
        ];
    }

    /** @return array<string, mixed> */
    public static function projectGroup(TaskProjectGroup $g): array
    {
        return [
            'id' => $g->id,
            'name' => $g->name,
            'color' => $g->color,
            'sort_order' => $g->sortOrder,
            'created_date' => self::iso($g->createdDate),
            'updated_date' => self::iso($g->updatedDate),
        ];
    }

    /** @return array<string, mixed> */
    public static function projectTemplate(TaskProjectTemplate $t): array
    {
        $taskCount = 0;
        foreach ($t->groups as $group) {
            $taskCount += is_array($group['tasks'] ?? null) ? count($group['tasks']) : 0;
        }

        return [
            'id' => $t->id,
            'name' => $t->name,
            'description' => $t->description,
            'color' => $t->color,
            'groups' => $t->groups,
            'task_count' => $taskCount,
            'created_by_id' => self::person($t->createdByUserId),
            'created_date' => self::iso($t->createdDate),
            'updated_date' => self::iso($t->updatedDate),
        ];
    }

    /** @return array<string, mixed> */
    public static function history(TaskHistoryRow $h): array
    {
        return [
            'id' => $h->id,
            'task_id' => $h->taskId,
            'from_status' => $h->fromStatus,
            'to_status' => $h->toStatus,
            'field' => $h->field ?? 'status',
            'from_value' => $h->fromValue,
            'to_value' => $h->toValue,
            'changed_by' => $h->changedByName ?? '',
            'changed_by_user_id' => self::person($h->changedByUserId),
            'changed_at' => self::iso($h->changedAt),
            'created_date' => self::iso($h->changedAt),
        ];
    }
}
