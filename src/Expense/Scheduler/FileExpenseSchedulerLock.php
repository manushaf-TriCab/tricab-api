<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Scheduler;

/** flock()-based lock; the OS drops it if the process dies, so it cannot go stale. */
final class FileExpenseSchedulerLock implements ExpenseSchedulerLock
{
    public function __construct(private readonly string $directory)
    {
    }

    public function acquire(string $job): ?callable
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Scheduler state directory is not writable.');
        }
        $handle = fopen($this->directory . '/' . $job . '.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open scheduler lock file.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return static function () use ($handle): void {
            flock($handle, LOCK_UN);
            fclose($handle);
        };
    }
}
