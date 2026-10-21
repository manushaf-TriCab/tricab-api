<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/**
 * One change to a task (`finance.task_history`), written with the change. A status change keeps its statuses in
 * `fromStatus` and `toStatus` and has no `field`; a change to any other field (Nick Han, 21 Oct 2026: "log all the
 * changes done to the task") names it in `field`, with the values before and after as text: none for long text.
 */
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
        public ?string $field = null,
        public ?string $fromValue = null,
        public ?string $toValue = null,
    ) {
    }
}
