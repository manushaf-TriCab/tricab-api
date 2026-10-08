<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

/** A value that cannot be used. The source's `Invalid` (400); the platform says 422, as RFQ does. */
final class TaskInvalid extends TaskFailure
{
    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'FINANCE_TASK_INPUT_INVALID';
    }
}
