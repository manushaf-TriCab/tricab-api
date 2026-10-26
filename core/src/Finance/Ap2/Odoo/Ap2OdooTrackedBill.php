<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/** An AP2 invoice that has an Odoo bill (`odooMoveId`) and is not settled yet: what the sync compares Odoo with. */
final readonly class Ap2OdooTrackedBill
{
    public function __construct(
        public string $invoiceId,
        public int $moveId,
        public string $number,
        public string $currency,
        public float $total,
        public float $credit,
        public float $paid,
        public string $importState,
        public string $odooRef = '',
    ) {
    }
}
