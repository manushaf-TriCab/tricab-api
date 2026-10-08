<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ar;

use TriCab\Core\Finance\Ar\Contract\ArAssigneeDirectory;
use TriCab\Core\Finance\Ar\Domain\ArAssignee;

/**
 * The assignee directory held in memory: nobody can be assigned to until a test (or a local run
 * without a database) adds them. The default without SQL, so the pages see an empty list.
 */
final class InMemoryArAssigneeDirectory implements ArAssigneeDirectory
{
    /** @var array<int, ArAssignee> */
    private array $people = [];

    public function add(int $userId, string $name, string $email): void
    {
        $this->people[$userId] = new ArAssignee($userId, $name, $email);
    }

    public function remove(int $userId): void
    {
        unset($this->people[$userId]);
    }

    public function assignees(): array
    {
        $out = array_values($this->people);
        usort($out, static fn (ArAssignee $a, ArAssignee $b): int => [mb_strtolower($a->name), $a->userId] <=> [mb_strtolower($b->name), $b->userId]);

        return $out;
    }

    public function find(int $userId): ?ArAssignee
    {
        return $this->people[$userId] ?? null;
    }
}
