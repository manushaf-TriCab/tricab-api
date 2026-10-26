<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/** One product line of a purchase order (`purchase.order.line`). */
final readonly class Ap2OdooPoLine
{
    public function __construct(
        public int $id,
        public ?int $productId,
        public string $description,
        public float $orderedQty,
        public float $receivedQty,
        public float $invoicedQty,
        public float $priceUnit,
    ) {
    }
}
