<?php

declare(strict_types=1);

namespace TriCab\Api\LegacyMigration\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\LegacyMigration\Live\ReadOnlySqlAudit;

/**
 * Decorator that permits SELECT only and records audit counts.
 */
final class ReadOnlyAuditedSqlConnection implements SqlConnection
{
    public function __construct(
        private readonly SqlConnection $inner,
        private readonly ReadOnlySqlAudit $audit,
    ) {
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        $this->audit->recordAzure($sql);

        return $this->inner->fetchAll($sql, $params);
    }

    public function execute(string $sql, array $params = []): void
    {
        $this->audit->recordAzure($sql);
        $this->inner->execute($sql, $params);
    }

    public function beginTransaction(): void
    {
        throw new \RuntimeException('Transactions are forbidden during strict read-only staff analysis.');
    }

    public function commit(): void
    {
        throw new \RuntimeException('Transactions are forbidden during strict read-only staff analysis.');
    }

    public function rollBack(): void
    {
        throw new \RuntimeException('Transactions are forbidden during strict read-only staff analysis.');
    }

    public function inTransaction(): bool
    {
        return false;
    }
}
