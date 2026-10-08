<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use TriCab\Core\Finance\Task\Contract\TaskMailLinks;
use TriCab\Core\Finance\Task\Domain\TaskMailLink;

/** The task-to-ledger links held in memory. */
final class InMemoryTaskMailLinks implements TaskMailLinks
{
    /** @var list<TaskMailLink> */
    private array $links = [];

    public function insert(TaskMailLink $link): void
    {
        $this->links[] = $link;
    }

    public function forTask(string $taskId): array
    {
        $out = array_values(array_filter($this->links, static fn (TaskMailLink $l): bool => $l->taskId === $taskId));
        usort($out, static fn (TaskMailLink $a, TaskMailLink $b): int => [$b->createdAt->format('Y-m-d H:i:s.u'), $a->id] <=> [$a->createdAt->format('Y-m-d H:i:s.u'), $b->id]);

        return $out;
    }

    /** @return list<TaskMailLink> every link, for a test */
    public function all(): array
    {
        return $this->links;
    }
}
