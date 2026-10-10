<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/**
 * The vendor columns to store on an invoice: `auto_match`'s return value, and what the "set a match" bodies produce.
 */
final readonly class ApVendorMatch
{
    public const MATCHED = 'matched';

    public const UNMATCHED = 'unmatched';

    public const CONFIRMED = 'confirmed';

    public const NEW = 'new';

    /** Set by a person; an automatic re-match never overwrites these (`VENDOR_MATCH_DECIDED`). */
    public const DECIDED = [self::CONFIRMED, self::NEW];

    /** Edits to these invoice fields can change which vendor an invoice belongs to (`VENDOR_MATCH_INPUTS`). */
    public const INPUT_FIELDS = ['vendor', 'vendor_email', 'tax_id', 'company'];

    public function __construct(
        public ?int $odooVendorId,
        public ?string $vendorRef,
        public ?string $odooVendorName,
        public string $vendorMatch,
        public string $vendorMatchNote,
    ) {
    }

    /**
     * The columns under the source's names (the audit event lists three of them).
     *
     * @return array{odoo_vendor_id: int|null, vendor_ref: string|null, odoo_vendor_name: string|null, vendor_match: string, vendor_match_note: string}
     */
    public function toArray(): array
    {
        return [
            'odoo_vendor_id' => $this->odooVendorId,
            'vendor_ref' => $this->vendorRef,
            'odoo_vendor_name' => $this->odooVendorName,
            'vendor_match' => $this->vendorMatch,
            'vendor_match_note' => $this->vendorMatchNote,
        ];
    }
}
