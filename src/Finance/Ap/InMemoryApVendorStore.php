<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Contract\ApVendorStore;
use TriCab\Core\Finance\Ap\Domain\ApVendor;
use TriCab\Core\Finance\Ap\Domain\ApVendorInvoice;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;

/**
 * The synced vendors, the companies and the invoices' vendor columns held in memory, for the tests'
 * and a database-less run's use. Invoices are kept here only for their vendor columns; the count per
 * vendor is worked out from them, live ones only (an invoice put in the bin is `$binned`).
 */
final class InMemoryApVendorStore implements ApVendorStore
{
    /** @var list<ApVendor> */
    private array $vendors = [];

    private ?DateTimeImmutable $syncedAt = null;

    /** @var list<array{name: string, former_names?: string|null}> */
    private array $companies = [];

    /** @var array<string, ApVendorInvoice> */
    private array $invoices = [];

    /** @var array<string, true> */
    private array $binned = [];

    /** @var list<string> invoice ids written, in order */
    public array $writes = [];

    /**
     * @param list<ApVendor> $vendors
     */
    public function withVendors(array $vendors, ?DateTimeImmutable $syncedAt = null): self
    {
        $this->vendors = $vendors;
        $this->syncedAt = $syncedAt;

        return $this;
    }

    /**
     * @param list<array{name: string, former_names?: string|null}> $companies
     */
    public function withCompanies(array $companies): self
    {
        $this->companies = $companies;

        return $this;
    }

    public function withInvoice(ApVendorInvoice $invoice, bool $binned = false): self
    {
        $this->invoices[$invoice->id] = $invoice;
        if ($binned) {
            $this->binned[$invoice->id] = true;
        }

        return $this;
    }

    public function invoice(string $id): ?ApVendorInvoice
    {
        return $this->invoices[$id] ?? null;
    }

    /**
     * The kept vendors that Odoo has not removed, ordered by name (case-insensitively) then Odoo id.
     *
     * @return list<ApVendor>
     */
    public function vendors(): array
    {
        $live = array_values(array_filter($this->vendors, static fn (ApVendor $vendor): bool => !$vendor->deleted));
        usort($live, static fn (ApVendor $left, ApVendor $right): int => strcmp(mb_strtolower($left->name, 'UTF-8'), mb_strtolower($right->name, 'UTF-8'))
            ?: $left->odooId <=> $right->odooId);

        return $live;
    }

    public function vendorsSyncedAt(): ?DateTimeImmutable
    {
        return $this->syncedAt;
    }

    public function invoiceCountsByVendor(): array
    {
        $counts = [];
        foreach ($this->invoices as $id => $invoice) {
            if ($invoice->odooVendorId !== null && !isset($this->binned[$id])) {
                $counts[$invoice->odooVendorId] = ($counts[$invoice->odooVendorId] ?? 0) + 1;
            }
        }

        return $counts;
    }

    public function activeCompanies(): array
    {
        return $this->companies;
    }

    public function setInvoiceVendor(string $invoiceId, ApVendorMatch $match, ?int $byUserId = null): void
    {
        $invoice = $this->invoices[$invoiceId] ?? new ApVendorInvoice($invoiceId);
        $this->invoices[$invoiceId] = new ApVendorInvoice(
            $invoice->id,
            $invoice->vendor,
            $invoice->company,
            $invoice->taxId,
            $invoice->vendorEmail,
            $invoice->sender,
            $invoice->senderName,
            $match->vendorMatch,
            $match->odooVendorId,
            $match->vendorRef,
            $match->odooVendorName,
            $match->vendorMatchNote,
        );
        $this->writes[] = $invoiceId;
    }
}
