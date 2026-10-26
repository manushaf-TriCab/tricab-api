<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2ExportStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2Export;

/** The exports held in memory. */
final class InMemoryAp2ExportStore implements Ap2ExportStore
{
    /** @var array<string, Ap2Export> */
    private array $rows = [];

    public function nextId(): string
    {
        return 'CSV-' . str_pad((string) (count($this->rows) + 1), 4, '0', STR_PAD_LEFT);
    }

    public function insert(Ap2Export $export): void
    {
        $this->rows[$export->id] = $export;
    }

    public function find(string $id): ?Ap2Export
    {
        return $this->rows[$id] ?? null;
    }

    public function all(): array
    {
        return array_reverse(array_values($this->rows));
    }
}
