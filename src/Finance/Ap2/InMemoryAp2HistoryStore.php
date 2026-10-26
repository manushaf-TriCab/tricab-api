<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap2\Contract\Ap2HistoryStore;
use TriCab\Core\Finance\Ap2\Domain\Ap2HistoryEntry;

/** The ledger held in memory. Append only, like the table: there is no way to change or remove an entry. */
final class InMemoryAp2HistoryStore implements Ap2HistoryStore
{
    /** @var list<Ap2HistoryEntry> */
    private array $entries = [];

    public function append(Ap2HistoryEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function forSupplier(string $supplierId, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null): array
    {
        $rows = array_values(array_filter(
            $this->entries,
            static fn (Ap2HistoryEntry $e): bool => $e->supplierId === $supplierId
                && ($from === null || $e->at >= $from)
                && ($until === null || $e->at < $until),
        ));

        return array_reverse($rows);
    }

    /** @return list<Ap2HistoryEntry> every entry, oldest first: what a test checks. */
    public function all(): array
    {
        return $this->entries;
    }
}
