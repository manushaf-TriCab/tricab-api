<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/** A task template (`finance.task_templates`). Hard-deleted. */
final class TaskTemplate
{
    /**
     * @param ?list<int> $preparerUserIds
     * @param ?list<int> $reviewerUserIds
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $requirements,
        public string $category,
        public string $financeStatementArea,
        public ?int $preparerUserId,
        public ?int $reviewerUserId,
        public int $defaultProgress,
        public ?string $dueDate,
        public bool $dueDateRequested,
        public DateTimeImmutable $createdDate,
        public DateTimeImmutable $updatedDate,
        ?array $preparerUserIds = null,
        ?array $reviewerUserIds = null,
    ) {
        $this->setPreparers($preparerUserIds ?? ($preparerUserId === null ? [] : [$preparerUserId]));
        $this->setReviewers($reviewerUserIds ?? ($reviewerUserId === null ? [] : [$reviewerUserId]));
    }

    /** @var list<int> every preparer, in order; `preparerUserId` is the first */
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
}
