<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap;

/**
 * The invoice context the vendor email draft is written from (`draft_vendor_email(invoice, ...)`): the fields the
 * source's prompt names, and `vendorEmail` / `sender` for the draft's `to` (the invoice's vendor email, else the
 * email's sender).
 *
 * `totalAmount` is printed into the prompt as it is written here, so pass the number as a number (1234.5 prints as
 * `1234.5`, as the source's did); text prints as text.
 */
final readonly class ApDraftInvoice
{
    public function __construct(
        public ?string $vendor,
        public ?string $invoiceNo,
        public ?string $invoiceDate,
        public ?string $poNumber,
        public ?string $currency,
        public int|float|string|null $totalAmount,
        public ?string $subject,
        public ?string $vendorEmail,
        public ?string $sender,
    ) {
    }

    /** @return array<string, int|float|string|null> */
    public function promptData(): array
    {
        return [
            'vendor' => $this->vendor,
            'invoice_no' => $this->invoiceNo,
            'invoice_date' => $this->invoiceDate,
            'po_number' => $this->poNumber,
            'currency' => $this->currency,
            'total_amount' => $this->totalAmount,
            'subject' => $this->subject,
        ];
    }

    /** `invoice.get("vendor_email") or invoice.get("sender") or ""`. */
    public function to(): string
    {
        foreach ([$this->vendorEmail, $this->sender] as $address) {
            if ($address !== null && $address !== '') {
                return $address;
            }
        }

        return '';
    }
}
