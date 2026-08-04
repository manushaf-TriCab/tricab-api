<?php

declare(strict_types=1);

namespace TriCab\Api\Http\Auth;

use Throwable;
use TriCab\Api\Infrastructure\Database\SqlConnection;

/**
 * Wraps admin mutation callables in a SQL transaction when a SqlConnection is
 * bound (azure-sql mode); no-ops transparently in memory mode.
 */
final class AdminMutationTransaction
{
    public function __construct(
        private readonly ?SqlConnection $connection = null,
    ) {
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function run(callable $fn): mixed
    {
        $connection = $this->connection;

        if ($connection === null) {
            return $fn();
        }

        $started = false;
        if (!$connection->inTransaction()) {
            $connection->beginTransaction();
            $started = true;
        }

        try {
            $result = $fn();

            if ($started) {
                $connection->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($started && $connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }
}
