<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/** A financial statement area (`finance.task_statement_areas`). */
final class TaskStatementArea
{
    public function __construct(
        public string $id,
        public string $name,
        public string $category,
        public string $description,
        public int $sortOrder,
        public DateTimeImmutable $createdDate,
        public DateTimeImmutable $updatedDate,
    ) {
    }
}
