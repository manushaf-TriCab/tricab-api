<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Ap\Contract\ApReferenceStore;
use TriCab\Core\Finance\Ap\Contract\ApVendorStore;
use TriCab\Core\Finance\Ap\Domain\ApReferenceSpec;
use TriCab\Core\Finance\Ap\Domain\ApVendor;
use TriCab\Core\Finance\Ap\Domain\ApVendorMatch;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Shared\Odoo\OdooLists;
use TriCab\Core\Finance\Shared\Odoo\OdooRecordStore;
use TriCab\Core\Finance\Shared\Odoo\OdooSyncRuns;

/**
 * What vendor matching needs from storage, over the platform's own tables: the vendors are the `vendors` list of
 * `finance.odoo_records`; the counts and the one write go through {@see ApInvoiceStore}, and the companies through
 * {@see ApReferenceStore}, so each rule stays in one place.
 *
 * **When the vendors were last synced.** The sync records no time per list: every run reads all four lists inside one
 * transaction and either succeeds or fails as a whole (`OdooSyncService`), and a record's own `synced_at` moves only when
 * that record changes. So the most faithful answer to the source's `synced_at("vendors")` is when the last SUCCESSFUL run
 * finished (`finance.odoo_sync_runs`), which every list shared. A run that is still going, failed or was abandoned does
 * not count.
 */
final class AzureSqlApVendorStore implements ApVendorStore
{
    /** The companies the source told the model about (`_active_company_names()`): the newest 200. */
    private const COMPANY_LIMIT = 200;

    public function __construct(
        private readonly OdooRecordStore $records,
        private readonly OdooSyncRuns $runs,
        private readonly ApInvoiceStore $invoices,
        private readonly ApReferenceStore $references,
        private readonly ApTime $time,
    ) {
    }

    public function vendors(): array
    {
        $vendors = [];
        foreach ($this->records->list(OdooLists::VENDORS, true, null) as $record) {
            $vendors[] = ApVendor::fromRecord($record);
        }

        return $vendors;
    }

    public function vendorsSyncedAt(): ?DateTimeImmutable
    {
        $run = $this->runs->lastSuccess();

        return $run === null ? null : ($run->finishedAt ?? $run->startedAt);
    }

    public function invoiceCountsByVendor(): array
    {
        return $this->invoices->liveCountsByVendor();
    }

    public function activeCompanies(): array
    {
        $companies = [];
        foreach ($this->references->rows(ApReferenceSpec::for(ApReferenceSpec::COMPANIES), 'created_date', true, self::COMPANY_LIMIT) as $row) {
            $name = $row->values['name'] ?? '';
            if ($row->active && trim($name) !== '') {
                $companies[] = ['name' => $name, 'former_names' => $row->values['former_names'] ?? ''];
            }
        }

        return $companies;
    }

    public function setInvoiceVendor(string $invoiceId, ApVendorMatch $match, ?int $byUserId = null): void
    {
        $this->invoices->setVendorMatch($invoiceId, $match, $byUserId, $this->time->now());
    }
}
