<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Contract;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Domain\ApVendor;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;

/**
 * What vendor matching needs from storage: the synced Odoo vendors, the invoice count per vendor, the
 * companies TriCab goes by, and the one write (an invoice's vendor columns). Storage only: every rule
 * is `ApVendorMatcher`'s and `ApVendorService`'s.
 */
interface ApVendorStore
{
    /**
     * The `vendors` list of `finance.odoo_records` that Odoo has not removed (deleted records are not
     * returned), archived vendors included and flagged `active = false`, ordered by name then Odoo id.
     * An empty list means nothing is synced, and the service answers "unavailable".
     *
     * @return list<ApVendor>
     */
    public function vendors(): array;

    /** When the vendor sync last succeeded (the source's `synced_at("vendors")`), or null when it has not. */
    public function vendorsSyncedAt(): ?DateTimeImmutable;

    /**
     * Live (not binned) invoices per matched Odoo vendor, for the Vendors page: `odoo vendor id => count`.
     * Vendors with none are left out.
     *
     * @return array<int, int>
     */
    public function invoiceCountsByVendor(): array;

    /**
     * The active companies, for the names TriCab itself goes by (`buyer_names`): `name`, and
     * `former_names` (names separated by semicolons) when the company has any.
     *
     * @return list<array{name: string, former_names?: string|null}>
     */
    public function activeCompanies(): array;

    /** Writes the five vendor columns of one invoice, and who chose them (`$byUserId`; null for an automatic match). */
    public function setInvoiceVendor(string $invoiceId, ApVendorMatch $match, ?int $byUserId = null): void;
}
