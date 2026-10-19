<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/**
 * A group of projects on the Projects page (`finance.task_project_groups`), in `sortOrder` (Nick Han, 19 Oct 2026:
 * "Add Group" for projects as well as tasks).
 */
final class TaskProjectGroup
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $color,
        public int $sortOrder,
        public DateTimeImmutable $createdDate,
        public DateTimeImmutable $updatedDate,
    ) {
    }
}
