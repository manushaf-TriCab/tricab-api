<?php

declare(strict_types=1);

namespace TriCab\Core\Shared\Persistence;

/**
 * Cross-repository transaction boundary owned by application services.
 *
 * Implementations must be nested-safe: calling run() while already inside a
 * transaction started by an outer run() call must not commit/rollback early.
 */
interface ApplicationTransaction
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
