<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * What one bill's Odoo state means for the AP2 invoice: its import state, Odoo reference, what Odoo reports paid, the
 * stage that follows, and anything a person should look at. `changed` is false when nothing differs from what AP2
 * holds, so the target can skip the write and the history line.
 *
 * `paid` is ABSOLUTE (total less credit less Odoo's residual), not an amount to add.
 */
final readonly class Ap2OdooBillUpdate
{
    /** @param list<string> $issues */
    public function __construct(
        public string $invoiceId,
        public int $moveId,
        public string $importState,
        public string $odooRef,
        public float $paid,
        public string $stage,
        public bool $settled,
        public array $issues,
        public bool $changed,
    ) {
    }
}
