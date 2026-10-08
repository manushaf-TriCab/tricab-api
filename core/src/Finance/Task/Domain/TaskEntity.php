<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/** An entity tasks are filed under (`finance.task_entities`). */
final class TaskEntity
{
    public function __construct(
        public string $id,
        public string $name,
        public string $code,
        public string $description,
        public string $color,
        public bool $isActive,
        public DateTimeImmutable $createdDate,
        public DateTimeImmutable $updatedDate,
    ) {
    }
}
