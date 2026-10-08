<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/** A task template (`finance.task_templates`). Hard-deleted. */
final class TaskTemplate
{
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
    ) {
    }
}
