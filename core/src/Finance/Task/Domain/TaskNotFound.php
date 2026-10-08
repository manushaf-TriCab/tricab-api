<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

/** Not there, or not the caller's to see: a task someone else holds is a 404, never a 403. */
final class TaskNotFound extends TaskFailure
{
    public function httpStatus(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'FINANCE_TASK_NOT_FOUND';
    }
}
