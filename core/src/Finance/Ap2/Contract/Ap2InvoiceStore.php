<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

use TriCab\Core\Finance\Ap2\Domain\Ap2Invoice;
use TriCab\Core\Finance\Ap2\Domain\Ap2PoForecastRow;

/** `finance.ap2_invoices`, plus the two things the rules read from beside it (active payments, PO estimates). */
interface Ap2InvoiceStore
{
    /** @return list<Ap2Invoice> by due date, then number, then id */
    public function all(): array;

    /** @return list<Ap2Invoice> the supplier's invoices, in the same order */
    public function forSupplier(string $supplierId): array;

    public function find(string $id): ?Ap2Invoice;

    public function insert(Ap2Invoice $invoice): void;

    public function update(Ap2Invoice $invoice): void;

    /** How many invoices were created in `[$from, $toExclusive)` (UTC instants). */
    public function countCreated(\DateTimeImmutable $from, \DateTimeImmutable $toExclusive): int;

    /** Is a payment proposal for this invoice still live (not Cancelled, Rejected or Paid)? */
    public function hasActivePayment(string $invoiceId): bool;

    /**
     * @return array<string, true> the ids of every invoice with a live payment proposal
     */
    public function invoiceIdsWithActivePayment(): array;

    /** @return list<Ap2PoForecastRow> */
    public function poForecast(): array;
}
