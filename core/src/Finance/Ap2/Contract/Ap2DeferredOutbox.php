<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

/**
 * An {@see Ap2Outbox} that holds messages queued inside a transaction until it commits. The transaction wrapper
 * ({@see \TriCab\Core\Finance\Ap2\Service\Ap2OutboxFlushingTransactions}) calls `enter` before the work and `leave`
 * after it: sent on commit (outermost level only), dropped on rollback.
 */
interface Ap2DeferredOutbox extends Ap2Outbox
{
    public function enter(): void;

    public function leave(bool $committed): void;
}
