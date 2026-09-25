<?php

declare(strict_types=1);

namespace TriCab\Api\Expense\Legacy;

use TriCab\Core\Expense\Application\DTO\LegacyExpenseFinanceBankFilters;
use TriCab\Core\Expense\Application\Support\MixedValueCoercion;
use TriCab\Core\Expense\Contract\LegacyExpenseFinanceBankReadRepository;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlySqlGuard;

/**
 * Finance-authorized SELECT-only adapter over tc.triexpense_bank_feed + tc.triexpense_periods.
 *
 * Org-wide queries are intentional — callers MUST gate with requireFinance.
 */
final class MysqlLegacyExpenseFinanceBankReadRepository implements LegacyExpenseFinanceBankReadRepository
{
    private const BANK_COLUMNS = <<<'SQL'
b.id, b.bank_reference, b.cardholder_email, b.country, b.first_name, b.last_name,
b.transaction_date, b.posting_date, b.statement_date,
b.merchant_name, b.merchant_city, b.merchant_country, b.merchant_postcode, b.merchant_category_group,
b.amount, b.currency, b.foreign_amount, b.foreign_currency, b.net_amount, b.tax_amount,
b.transaction_type, b.match_status, b.receipt_id, b.uploaded_at, b.uploaded_by, b.period_id,
b.resolution, b.resolution_note, b.offset_tx_id, b.resolved_by, b.resolved_at
SQL;

    private const PERIOD_COLUMNS = <<<'SQL'
p.id, p.country, p.period_start, p.period_end, p.tx_start, p.tx_end,
p.status, p.closed_at, p.closed_by, p.created_at
SQL;

    public function __construct(
        private readonly LegacyReadOnlyConnection $connection,
        private readonly LegacyReadOnlySqlGuard $guard,
    ) {
    }

    public function findBankById(int $legacyBankFeedId): ?array
    {
        if ($legacyBankFeedId <= 0) {
            return null;
        }
        $sql = 'SELECT ' . self::BANK_COLUMNS
            . ' FROM tc.triexpense_bank_feed b'
            . ' WHERE b.id = :id LIMIT 1';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, [':id' => $legacyBankFeedId], self::class, 'findBankById');

