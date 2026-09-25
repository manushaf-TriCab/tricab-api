<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceFilters;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceReadRepository;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

/**
 * Finance-authorized SELECT-only adapter over tc.triexpense_receipts (+ bank/period census).
 *
 * Org-wide queries are intentional — callers MUST gate with requireFinance.
 * Owner-key filters remain available for employee drill-down.
 */
final class MysqlLegacyExpenseFinanceReadRepository implements LegacyExpenseFinanceReadRepository
{
    private const RECEIPT_COLUMNS = <<<'SQL'
r.id, r.file_url, r.file_name, r.vendor_name, r.total_amount, r.gst_amount, r.currency, r.receipt_date,
r.category, r.other_category, r.customer_id, r.customer_code, r.customer_name, r.customer_state,
r.contact_name, r.contact_email, r.contact_phone, r.description, r.gst_type, r.statement_period,
r.cost_centre, r.manager_login, r.manager_status, r.manager_reason, r.status,
r.resolution, r.resolution_note, r.bank_feed_id, r.period_id,
r.created_by, r.submitted_by, r.created_at, r.updated_at, r.deleted_at,
COALESCE(NULLIF(TRIM(tu.display_name), ''), e.display_name, SUBSTRING_INDEX(r.created_by, '@', 1)) AS submitter_display_name,
COALESCE(NULLIF(TRIM(tu.office_location), ''), e.state, '') AS submitter_branch,
COALESCE(NULLIF(TRIM(tu.department_override), ''), TRIM(e.department), '') AS submitter_department,
COALESCE(NULLIF(TRIM(tu.manager_display_name), ''), mgr.display_name, r.manager_login) AS manager_display_name
SQL;

    public function __construct(
        private readonly LegacyReadOnlyConnection $connection,
        private readonly LegacyReadOnlySqlGuard $guard,
    ) {
    }

    public function findById(int $legacyReceiptId): ?array
    {
        if ($legacyReceiptId <= 0) {
            return null;
        }
        $sql = 'SELECT ' . self::RECEIPT_COLUMNS
            . ' FROM tc.triexpense_receipts r'
            . $this->joinsSql()
            . ' WHERE r.id = :id AND r.deleted_at IS NULL LIMIT 1';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, [':id' => $legacyReceiptId], self::class, 'findById');

