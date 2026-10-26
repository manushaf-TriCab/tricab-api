<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2SupplierStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;

/** The suppliers held in memory: the tests', and a database-less run's. */
final class InMemoryAp2SupplierStore implements Ap2SupplierStore
{
    /** @var array<string, Ap2Supplier> */
    private array $rows = [];

    public function all(): array
    {
        $rows = array_values($this->rows);
        usort($rows, static fn (Ap2Supplier $a, Ap2Supplier $b): int => [$a->name, $a->id] <=> [$b->name, $b->id]);

        return $rows;
    }

    public function find(string $id): ?Ap2Supplier
    {
        return $this->rows[$id] ?? null;
    }

    public function insert(Ap2Supplier $supplier): void
    {
        $this->rows[$supplier->id] = $supplier;
    }

    public function update(Ap2Supplier $supplier): void
    {
        $this->rows[$supplier->id] = $supplier;
    }
}
