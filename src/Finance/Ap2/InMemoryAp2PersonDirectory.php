<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2PersonDirectory;
use TriCab\Core\Finance\Ap2\Domain\Ap2Person;

/** Nobody until a test adds people; `grant` says which permissions a person holds. */
final class InMemoryAp2PersonDirectory implements Ap2PersonDirectory
{
    /** @var array<int, array{person: Ap2Person, permissions: list<string>}> */
    private array $people = [];

    /** @param list<string> $permissions full codes */
    public function add(Ap2Person $person, array $permissions): void
    {
        $this->people[$person->userId] = ['person' => $person, 'permissions' => $permissions];
    }

    public function find(int $userId): ?Ap2Person
    {
        return isset($this->people[$userId]) ? $this->people[$userId]['person'] : null;
    }

    public function holding(string $permission): array
    {
        $out = [];
        foreach ($this->people as $row) {
            if (in_array($permission, $row['permissions'], true)) {
                $out[] = $row['person'];
            }
        }
        usort($out, static fn (Ap2Person $a, Ap2Person $b): int => [$a->name, $a->userId] <=> [$b->name, $b->userId]);

        return $out;
    }
}
