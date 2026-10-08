<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/** A project, soft-deleted into the Bin (`finance.task_projects`). */
final class TaskProject
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public string $color,
        public string $status,
        public string $targetDate,
        public ?DateTimeImmutable $deletedAt,
        public ?int $deletedBy,
        public DateTimeImmutable $createdDate,
        public DateTimeImmutable $updatedDate,
    ) {
    }
}
