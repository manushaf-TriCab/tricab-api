<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

/**
 * STRICT READ-ONLY legacy adapter for reconciliation / dry-run.
 * Must never become the permanent write path.
 */
final class ReadOnlyLegacyExpenseReceiptSource
{
    public function __construct(
        private readonly LegacyReadOnlyConnection $connection,
        private readonly LegacyReadOnlySqlGuard $guard,
    ) {
    }

    public function countReceipts(): int
    {
        $sql = 'SELECT COUNT(*) AS c FROM tc.triexpense_receipts';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, [], 'ReadOnlyLegacyExpenseReceiptSource', 'countReceipts');

        return (int) ($rows[0]['c'] ?? 0);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function sampleIdentityKeys(int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $sql = 'SELECT id, created_by, submitted_by, manager_login, status, category, file_url
                FROM tc.triexpense_receipts
                WHERE deleted_at IS NULL
                ORDER BY id ASC
                LIMIT ' . $limit;
        $this->guard->assertReadOnly($sql);

        return $this->connection->query($sql, [], 'ReadOnlyLegacyExpenseReceiptSource', 'sampleIdentityKeys');
    }

    /** Explicit write rejection for tests — never executes against DB. */
    public function assertWritesRejected(string $sql): void
    {
        $this->guard->assertReadOnly($sql);
    }
}