<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use TriCab\Api\Finance\Ap\InMemoryApAuditStore;
use TriCab\Api\Finance\Ap\InMemoryApInvoiceStore;
use TriCab\Api\Finance\Attachments\InMemoryFinanceAttachmentStore;
use TriCab\Api\Finance\Audit\InMemoryFinanceAuditLog;
use TriCab\Api\Tests\Doubles\FixedClock;
use TriCab\Core\Finance\Ap\Domain\ApActor;
use TriCab\Core\Finance\Ap\Domain\ApInvoice;
use TriCab\Core\Finance\Ap\Service\ApAttachmentService;
use TriCab\Core\Finance\Ap\Service\ApInvoiceDownloadService;
use TriCab\Core\Finance\Ap\Service\ApInvoiceGuard;
use TriCab\Core\Finance\Ap\Service\ApInvoiceService;
use TriCab\Core\Finance\Ap\Service\ApTime;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentService;
use TriCab\Core\Finance\Shared\Attachments\FinanceAttachmentStore;

/** The register's services over the in-memory stores, with a fixed clock (2026-10-09 01:02:03 UTC, which is 9 Oct in Melbourne). */
final class ApInvoiceWorld
{
    public InMemoryApInvoiceStore $invoices;

    public InMemoryApAuditStore $audit;

    public FinanceAttachmentStore $fileStore;

    public FinanceAttachmentService $files;

    public InMemoryFinanceAuditLog $platformAudit;

    public ApAttachmentService $attachments;

    public ApInvoiceService $service;

    public ApInvoiceDownloadService $downloads;

    public FixedClock $clock;

    public function __construct(?FinanceAttachmentStore $fileStore = null, int $maxDownloadBytes = ApInvoiceDownloadService::MAX_TOTAL_BYTES)
    {
        $this->clock = FixedClock::utc('2026-10-09 01:02:03.456789');
        $this->invoices = new InMemoryApInvoiceStore();
        $this->audit = new InMemoryApAuditStore();
        $this->fileStore = $fileStore ?? new InMemoryFinanceAttachmentStore();
        $this->files = new FinanceAttachmentService($this->fileStore);
        $this->platformAudit = new InMemoryFinanceAuditLog();
        $time = new ApTime($this->clock, 'Australia/Melbourne');
        $this->attachments = new ApAttachmentService($this->files, $this->fileStore, new ApInvoiceGuard($this->invoices));
        $this->service = new ApInvoiceService($this->invoices, $this->audit, $this->attachments, $time, $this->platformAudit);
        $this->downloads = new ApInvoiceDownloadService($this->invoices, $this->attachments, $time, $maxDownloadBytes);
    }

    /** Stores an invoice and, with bytes, a document on it, as the scan would. */
    public function stored(ApInvoice $invoice, ?string $bytes = null, string $type = 'application/pdf'): ApInvoice
    {
        if ($bytes !== null) {
            $file = $this->attachments->keep($invoice->attachment !== '' ? $invoice->attachment : 'document.pdf', $type, $bytes, 7);
            $this->files->attach(Module::APP_AP, $file->id, ApAttachmentService::RECORD_TYPE, $invoice->id, 7);
            $invoice = $invoice->with(fileId: $file->id, contentHash: $file->sha256);
        }
        $this->invoices->insert($invoice);

        return $invoice;
    }

    public function actor(): ApActor
    {
        return ApInvoiceFixtures::actor();
    }
}
