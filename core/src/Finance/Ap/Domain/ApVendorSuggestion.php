<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Domain;

/** A ranked candidate vendor for a person to pick (`_vendor_summary` plus `score` and `reason`). */
final readonly class ApVendorSuggestion
{
    public function __construct(
        public ApVendor $vendor,
        /** Rounded to two places, as the source does. */
        public float $score,
        public string $reason,
    ) {
    }

    /**
     * Every key the source's suggestion carried.
     *
     * @return array{odoo_id: int, name: string, ref: string, email: string, vat: string, company_name: string, active: bool, score: float, reason: string}
     */
    public function toArray(): array
    {
        return [
            'odoo_id' => $this->vendor->odooId,
            'name' => $this->vendor->name,
            'ref' => $this->vendor->ref,
            'email' => $this->vendor->email,
            'vat' => $this->vendor->vat,
            'company_name' => $this->vendor->companyName,
            'active' => $this->vendor->active,
            'score' => $this->score,
            'reason' => $this->reason,
        ];
    }
}
