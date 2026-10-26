<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/** A receipt of a purchase order (`stock.picking`): its reference, state and the day it was done. */
final readonly class Ap2OdooReceipt
{
    public function __construct(
        public int $id,
        public string $name,
        public string $state,
        public ?string $doneOn,
    ) {
    }
}
