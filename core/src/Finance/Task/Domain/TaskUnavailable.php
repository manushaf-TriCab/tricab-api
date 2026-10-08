<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

/** Something Tri-Task needs is not set up (the source's 503: no sending mailbox is configured). */
final class TaskUnavailable extends TaskFailure
{
    public function httpStatus(): int
    {
        return 503;
    }

    public function errorCode(): string
    {
        return 'FINANCE_TASK_UNAVAILABLE';
    }
}
