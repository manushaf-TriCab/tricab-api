<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

/** The person holds the route's permission but not the right to this record. */
final class TaskForbidden extends TaskFailure
{
    public function httpStatus(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'FINANCE_TASK_FORBIDDEN';
    }
}
