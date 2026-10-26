<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Odoo;

/**
 * A vendor bill's state in Odoo (`account.move`): `state` draft|posted|cancel, `payment_state`
 * not_paid|in_payment|paid|partial|reversed|blocked|invoicing_legacy, and the amounts in the bill's own currency.
 */
final readonly class Ap2OdooBillState
{
    /** @param list<Ap2OdooPaymentRef> $payments the payments reconciled with it */
    public function __construct(
        public int $moveId,
        public string $name,
        public string $ref,
        public string $moveType,
        public string $state,
        public string $paymentState,
        public float $amountTotal,
        public float $amountResidual,
        public string $currency,
        public array $payments,
    ) {
    }
}
