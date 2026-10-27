<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Scheduler;

final class FileExpenseSchedulerRunStore implements ExpenseSchedulerRunStore
{
    public function __construct(private readonly string $directory)
    {
    }

    public function record(string $job, array $record): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Scheduler state directory is not writable.');
        }
        $path = $this->directory . '/last-run-' . $job . '.json';
        $tmp = $path . '.tmp';
        file_put_contents($tmp, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($tmp, $path);
    }

    public function last(string $job): ?array
    {
        $path = $this->directory . '/last-run-' . $job . '.json';
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
