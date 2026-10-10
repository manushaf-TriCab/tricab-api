<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

use DateTimeImmutable;
use TriCab\Core\Finance\Ap\ApExtraction;

/**
 * An invoice the scan read from one attachment of one message, ready to be filed (`ApInvoiceService::createFromScan`).
 *
 * `$extraction` carries what the model read (graded: its `reviewReason`, and `status()`). The business-duplicate check runs on
 * its RAW fields (`invoiceNo`, `vendor`, `currency`, `totalAmount`) before `Unknown Vendor` or `INV-<epoch>` fill a gap.
 * `$status` and `$reviewReason` are what the scan decided (an attachment a person forced through carries the forced reason);
 * `$vendorEmail` is the scan's `_resolve_vendor_email` answer. The file, if any, is an upload the scan kept unattached.
 */
final readonly class ApInvoiceCapture
{
    public function __construct(
        public string $mailbox,
        public string $emailId,
        public ?string $emailRecordId,
        public ?string $attachmentId,
        public ?DateTimeImmutable $emailDate,
        public string $sender,
        public string $subject,
        public string $attachmentName,
        public ApExtraction $extraction,
        public string $status,
        public ?string $reviewReason,
        public ?string $vendorEmail = null,
        public ?string $fileId = null,
        public ?string $contentHash = null,
    ) {
    }
}
