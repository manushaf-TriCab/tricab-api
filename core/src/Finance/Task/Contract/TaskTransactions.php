<?php

declare(strict_types=1);

namespace TriCab\Core\Finance\Task\Contract;

/**
 * "All or nothing" for a use case that reads and writes several rows (a bulk update, a
 * status change with its history, a permanent delete). The use case keeps the rules; the
 * adapter keeps the transaction, so both adapters give the same guarantee.
 */
interface TaskTransactions
{
    /**
     * Runs `$work`; commits when it returns, rolls back when it throws, and rethrows. A call inside
     * another joins it.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function transactional(callable $work): mixed;
}
