<?php

declare(strict_types=1);

namespace TriCab\Core\QuoteOrder\Contract;

/**
 * Write-capable MySQL connection for quote persistence only.
 * Must never share the read-only LEGACY_TC bridge or LegacyReadOnlySqlGuard path.
 */
interface LegacyQuoteWriteConnection
{
    public function beginTransaction(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function inTransaction(): bool;

    /**
     * @param array<string, scalar|null> $params
     */
    public function execute(string $sql, array $params = []): int;

    public function lastInsertId(): string;
}
