<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2Supplier;

/** `finance.ap2_suppliers`. */
interface Ap2SupplierStore
{
    /** @return list<Ap2Supplier> by name, then id */
    public function all(): array;

    public function find(string $id): ?Ap2Supplier;

    public function insert(Ap2Supplier $supplier): void;

    public function update(Ap2Supplier $supplier): void;
}
