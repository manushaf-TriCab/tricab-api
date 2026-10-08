<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

/** The request clashes with what is stored (a name taken, a task blocked, a record still in use). */
final class TaskConflict extends TaskFailure
{
    public function httpStatus(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'FINANCE_TASK_CONFLICT';
    }
}
