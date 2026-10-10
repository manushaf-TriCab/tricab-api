<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use TriCab\Core\Finance\Ap\Contract\ApApproverDirectory;
use TriCab\Core\Finance\Ap\Domain\ApApprover;

/**
 * The approver directory held in memory: nobody can approve until a test (or a local run without a database) adds them. The
 * default without SQL, so the pages see an empty list. Ordered as the SQL directory is: display name, then id.
 */
final class InMemoryApApproverDirectory implements ApApproverDirectory
{
    /** @var array<int, ApApprover> */
    private array $people = [];

    public function add(int $userId, string $name, string $email): void
    {
        $this->people[$userId] = new ApApprover($userId, $name, strtolower($email));
    }

    public function remove(int $userId): void
    {
        unset($this->people[$userId]);
    }

    public function approvers(): array
    {
        $out = array_values($this->people);
        usort($out, static fn (ApApprover $a, ApApprover $b): int => [mb_strtolower($a->name), $a->userId] <=> [mb_strtolower($b->name), $b->userId]);

        return $out;
    }
}
