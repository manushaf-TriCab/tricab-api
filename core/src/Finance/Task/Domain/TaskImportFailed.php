<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Domain;

/** The import file as a whole cannot be read (the source's `ImportFailed`, 400). Rows that fail are reported, not thrown. */
final class TaskImportFailed extends TaskFailure
{
    public function httpStatus(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'FINANCE_TASK_IMPORT_FAILED';
    }
}
