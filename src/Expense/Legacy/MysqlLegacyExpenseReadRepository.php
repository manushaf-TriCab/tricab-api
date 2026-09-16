<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Contract\LegacyExpenseReadRepository;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

/**
 * Generic READ-ONLY adapter over tc.triexpense_receipts.
 * Ownership is always constrained by parameterized owner keys from ExpenseProfile.
 */
final class MysqlLegacyExpenseReadRepository implements LegacyExpenseReadRepository
{
    private const SELECT_COLUMNS = <<<'SQL'
id, file_url, file_name, vendor_name, total_amount, gst_amount, currency, receipt_date,
category, other_category, customer_id, customer_code, customer_name, customer_state,
contact_name, contact_email, contact_phone, description, gst_type, statement_period,
cost_centre, manager_login, manager_status, manager_reason, status,
created_by, submitted_by, created_at, updated_at, deleted_at
SQL;

    public function __construct(
        private readonly LegacyReadOnlyConnection $connection,
        private readonly LegacyReadOnlySqlGuard $guard,
    ) {
    }

    public function listForOwnerKeys(array $ownerKeys, int $limit = 500): array
    {
        $keys = $this->normalizeKeys($ownerKeys);
        if ($keys === []) {
            return [];
        }
        $limit = max(1, min(1000, $limit));
        [$placeholders, $params] = $this->bindKeys($keys);
        $sql = 'SELECT ' . self::SELECT_COLUMNS
            . ' FROM tc.triexpense_receipts'
            . ' WHERE deleted_at IS NULL'
            . ' AND LOWER(TRIM(created_by)) IN (' . $placeholders . ')'
            . ' ORDER BY receipt_date DESC, id DESC'
            . ' LIMIT ' . $limit;
        $this->guard->assertReadOnly($sql);

        return $this->connection->query($sql, $params, 'MysqlLegacyExpenseReadRepository', 'listForOwnerKeys');
    }

    public function findOwnedById(int $legacyReceiptId, array $ownerKeys): ?array
    {
        if ($legacyReceiptId <= 0) {
            return null;
        }
        $keys = $this->normalizeKeys($ownerKeys);
        if ($keys === []) {
            return null;
        }
        [$placeholders, $params] = $this->bindKeys($keys);
        $params[':id'] = $legacyReceiptId;
        $sql = 'SELECT ' . self::SELECT_COLUMNS
            . ' FROM tc.triexpense_receipts'
            . ' WHERE id = :id'
            . ' AND deleted_at IS NULL'
            . ' AND LOWER(TRIM(created_by)) IN (' . $placeholders . ')'
            . ' LIMIT 1';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, $params, 'MysqlLegacyExpenseReadRepository', 'findOwnedById');

        return $rows[0] ?? null;
    }

    /**
     * @param list<string> $ownerKeys
     * @return list<string>
     */
    private function normalizeKeys(array $ownerKeys): array
    {
        $out = [];
        foreach ($ownerKeys as $key) {
            $normalized = strtolower(trim((string) $key));
            if ($normalized !== '') {
                $out[$normalized] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param list<string> $keys
     * @return array{0:string,1:array<string,string>}
     */
    private function bindKeys(array $keys): array
    {
        $placeholders = [];
        $params = [];
        foreach ($keys as $i => $key) {
            $name = ':k' . $i;
            $placeholders[] = $name;
            $params[$name] = $key;
        }

        return [implode(',', $placeholders), $params];
    }
}
