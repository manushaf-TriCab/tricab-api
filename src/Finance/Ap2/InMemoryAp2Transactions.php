<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;

/** No transaction: the in-memory stores cannot fail half way in any way a test needs to see. */
final class InMemoryAp2Transactions implements Ap2Transactions
{
    public function run(callable $work): mixed
    {
        return $work();
    }
}
