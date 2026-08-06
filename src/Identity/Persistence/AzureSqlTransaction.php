<?php

declare(strict_types=1);

namespace TriCab\Api\Identity\Persistence;

use Throwable;
use TriCab\Api\Infrastructure\Database\SqlConnection;

/**
 * Nested-transaction-safe write helper. Begins a transaction only when the
 * connection is not already inside one, and only commits/rolls back the
 * transaction it started itself.
 */
trait AzureSqlTransaction
{
    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function runInTransaction(SqlConnection $connection, callable $operation): mixed
    {
        $startedHere = !$connection->inTransaction();

        if ($startedHere) {
            $connection->beginTransaction();
        }

        try {
            $result = $operation();

            if ($startedHere) {
                $connection->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($startedHere && $connection->inTransaction()) {
                $connection->rollBack();
            }

            throw $exception;
        }
    }
}
