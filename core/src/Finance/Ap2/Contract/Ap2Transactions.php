<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Ap2\Contract;

/** One database transaction around a use case, so an invoice write and its history line land together or not at all. */
interface Ap2Transactions
{
    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed;
}
