<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Scheduler;

final class InMemoryExpenseSchedulerLock implements ExpenseSchedulerLock
{
    /** @var array<string, true> */
    private array $held = [];

    public function acquire(string $job): ?callable
    {
        if (isset($this->held[$job])) {
            return null;
        }
        $this->held[$job] = true;

        return function () use ($job): void {
            unset($this->held[$job]);
        };
    }
}
