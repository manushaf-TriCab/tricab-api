<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Contract;

use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskReportRecipient;

/** `finance.task_report_recipients`: who gets the daily summary. The email is unique. */
interface TaskReportRecipientStore
{
    /** @return list<TaskReportRecipient> by email */
    public function all(): array;

    public function find(string $id): ?TaskReportRecipient;

    public function findByEmail(string $email): ?TaskReportRecipient;

    /** @throws TaskConflict when the address is already a recipient (a race past the service's check) */
    public function insert(TaskReportRecipient $recipient): void;

    public function delete(string $id): void;
}
