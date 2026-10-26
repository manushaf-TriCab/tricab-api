<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Domain;

/** One uninvoiced PO estimate for the cash forecast (`finance.ap2_po_forecast`). */
final readonly class Ap2PoForecastRow
{
    public function __construct(
        public string $po,
        public string $supplierId,
        public float $amount,
        public string $currency,
        public string $date,
    ) {
    }
}
