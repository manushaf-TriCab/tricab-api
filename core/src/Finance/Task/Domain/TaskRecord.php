<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/**
 * One task (`finance.task_tasks`). The stored status is one of `TaskRules::STATUSES`;
 * `overdue` is derived from the due date, never stored (decision D17). `priority` and `groupId`
 * came with the board (13 Oct 2026) and default to none, so older callers need not pass them. `sortOrder` is the
 * task's place in its group on the board (Nick Han, 20 Oct 2026: "can we move the task around by dragging"); none
 * until someone drags, and such tasks follow the ordered ones, oldest first. `timeSpent` is the Estimated Time, free
 * text as the person writes it (Nick Han, 26 Oct 2026: how long a task would take); empty for none. The column kept its
 * first name, `time_spent`, after the label changed.
 */
final class TaskRecord
{
    /**
     * `preparerUserId` / `reviewerUserId` are the first of each list; when a list is not passed it is that one person.
     *
     * @param ?list<int> $preparerUserIds
     * @param ?list<int> $reviewerUserIds
     */
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
        ?array $preparerUserIds = null,
        ?array $reviewerUserIds = null,
        public string $timeSpent = '',
    ) {
        $this->setPreparers($preparerUserIds ?? ($preparerUserId === null ? [] : [$preparerUserId]));
        $this->setReviewers($reviewerUserIds ?? ($reviewerUserId === null ? [] : [$reviewerUserId]));
    }

    /**
     * Every preparer and reviewer (Nick Han, 23 Oct 2026), in order. `preparerUserId` and `reviewerUserId` stay as the
     * first of each: what clients and columns before the lists read.
     *
     * @var list<int>
     */
    public array $preparerUserIds = [];

    /** @var list<int> */
    public array $reviewerUserIds = [];

    /** @param list<int> $ids */
    public function setPreparers(array $ids): void
    {
        $this->preparerUserIds = array_values(array_unique($ids));
        $this->preparerUserId = $this->preparerUserIds[0] ?? null;
    }

    /** @param list<int> $ids */
    public function setReviewers(array $ids): void
    {
        $this->reviewerUserIds = array_values(array_unique($ids));
        $this->reviewerUserId = $this->reviewerUserIds[0] ?? null;
    }

    /** The caller is one of the task's preparers or reviewers (`_is_own`). */
    public function isOwnedBy(int $userId): bool
    {
        return in_array($userId, $this->preparerUserIds, true) || in_array($userId, $this->reviewerUserIds, true);
    }

    /** @return list<int> every preparer and reviewer, each once */
    public function everyone(): array
    {
        return array_values(array_unique([...$this->preparerUserIds, ...$this->reviewerUserIds]));
    }
}
