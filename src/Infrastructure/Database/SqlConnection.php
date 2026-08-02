<?php

declare(strict_types=1);

namespace TriCab\Api\Infrastructure\Database;

/**
 * Narrow SQL boundary used by Azure SQL repositories for testability.
 */
interface SqlConnection
{
    /**
     * @param array<string, scalar|null> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array;

    /**
     * @param array<string, scalar|null> $params
     */
    public function execute(string $sql, array $params = []): void;

    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function inTransaction(): bool;
}
