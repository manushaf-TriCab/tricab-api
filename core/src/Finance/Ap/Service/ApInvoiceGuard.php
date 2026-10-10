<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Contract\ApInvoiceStore;
use TriCab\Core\Finance\Module;
use TriCab\Core\Finance\Shared\Attachments\FinanceRecordGuard;

/**
 * Tri-AP's answer to "may this caller see that record?" for the file store. A caller who reached the route already holds
 * the app's permission and `ApAttachmentService` has applied the person's mailbox scope to the invoice; this checks that the
 * record exists (a binned invoice is still readable, as the source read it) and answers only for the app's own record type.
 */
final class ApInvoiceGuard implements FinanceRecordGuard
{
    public function __construct(private readonly ApInvoiceStore $invoices)
    {
    }

    public function maySee(string $application, string $recordType, string $recordId): bool
    {
        return $application === Module::APP_AP
            && $recordType === ApAttachmentService::RECORD_TYPE
            && $this->invoices->exists($recordId);
    }
}
