<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/** A payment Odoo reconciled with a bill (`account.payment`): reference, day, amount, state. */
final readonly class Ap2OdooPaymentRef
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $date,
        public float $amount,
        public string $state,
    ) {
    }
}
