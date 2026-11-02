<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

/** `GET /reports/aged`: per-supplier ageing in AUD over Odoo's open payables, read live ({@see Ap2OdooLedgerService}). */
final class Ap2ReportService
{
    public function __construct(
        private readonly Ap2OdooLedgerService $ledger,
    ) {
    }

    /**
     * `overdue` is the part of `total` past due; `unallocated` the payments not matched to a bill (netted in);
     * `onHold` says the supplier should not be paid yet (a hold, or bank details unverified or changing). Rows with
     * `notBySupplier` are the lines Odoo holds without a supplier, one per entity.
     *
     * @return list<array<string, mixed>> suppliers by name, then the not-by-supplier rows; nothing open is left out
     */
    public function aged(bool $fresh = false): array
    {
        return $this->ledger->aged($fresh);
    }
}
