<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/**
 * One task (`finance.task_tasks`). The stored status is `in_progress` or `completed`;
 * `overdue` is derived from the due date, never stored (decision D17).
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
    ) {
    }

    /** The caller prepares or reviews this task (`_is_own`). */
    public function isOwnedBy(int $userId): bool
    {
        return $this->preparerUserId === $userId || $this->reviewerUserId === $userId;
    }
}
