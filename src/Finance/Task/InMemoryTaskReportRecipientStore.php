<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use TriCab\Core\Finance\Task\Contract\TaskReportRecipientStore;
use TriCab\Core\Finance\Task\Domain\TaskConflict;
use TriCab\Core\Finance\Task\Domain\TaskReportRecipient;

/** The daily-summary recipients held in memory, with the same unique email as the index. */
final class InMemoryTaskReportRecipientStore implements TaskReportRecipientStore
{
    /** @var array<string, TaskReportRecipient> */
    private array $rows = [];

    public function all(): array
    {
        $out = array_values($this->rows);
        usort($out, static fn (TaskReportRecipient $a, TaskReportRecipient $b): int => [strtolower($a->email), $a->id] <=> [strtolower($b->email), $b->id]);

        return $out;
    }

    public function find(string $id): ?TaskReportRecipient
    {
        return $this->rows[$id] ?? null;
    }

    public function findByEmail(string $email): ?TaskReportRecipient
    {
        foreach ($this->rows as $row) {
            if (strtolower($row->email) === strtolower($email)) {
                return $row;
            }
        }

        return null;
    }

    public function insert(TaskReportRecipient $recipient): void
    {
        if ($this->findByEmail($recipient->email) !== null) {
            throw new TaskConflict('That address is already a recipient');
        }
        $this->rows[$recipient->id] = $recipient;
    }

    public function delete(string $id): void
    {
        unset($this->rows[$id]);
    }
}
