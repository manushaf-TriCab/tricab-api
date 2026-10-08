<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Shared\Mail;

/**
 * The outcome of a send that did not throw. `deduplicated` is true when the
 * idempotency key had already been sent: nothing was sent again, and `delivery`
 * is the earlier one.
 */
final readonly class FinanceMailResult
{
    public function __construct(
        public FinanceMailDelivery $delivery,
        public bool $deduplicated,
        public bool $threaded = false,
    ) {
    }
}
