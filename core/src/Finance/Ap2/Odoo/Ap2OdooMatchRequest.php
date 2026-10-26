<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/** An AP2 invoice awaiting PO / receipt matching: the PO number it quotes and its currency. */
final readonly class Ap2OdooMatchRequest
{
    public function __construct(
        public string $invoiceId,
        public string $po,
        public string $currency,
    ) {
    }
}