        return $rows[0] ?? null;
    }

    public function listFinance(LegacyExpenseFinanceFilters $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $limit = max(1, min(1000, $filters->limit));
        $offset = max(0, $filters->offset);
        $sql = 'SELECT ' . self::RECEIPT_COLUMNS
            . ' FROM tc.triexpense_receipts r'
            . $this->joinsSql()
            . ' WHERE ' . $where
            . ' ORDER BY r.receipt_date DESC, r.id DESC'
            . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        $this->guard->assertReadOnly($sql);

        return $this->connection->query($sql, $params, self::class, 'listFinance');
    }

    public function countFinance(LegacyExpenseFinanceFilters $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT COUNT(*) AS cnt FROM tc.triexpense_receipts r WHERE ' . $where;
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, $params, self::class, 'countFinance');

        return MixedValueCoercion::intValue($rows[0]['cnt'] ?? 0);
    }

    public function countByStatus(?string $dateFrom, ?string $dateTo): array
    {
        $filters = new LegacyExpenseFinanceFilters(
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            excludeDraft: false,
            limit: 1,
        );
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT LOWER(TRIM(r.status)) AS status, COUNT(*) AS cnt'
            . ' FROM tc.triexpense_receipts r WHERE ' . $where
            . ' GROUP BY LOWER(TRIM(r.status))';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, $params, self::class, 'countByStatus');
        $out = [];
        foreach ($rows as $row) {
            $status = strtolower(MixedValueCoercion::stringValue($row['status'] ?? ''));
            if ($status === '') {
                $status = 'unknown';
            }
            $out[$status] = MixedValueCoercion::intValue($row['cnt'] ?? 0);
        }

        return $out;
    }

    public function sumByCurrency(LegacyExpenseFinanceFilters $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT UPPER(TRIM(COALESCE(NULLIF(r.currency, \'\'), \'AUD\'))) AS currency,'
            . ' SUM(CAST(r.total_amount AS DECIMAL(18,2))) AS total_amount,'
            . ' COUNT(*) AS receipt_count'
            . ' FROM tc.triexpense_receipts r WHERE ' . $where
            . ' GROUP BY UPPER(TRIM(COALESCE(NULLIF(r.currency, \'\'), \'AUD\')))'
            . ' ORDER BY currency ASC';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, $params, self::class, 'sumByCurrency');
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'currency' => MixedValueCoercion::stringValue($row['currency'] ?? 'AUD'),
                'totalAmount' => number_format(MixedValueCoercion::floatValue($row['total_amount'] ?? 0), 2, '.', ''),
                'receiptCount' => MixedValueCoercion::intValue($row['receipt_count'] ?? 0),
            ];
        }

        return $out;
    }

    public function countBankFeedByPeriodCountryStatus(): array
    {
        $sql = 'SELECT period_id AS periodId, country,'
            . ' match_status AS matchStatus, resolution,'
            . ' COUNT(*) AS entryCount'
            . ' FROM tc.triexpense_bank_feed'
            . ' GROUP BY period_id, country, match_status, resolution'
            . ' ORDER BY period_id DESC, country ASC';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, [], self::class, 'countBankFeedByPeriodCountryStatus');
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'periodId' => MixedValueCoercion::nullableInt($row['periodId'] ?? null),
                'country' => MixedValueCoercion::nullableString($row['country'] ?? null),
                'matchStatus' => MixedValueCoercion::nullableString($row['matchStatus'] ?? null),
                'resolution' => MixedValueCoercion::nullableString($row['resolution'] ?? null),
                'entryCount' => MixedValueCoercion::intValue($row['entryCount'] ?? 0),
            ];
        }

        return $out;
    }

    public function countPeriodsByCountryStatus(): array
    {
        $sql = 'SELECT id AS periodId, country, status, 1 AS periodCount'
            . ' FROM tc.triexpense_periods'
            . ' ORDER BY id DESC';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, [], self::class, 'countPeriodsByCountryStatus');
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'periodId' => MixedValueCoercion::intValue($row['periodId'] ?? 0),
                'country' => MixedValueCoercion::nullableString($row['country'] ?? null),
                'status' => MixedValueCoercion::nullableString($row['status'] ?? null),
                'periodCount' => 1,
            ];
        }

        return $out;
    }

    private function joinsSql(): string
    {
        return ' LEFT JOIN tc.employee e'
            . ' ON LOWER(e.user_login) = LOWER(SUBSTRING_INDEX(r.created_by COLLATE utf8mb4_unicode_ci, \'@\', 1))'
            . ' LEFT JOIN tc.triexpense_users tu'
            . ' ON LOWER(tu.user_login) = LOWER(SUBSTRING_INDEX(r.created_by COLLATE utf8mb4_unicode_ci, \'@\', 1))'
            . ' LEFT JOIN tc.employee mgr'
            . ' ON LOWER(mgr.user_login) = LOWER(SUBSTRING_INDEX(COALESCE(r.manager_login, \'\') COLLATE utf8mb4_unicode_ci, \'@\', 1))';
    }

    /**
     * @return array{0:string,1:array<string, bool|float|int|string|null>}
     */
    private function buildWhere(LegacyExpenseFinanceFilters $filters): array
    {
        $clauses = ['r.deleted_at IS NULL'];
        /** @var array<string, bool|float|int|string|null> $params */
        $params = [];

        if ($filters->excludeDraft === true) {
            $clauses[] = 'LOWER(TRIM(r.status)) <> \'draft\'';
        }

        if ($filters->dateFrom !== null && trim($filters->dateFrom) !== '') {
            $clauses[] = 'r.receipt_date >= :dateFrom';
            $params[':dateFrom'] = substr(trim($filters->dateFrom), 0, 10);
        }
        if ($filters->dateTo !== null && trim($filters->dateTo) !== '') {
            $clauses[] = 'r.receipt_date <= :dateTo';
            $params[':dateTo'] = substr(trim($filters->dateTo), 0, 10);
        }
        if ($filters->status !== null && trim($filters->status) !== '') {
            $clauses[] = 'LOWER(TRIM(r.status)) = :status';
            $params[':status'] = strtolower(trim($filters->status));
        }
        if ($filters->currency !== null && trim($filters->currency) !== '') {
            $clauses[] = 'UPPER(TRIM(COALESCE(NULLIF(r.currency, \'\'), \'AUD\'))) = :currency';
            $params[':currency'] = strtoupper(trim($filters->currency));
        }
        if ($filters->customer !== null && trim($filters->customer) !== '') {
            $clauses[] = '(LOWER(COALESCE(r.customer_code, \'\')) LIKE :customer'
                . ' OR LOWER(COALESCE(r.customer_name, \'\')) LIKE :customer)';
            $params[':customer'] = '%' . strtolower(trim($filters->customer)) . '%';
        }
        if ($filters->resolution !== null && trim($filters->resolution) !== '') {
            $res = strtolower(trim($filters->resolution));
            if ($res === 'none' || $res === 'unresolved') {
                $clauses[] = '(r.resolution IS NULL OR TRIM(r.resolution) = \'\')';
            } else {
                $clauses[] = 'LOWER(TRIM(r.resolution)) = :resolution';
                $params[':resolution'] = $res;
            }
        }
        if ($filters->unmatchedOnly === true) {
            $clauses[] = '(r.bank_feed_id IS NULL OR r.bank_feed_id = 0)'
                . ' AND (r.resolution IS NULL OR TRIM(r.resolution) = \'\' OR LOWER(TRIM(r.resolution)) = \'chased\')';
        }
        if ($filters->search !== null && trim($filters->search) !== '') {
            $clauses[] = '(LOWER(COALESCE(r.vendor_name, \'\')) LIKE :search'
                . ' OR LOWER(COALESCE(r.created_by, \'\')) LIKE :search'
                . ' OR LOWER(COALESCE(r.description, \'\')) LIKE :search'
                . ' OR LOWER(COALESCE(r.cost_centre, \'\')) LIKE :search)';
            $params[':search'] = '%' . strtolower(trim($filters->search)) . '%';
        }

        $ownerKeys = $this->normalizeKeys($filters->ownerKeys ?? []);
        if ($ownerKeys !== []) {
            [$placeholders, $keyParams] = $this->bindKeys($ownerKeys);
            $clauses[] = 'LOWER(TRIM(r.created_by)) IN (' . $placeholders . ')';
            $params = array_merge($params, $keyParams);
        }

        return [implode(' AND ', $clauses), $params];
    }

    /**
     * @param list<string> $ownerKeys
     * @return list<string>
     */
    private function normalizeKeys(array $ownerKeys): array
    {
        $out = [];
        foreach ($ownerKeys as $key) {
            $normalized = strtolower(trim($key));
            if ($normalized !== '') {
                $out[$normalized] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * @param list<string> $keys
     * @return array{0:string,1:array<string, string>}
     */
    private function bindKeys(array $keys): array
    {
        $placeholders = [];
        $params = [];
        foreach ($keys as $i => $key) {
            $name = ':ok' . $i;
            $placeholders[] = $name;
            $params[$name] = $key;
        }

        return [implode(',', $placeholders), $params];
    }
}
