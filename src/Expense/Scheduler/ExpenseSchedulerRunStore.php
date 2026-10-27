<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Scheduler;

/** Health / last-run record, one entry per job. Counts and names only, never mail bodies. */
interface ExpenseSchedulerRunStore
{
    /** @param array<string, mixed> $record */
    public function record(string $job, array $record): void;

    /** @return ?array<string, mixed> */
    public function last(string $job): ?array;
}
