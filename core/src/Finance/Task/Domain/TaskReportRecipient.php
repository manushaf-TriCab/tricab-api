<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

use DateTimeImmutable;

/** Someone who gets the daily summary (`finance.task_report_recipients`). */
final readonly class TaskReportRecipient
{
    public function __construct(
        public string $id,
        public string $email,
        public string $name,
        public ?int $userId,
        public DateTimeImmutable $createdDate,
        public DateTimeImmutable $updatedDate,
    ) {
    }
}