        return $rows[0] ?? null;
    }

    public function listBank(LegacyExpenseFinanceBankFilters $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT ' . self::BANK_COLUMNS
            . ' FROM tc.triexpense_bank_feed b'
            . ' WHERE ' . $where
            . ' ORDER BY b.transaction_date DESC, b.id DESC'
            . ' LIMIT ' . $filters->normalizedLimit()
            . ' OFFSET ' . $filters->normalizedOffset();
        $this->guard->assertReadOnly($sql);

        return $this->connection->query($sql, $params, self::class, 'listBank');
    }

    public function countBank(LegacyExpenseFinanceBankFilters $filters): int
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT COUNT(*) AS cnt FROM tc.triexpense_bank_feed b WHERE ' . $where;
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, $params, self::class, 'countBank');

        return MixedValueCoercion::intValue($rows[0]['cnt'] ?? 0);
    }

    public function sumBankByCurrency(LegacyExpenseFinanceBankFilters $filters): array
    {
        [$where, $params] = $this->buildWhere($filters);
        $sql = 'SELECT UPPER(TRIM(COALESCE(NULLIF(b.currency, \'\'), \'AUD\'))) AS currency,'
            . ' SUM(CAST(b.amount AS DECIMAL(18,2))) AS total_amount,'
            . ' COUNT(*) AS entry_count'
            . ' FROM tc.triexpense_bank_feed b WHERE ' . $where
            . ' GROUP BY UPPER(TRIM(COALESCE(NULLIF(b.currency, \'\'), \'AUD\')))'
            . ' ORDER BY currency ASC';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, $params, self::class, 'sumBankByCurrency');
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'currency' => MixedValueCoercion::stringValue($row['currency'] ?? 'AUD'),
                'totalAmount' => number_format(MixedValueCoercion::floatValue($row['total_amount'] ?? 0), 2, '.', ''),
                'entryCount' => MixedValueCoercion::intValue($row['entry_count'] ?? 0),
            ];
        }

        return $out;
    }

    public function findPeriodById(int $legacyPeriodId): ?array
    {
        if ($legacyPeriodId <= 0) {
            return null;
        }
        $sql = 'SELECT ' . self::PERIOD_COLUMNS
            . ' FROM tc.triexpense_periods p'
            . ' WHERE p.id = :id LIMIT 1';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, [':id' => $legacyPeriodId], self::class, 'findPeriodById');

        return $rows[0] ?? null;
    }

    public function listPeriods(?string $country): array
    {
        /** @var array<string, bool|float|int|string|null> $params */
        $params = [];
        $where = '';
        if ($country !== null && trim($country) !== '') {
            $where = ' WHERE UPPER(TRIM(p.country)) = :country';
            $params[':country'] = strtoupper(trim($country));
        }
        $sql = 'SELECT ' . self::PERIOD_COLUMNS
            . ' FROM tc.triexpense_periods p'
            . $where
            . ' ORDER BY p.period_end DESC, p.id DESC';
        $this->guard->assertReadOnly($sql);

        return $this->connection->query($sql, $params, self::class, 'listPeriods');
    }

    public function countBankByPeriodCountryMatchResolution(): array
    {
        $sql = 'SELECT period_id AS periodId, country,'
            . ' match_status AS matchStatus, resolution,'
            . ' COUNT(*) AS entryCount'
            . ' FROM tc.triexpense_bank_feed'
            . ' GROUP BY period_id, country, match_status, resolution'
            . ' ORDER BY period_id DESC, country ASC';
        $this->guard->assertReadOnly($sql);
        $rows = $this->connection->query($sql, [], self::class, 'countBankByPeriodCountryMatchResolution');
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

    /**
     * @return array{0:string,1:array<string, bool|float|int|string|null>}
     */
    private function buildWhere(LegacyExpenseFinanceBankFilters $filters): array
    {
        $clauses = ['1 = 1'];
        /** @var array<string, bool|float|int|string|null> $params */
        $params = [];

        if ($filters->periodId !== null && $filters->periodId > 0) {
            $clauses[] = 'b.period_id = :periodId';
            $params[':periodId'] = $filters->periodId;
        }
        if ($filters->country !== null && trim($filters->country) !== '') {
            $clauses[] = 'UPPER(TRIM(b.country)) = :country';
            $params[':country'] = strtoupper(trim($filters->country));
        }
        if ($filters->dateFrom !== null && trim($filters->dateFrom) !== '') {
            $clauses[] = 'b.transaction_date >= :dateFrom';
            $params[':dateFrom'] = substr(trim($filters->dateFrom), 0, 10);
        }
        if ($filters->dateTo !== null && trim($filters->dateTo) !== '') {
            $clauses[] = 'b.transaction_date <= :dateTo';
            $params[':dateTo'] = substr(trim($filters->dateTo), 0, 10);
        }
        if ($filters->currency !== null && trim($filters->currency) !== '') {
            $clauses[] = 'UPPER(TRIM(COALESCE(NULLIF(b.currency, \'\'), \'AUD\'))) = :currency';
            $params[':currency'] = strtoupper(trim($filters->currency));
        }
        if ($filters->matchStatus !== null && trim($filters->matchStatus) !== '') {
            $clauses[] = 'LOWER(TRIM(b.match_status)) = :matchStatus';
            $params[':matchStatus'] = strtolower(trim($filters->matchStatus));
        }
        if ($filters->resolution !== null && trim($filters->resolution) !== '') {
            $res = strtolower(trim($filters->resolution));
            if ($res === 'none' || $res === 'unresolved') {
                $clauses[] = '(b.resolution IS NULL OR TRIM(b.resolution) = \'\')';
            } else {
                $clauses[] = 'LOWER(TRIM(b.resolution)) = :resolution';
                $params[':resolution'] = $res;
            }
        }
        if ($filters->cardholderEmail !== null && trim($filters->cardholderEmail) !== '') {
            $clauses[] = 'LOWER(TRIM(b.cardholder_email)) = :cardholderEmail';
            $params[':cardholderEmail'] = strtolower(trim($filters->cardholderEmail));
        }
        if ($filters->needsReceiptOnly === true) {
            $clauses[] = 'LOWER(TRIM(b.match_status)) IN (\'unmatched\', \'ambiguous\')'
                . ' AND (b.resolution IS NULL OR TRIM(b.resolution) = \'\' OR LOWER(TRIM(b.resolution)) = \'chased\')';
        }
        if ($filters->search !== null && trim($filters->search) !== '') {
            $clauses[] = '(LOWER(COALESCE(b.merchant_name, \'\')) LIKE :search'
                . ' OR LOWER(COALESCE(b.cardholder_email, \'\')) LIKE :search'
                . ' OR LOWER(COALESCE(b.bank_reference, \'\')) LIKE :search'
                . ' OR LOWER(COALESCE(b.merchant_city, \'\')) LIKE :search)';
            $params[':search'] = '%' . strtolower(trim($filters->search)) . '%';
        }

        return [implode(' AND ', $clauses), $params];
    }
}
