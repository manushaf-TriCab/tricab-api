<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/**
 * The invoice fields vendor matching reads (`vendor`, `company`, `tax_id`, `vendor_email`, and the
 * sender for suggestions) and the vendor columns it writes (`vendor_match` and the four beside it).
 */
final readonly class ApVendorInvoice
{
    public function __construct(
        public string $id,
        public ?string $vendor = null,
        public ?string $company = null,
        public ?string $taxId = null,
        public ?string $vendorEmail = null,
        /** The raw sender, "Name <address>" or an address. */
        public ?string $sender = null,
        /** The sender's display name, when the mail store knows it (`sender_name` in the source). */
        public ?string $senderName = null,
        /** null (never tried), 'matched', 'unmatched', 'confirmed' or 'new'. */
        public ?string $vendorMatch = null,
        public ?int $odooVendorId = null,
        public ?string $vendorRef = null,
        public ?string $odooVendorName = null,
        public ?string $vendorMatchNote = null,
    ) {
    }

    /** True when the stored columns already say what `$match` says (so a run need not write them). */
    public function carries(ApVendorMatch $match): bool
    {
        return $this->odooVendorId === $match->odooVendorId
            && $this->vendorRef === $match->vendorRef
            && $this->odooVendorName === $match->odooVendorName
            && $this->vendorMatch === $match->vendorMatch
            && $this->vendorMatchNote === $match->vendorMatchNote;
    }

    /**
     * The register's invoice as vendor matching reads it. A blank text column ('' in the table) is null here, as the
     * source's null was.
     */
    public static function fromInvoice(ApInvoice $invoice): self
    {
        $text = static fn (string $value): ?string => $value === '' ? null : $value;

        return new self(
            $invoice->id,
            $text($invoice->vendor),
            $text($invoice->company),
            $text($invoice->taxId),
            $text($invoice->vendorEmail),
            $text($invoice->sender),
            $text($invoice->senderName),
            $invoice->vendorMatch,
            $invoice->odooVendorId,
            $text($invoice->vendorRef),
            $text($invoice->odooVendorName),
            $text($invoice->vendorMatchNote),
        );
    }
}
