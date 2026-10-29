<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Scheduler;

final class InMemoryExpenseSchedulerRunStore implements ExpenseSchedulerRunStore
{
    /** @var array<string, array<string, mixed>> */
    private array $rows = [];

    public function record(string $job, array $record): void
    {
        $this->rows[$job] = $record;
    }

    public function last(string $job): ?array
    {
        return $this->rows[$job] ?? null;
    }
}
