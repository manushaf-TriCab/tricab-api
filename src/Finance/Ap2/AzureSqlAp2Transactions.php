<?php

declare(strict_types=1);

namespace TriCab\Api\Finance\Ap2;

use TriCab\Core\Finance\Ap2\Contract\Ap2Transactions;

/** One transaction on the shared connection; a nested call joins the open one. */
final class AzureSqlAp2Transactions extends AzureSqlAp2Support implements Ap2Transactions
{
    public function run(callable $work): mixed
    {
        return $this->transactional($work);
    }
}
