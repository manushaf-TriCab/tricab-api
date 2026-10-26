<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

/**
 * The Copilot's lookups over the AP2 stores. READ ONLY: no method here may change anything, and none may be added
 * that does. Each answers plain data the model reads (the contract's record shapes, trimmed), never throws for an
 * unknown id (it answers `found: false`), and is limited to what `FINANCE.AP2.INVOICES.READ` can see.
 */
interface Ap2CopilotTools
{
    /**
     * The supplier's invoices, excluded ones left out, each with its stage, amounts, balance and due date.
     *
     * @return array<string, mixed>
     */
    public function invoices(string $supplierId): array;

    /**
     * One invoice by id, or by number within a supplier: every field plus validation, matching, queue and payment
     * issues.
     *
     * @return array<string, mixed>
     */
    public function invoice(?string $invoiceId, ?string $supplierId, ?string $number): array;

    /**
     * The supplier's invoices that have validation or payment issues, with the issues.
     *
     * @return array<string, mixed>
     */
    public function exceptions(string $supplierId): array;

    /**
     * Name, terms, owner, holds and bank state, posted open AP, overdue AP (AUD) and invoice counts.
     *
     * @return array<string, mixed>
     */
    public function supplierSummary(string $supplierId): array;

    /**
     * The supplier's activity ledger, newest first, at most `$limit` entries.
     *
     * @return array<string, mixed>
     */
    public function history(string $supplierId, int $limit): array;

    /**
     * Suppliers whose name contains `$query` (to resolve "Cable Materials" to an id).
     *
     * @return array<string, mixed>
     */
    public function findSuppliers(string $query): array;
}
