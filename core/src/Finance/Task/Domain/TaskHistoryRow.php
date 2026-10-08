<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/** One status change (`finance.task_history`), written with the change. */
final readonly class TaskHistoryRow
{
    public function __construct(
        public string $id,
        public string $taskId,
        public ?string $fromStatus,
        public ?string $toStatus,
        public ?int $changedByUserId,
        public ?string $changedByName,
        public DateTimeImmutable $changedAt,
    ) {
    }
}
