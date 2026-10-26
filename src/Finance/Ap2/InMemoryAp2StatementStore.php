<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2StatementStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Statement;

/** The statements held in memory. */
final class InMemoryAp2StatementStore implements Ap2StatementStore
{
    /** @var array<string, Ap2Statement> */
    private array $rows = [];

    public function all(): array
    {
        $rows = array_values($this->rows);
        usort($rows, static fn (Ap2Statement $a, Ap2Statement $b): int => [$b->date, $b->id] <=> [$a->date, $a->id]);

        return $rows;
    }

    public function find(string $id): ?Ap2Statement
    {
        return $this->rows[$id] ?? null;
    }

    public function insert(Ap2Statement $statement): void
    {
        $this->rows[$statement->id] = $statement;
    }

    public function update(Ap2Statement $statement): void
    {
        $this->rows[$statement->id] = $statement;
    }
}
