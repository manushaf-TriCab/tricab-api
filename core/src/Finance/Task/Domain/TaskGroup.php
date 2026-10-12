<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/** A group of tasks inside a project (`finance.task_groups`): one section of the project board, in `sortOrder`. */
final class TaskGroup
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $name,
        public ?string $color,
        public int $sortOrder,
        public DateTimeImmutable $createdDate,
        public DateTimeImmutable $updatedDate,
    ) {
    }
}
