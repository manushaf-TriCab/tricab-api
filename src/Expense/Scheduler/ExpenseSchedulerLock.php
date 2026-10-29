<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Scheduler;

/** Run-lock so two instances (slot swap, manual + WebJob) never run the same job at once. */
interface ExpenseSchedulerLock
{
    /** @return ?callable():void a release callback, or null when another run holds the lock */
    public function acquire(string $job): ?callable;
}
