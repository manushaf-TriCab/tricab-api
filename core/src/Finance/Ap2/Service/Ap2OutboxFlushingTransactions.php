<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Service;

use Throwable;
use TriCab\Core\Finance\Ap2\Contract\Ap2DeferredOutbox;
use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;

/**
 * Wraps the real transactions so the Graph outbox sends after the commit: the use case queues inside its transaction,
 * and the message goes only if the transaction commits (and is dropped if it rolls back).
 */
final class Ap2OutboxFlushingTransactions implements Ap2Transactions
{
    public function __construct(
        private readonly Ap2Transactions $inner,
        private readonly Ap2DeferredOutbox $outbox,
    ) {
    }

    public function run(callable $work): mixed
    {
        $this->outbox->enter();
        try {
            $result = $this->inner->run($work);
        } catch (Throwable $failure) {
            $this->outbox->leave(false);

            throw $failure;
        }
        $this->outbox->leave(true);

        return $result;
    }
}
