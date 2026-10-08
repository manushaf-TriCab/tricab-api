<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Task;

use DateTimeImmutable;
use TriCab\Core\Finance\Task\Contract\FinanceTaskMemberDirectory;
use TriCab\Core\Finance\Task\Domain\TaskMember;

/**
 * The member directory held in memory: nobody is a member until a test (or a local run
 * without a database) adds them. The default without SQL, so the pages see an empty team.
 */
final class InMemoryFinanceTaskMemberDirectory implements FinanceTaskMemberDirectory
{
    /** @var array<int, TaskMember> */
    private array $members = [];

    /** @var array<int, string> */
    private array $titles = [];

    public function add(int $userId, string $name, string $email, string $accessRole = TaskMember::ROLE_USER): void
    {
        $this->members[$userId] = new TaskMember($userId, $name, $email, $accessRole, $this->titles[$userId] ?? '');
    }

    public function remove(int $userId): void
    {
        unset($this->members[$userId]);
    }

    public function members(): array
    {
        $out = array_values(array_map(
            fn (TaskMember $m): TaskMember => new TaskMember($m->userId, $m->name, $m->email, $m->accessRole, $this->titles[$m->userId] ?? ''),
            $this->members,
        ));
        usort($out, static fn (TaskMember $a, TaskMember $b): int => [strtolower($a->name), $a->userId] <=> [strtolower($b->name), $b->userId]);

        return $out;
    }

    public function find(int $userId): ?TaskMember
    {
        foreach ($this->members() as $member) {
            if ($member->userId === $userId) {
                return $member;
            }
        }

        return null;
    }

    public function setJobTitle(int $userId, string $jobTitle, DateTimeImmutable $now): void
    {
        $this->titles[$userId] = $jobTitle;
    }
}
