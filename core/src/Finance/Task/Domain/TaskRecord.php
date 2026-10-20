<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/**
 * One task (`finance.task_tasks`). The stored status is one of `TaskRules::STATUSES`;
 * `overdue` is derived from the due date, never stored (decision D17). `priority` and `groupId`
 * came with the board (13 Oct 2026) and default to none, so older callers need not pass them. `sortOrder` is the
 * task's place in its group on the board (Nick Han, 20 Oct 2026: "can we move the task around by dragging"); none
 * until someone drags, and such tasks follow the ordered ones, oldest first.
 */
final class TaskRecord
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $requirements,
        public string $notes,
        public string $category,
        public string $financeStatementArea,
        public ?string $entityId,
        public ?string $projectId,
        public ?int $preparerUserId,
        public ?int $reviewerUserId,
        public string $status,
        public int $progress,
        public ?string $dueDate,
        public bool $dueDateRequested,
        public bool $urgent,
        public ?DateTimeImmutable $completedAt,
        public ?int $createdByUserId,
        public ?DateTimeImmutable $deletedAt,
        public ?int $deletedBy,
        public DateTimeImmutable $createdDate,
        public DateTimeImmutable $updatedDate,
        public ?string $priority = null,
        public ?string $groupId = null,
        public ?int $sortOrder = null,
    ) {
    }

    /** The caller prepares or reviews this task (`_is_own`). */
    public function isOwnedBy(int $userId): bool
    {
        return $this->preparerUserId === $userId || $this->reviewerUserId === $userId;
    }
}
