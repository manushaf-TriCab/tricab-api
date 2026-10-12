<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/**
 * A reusable project (`finance.task_project_templates`). `groups` is the whole breakdown, stored as one JSON
 * document: a list of `{name, color, tasks: [{name, description, requirements, category, finance_statement_area,
 * priority, preparer_id, reviewer_id, due_offset_days, due_date_requested}]}`, already validated by
 * `TaskBoardService`.
 */
final class TaskProjectTemplate
{
    /**
     * @param list<array<string, mixed>> $groups
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public ?string $color,
        public array $groups,
        public ?int $createdByUserId,
        public DateTimeImmutable $createdDate,
        public DateTimeImmutable $updatedDate,
    ) {
    }
}
