<?php

declare(strict_types=1);

namespace TriCab\Api\Tests\Finance\Ap;

use TriCab\Api\Infrastructure\Database\SqlConnection;

/** A connection that counts the statements sent through it: what a test of "a fixed number of queries" reads. */
final class CountingSqlConnection implements SqlConnection
{
    public int $statements = 0;

    public function __construct(private readonly SqlConnection $inner)
    {
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        ++$this->statements;

        return $this->inner->fetchAll($sql, $params);
    }

    public function execute(string $sql, array $params = []): void
    {
        ++$this->statements;
        $this->inner->execute($sql, $params);
    }

    public function beginTransaction(): void
    {
        $this->inner->beginTransaction();
    }

    public function commit(): void
    {
        $this->inner->commit();
    }

    public function rollBack(): void
    {
        $this->inner->rollBack();
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }
}
