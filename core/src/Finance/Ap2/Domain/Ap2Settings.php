<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/**
 * Tri-AP2's settings (`finance.ap2_settings`). The defaults are the prototype's, except the approval expiry, which
 * is a placeholder until Finance decides (plan question 7).
 */
final readonly class Ap2Settings
{
    /**
     * @param array<string, float> $fxRates AUD per one unit of each currency; Odoo's rates replace these in Phase 9
     * @param array<string, int> $taxCodeMap invoice tax code => Odoo account.tax id; wins over name matching when posting
     */
    public function __construct(
        public string $glDefault = '6000',
        public string $taxDefault = 'GST10',
        public int $escalationDays = 2,
        public float $batchCashLimit = 25000.0,
        public int $approvalExpiryDays = 7,
        public bool $paymentProposalsEnabled = true,
        public array $fxRates = ['AUD' => 1.0],
        public array $taxCodeMap = [],
        /** Sydney calendar day (yyyy-mm-dd): only mail received on or after it is synced or shown. Null until set (sync refuses). */
        public ?string $mailCutoffDate = null,
    ) {
    }

    public function with(mixed ...$changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
