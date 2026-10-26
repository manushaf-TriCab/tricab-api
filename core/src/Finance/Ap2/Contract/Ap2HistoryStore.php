<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;

/**
 * `finance.ap2_history`: the supplier activity ledger. APPEND ONLY. This interface has no update and no delete, and
 * must never gain one; the table refuses them too (a trigger in `create-finance-ap2-schema.sql`).
 */
interface Ap2HistoryStore
{
    public function append(Ap2HistoryEntry $entry): void;

    /**
     * A supplier's ledger, newest first. `$from` is inclusive and `$until` exclusive, both UTC instants (the
     * caller turns Sydney calendar days into them).
     *
     * @return list<Ap2HistoryEntry>
     */
    public function forSupplier(string $supplierId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): array;
}
