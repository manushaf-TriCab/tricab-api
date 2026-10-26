<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

use DateTimeImmutable;

/**
 * One row of `finance.ap2_invoices`: the prototype's "bill", with the contract's field names.
 *
 * Dates are `yyyy-MM-dd` text (the Sydney calendar day), null when unknown. Money is a float of whole cents.
 * Everything the rules derive (validation, matching, duplicates, queue, approval currency, export and payment
 * eligibility) is NOT stored: {@see Ap2InvoiceRules::assess()} computes it from these fields on every read.
 */
final readonly class Ap2Invoice
{
    public const KINDS = ['Goods PO', 'Service PO', 'No PO', 'Recurring'];

    public const CURRENCIES = ['AUD', 'USD', 'NZD', 'SGD'];

    public const STAGES = ['Received', 'Needs verification', 'Awaiting matching', 'Pending approval', 'Approved', 'Posted', 'Partially settled', 'Settled', 'Excluded'];

    public const IMPORT_STATES = ['Not exported', 'Exported CSV', 'Imported draft', 'Posted', 'Import failed'];

    public function __construct(
        public string $id,
        public string $supplierId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public string $number = '',
        public string $entity = '',
        public string $currency = 'AUD',
        public float $net = 0.0,
        public float $tax = 0.0,
        public float $total = 0.0,
        public ?string $issued = null,
        public ?string $due = null,
        public string $kind = 'No PO',
        public string $po = '',
        public float $quantity = 1.0,
        public float $orderedQty = 0.0,
        public float $receivedQty = 0.0,
        public float $poUnitPrice = 0.0,
        public string $receiptRef = '',
        public bool $serviceAccepted = false,
        public string $noPoReason = '',
        public string $gl = '',
        public string $taxCode = '',
        public string $costCentre = '',
        public float $credit = 0.0,
        public string $creditRef = '',
        public float $creditNet = 0.0,
        public float $creditTax = 0.0,
        public bool $creditResolved = false,
        public float $paid = 0.0,
        public string $stage = 'Received',
        public bool $verified = false,
        public string $importState = 'Not exported',
        public string $odooRef = '',
        public ?int $odooMoveId = null,
        public ?string $approvalId = null,
        public bool $excluded = false,
        public string $excludedReason = '',
        public bool $paymentHold = false,
        public string $sourceDoc = '',
        public ?string $sourceFileId = null,
        public ?float $confidence = null,
        public string $sourceFingerprint = '',
        public ?string $mailId = null,
        public ?Ap2Person $owner = null,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    /** Posted in Odoo: the financial fields are locked (the prototype's `posted()`). */
    public function isPosted(): bool
    {
        return $this->importState === 'Posted';
    }

    /** What is still owed: total less credit less what Odoo reports paid, never below zero. */
    public function balance(): float
    {
        return max(0.0, round($this->total - $this->credit - $this->paid, 2));
    }
}
