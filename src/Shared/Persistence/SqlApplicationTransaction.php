<?php

declare(strict_types=1);

namespace TriCab\Api\Shared\Persistence;

use Throwable;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Shared\Persistence\ApplicationTransaction;

/**
 * Nested-safe transaction boundary for Phase 4 application services, backed by
 * a real SQL connection (azure-sql mode). Mirrors AdminMutationTransaction.
 */
final class SqlApplicationTransaction implements ApplicationTransaction
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function run(callable $operation): mixed
    {
        $startedHere = !$this->connection->inTransaction();
        if ($startedHere) {
            $this->connection->beginTransaction();
        }

        try {
            $result = $operation();

            if ($startedHere) {
                $this->connection->commit();
            }

            return $result;
        } catch (Throwable $exception) {
            if ($startedHere && $this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
