<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap\Service;

use TriCab\Core\Finance\Ap\Domain\ApOutboundMessage;
use TriCab\Core\Finance\Shared\Mail\FinanceMailDelivery;

/**
 * A send that went out: the link row, the ledger row (`sent`), whether it went into the original's conversation, and whether the
 * idempotency key had been sent already (nothing went out this time, and the earlier link and delivery are returned).
 */
final readonly class ApMailOutcome
{
    public function __construct(
        public ApOutboundMessage $link,
        public FinanceMailDelivery $delivery,
        public bool $threaded,
        public bool $deduplicated,
    ) {
    }
}
