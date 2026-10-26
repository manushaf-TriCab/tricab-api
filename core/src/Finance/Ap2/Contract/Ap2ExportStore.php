<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2Export;

/** `finance.ap2_exports`. */
interface Ap2ExportStore
{
    /** The next `CSV-0001` style id. Call it inside the transaction that inserts the export. */
    public function nextId(): string;

    public function insert(Ap2Export $export): void;

    public function find(string $id): ?Ap2Export;

    /** @return list<Ap2Export> newest first */
    public function all(): array;
}
