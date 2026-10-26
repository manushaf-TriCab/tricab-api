<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2Statement;

/** `finance.ap2_statements`. */
interface Ap2StatementStore
{
    /** @return list<Ap2Statement> newest statement date first */
    public function all(): array;

    public function find(string $id): ?Ap2Statement;

    public function insert(Ap2Statement $statement): void;

    public function update(Ap2Statement $statement): void;
}
