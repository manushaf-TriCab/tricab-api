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

    /**
     * The invoice state (Nick's 1 Nov update): exactly these three. Matching, approval, import and settlement are
     * separate dimensions, so a paid invoice stays Posted and its balance says how far it is settled.
     */
    public const STAGES = ['Needs verification', 'Confirmed', 'Posted'];

    public const MAX_LINES = 100;

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
        public string $stage = 'Needs verification',
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
        public bool $approvalRequired = false,
        public ?string $externalId = null,
        public string $journal = '',
        /** @var list<Ap2InvoiceLine> */
        public array $lines = [],
    ) {
    }

    /** The CSV's External ID: the saved one, else `triap_<id>`, so a repeat export keeps the same ID. */
    public function odooExternalId(): string
    {
        return $this->externalId ?? 'triap_' . $this->id;
    }

    /**
     * The bill lines the Odoo CSV carries: the saved ones, else one line from the invoice's own number, GL code,
     * quantity, net and tax code (the prototype's `initialiseVendorFields`).
     *
     * @return list<Ap2InvoiceLine>
     */
    public function odooLines(): array
    {
        if ($this->lines !== []) {
            return $this->lines;
        }
        $quantity = $this->quantity > 0 ? $this->quantity : 1.0;

        return [new Ap2InvoiceLine($this->number !== '' ? $this->number : 'Invoice line', $this->gl, $quantity, round($this->net / $quantity, 4), $this->taxCode)];
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
