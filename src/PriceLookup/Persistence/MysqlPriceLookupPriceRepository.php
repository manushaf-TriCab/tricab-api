<?php

declare(strict_types=1);

namespace TriCab\Api\PriceLookup\Persistence;

use TriCab\Core\PriceLookup\Contract\PriceLookupPriceRepository;
use TriCab\Core\PriceLookup\Domain\PriceLookupColourMask;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;

/**
 * Read-only MySQL tc.price (+ stock joins) for staff Price Lookup.
 */
final class MysqlPriceLookupPriceRepository implements PriceLookupPriceRepository
{
    private const SEARCH_SQL = <<<'SQL'
SELECT
    p.code AS productCode,
    CAST(p.cost AS CHAR) AS cost,
    CAST(p.setup AS CHAR) AS setupLength,
    DATE_FORMAT(p.dc, '%D %b\'%y') AS lastUpdated,
    tp_au.st AS warehouseStatus
FROM price p
LEFT JOIN tpro t ON p.code = t.ida
LEFT JOIN tpro tman ON t.prob = tman.id
LEFT JOIN tstkwh_p tp_au ON tman.id = tp_au.stk AND tp_au.wh = 7
WHERE p.code LIKE :pattern
  AND p.dbco = :dbco
  AND p.code NOT REGEXP :maskPattern
GROUP BY p.code, p.cost, p.setup, p.dc, tp_au.st
ORDER BY p.code ASC
LIMIT :limit
SQL;

    private const FIND_SQL = <<<'SQL'
SELECT
    p.code AS productCode,
    CAST(p.cost AS CHAR) AS cost,
    CAST(p.setup AS CHAR) AS setupLength,
    DATE_FORMAT(p.dc, '%D %b\'%y') AS lastUpdated,
    tp_au.st AS warehouseStatus
FROM price p
LEFT JOIN tpro t ON p.code = t.ida
LEFT JOIN tpro tman ON t.prob = tman.id
LEFT JOIN tstkwh_p tp_au ON tman.id = tp_au.stk AND tp_au.wh = 7
WHERE p.code = :code
  AND p.dbco = :dbco
GROUP BY p.code, p.cost, p.setup, p.dc, tp_au.st
LIMIT 1
SQL;

    private const WAREHOUSE_STATUS_SQL = <<<'SQL'
SELECT tp_au.st AS warehouseStatus
FROM tpro t
LEFT JOIN tpro tman ON t.prob = tman.id
LEFT JOIN tstkwh_p tp_au ON tman.id = tp_au.stk AND tp_au.wh = 7
WHERE t.ida = :code
LIMIT 1
SQL;

    private const PRODUCT_CODES_SQL = <<<'SQL'
SELECT DISTINCT UPPER(TRIM(t.ida)) AS productCode
FROM tpro t
WHERE t.ida IS NOT NULL
  AND TRIM(t.ida) <> ''
  AND UPPER(TRIM(t.ida)) NOT REGEXP :maskPattern
ORDER BY productCode ASC
LIMIT :limit
SQL;

    public function __construct(
        private readonly LegacyReadOnlyConnection $tc,
    ) {
    }

    public function searchByPrefix(string $dbco, string $likePattern, int $limit): array
    {
        $rows = $this->tc->query(
            self::SEARCH_SQL,
            [
                'pattern' => $likePattern,
                'dbco' => $dbco,
                'maskPattern' => PriceLookupColourMask::SQL_MASK_PATTERN,
                'limit' => max(1, $limit),
            ],
            'price-lookup',
            'searchByPrefix',
        );

        return array_map([$this, 'mapRow'], $rows);
    }

    public function findByCode(string $dbco, string $code): ?array
    {
        $rows = $this->tc->query(
            self::FIND_SQL,
            [
                'code' => $code,
                'dbco' => $dbco,
            ],
            'price-lookup',
            'findByCode',
        );

        if ($rows === []) {
            return null;
        }

        return $this->mapRow($rows[0]);
    }

    public function findWarehouseStatusByCode(string $code): mixed
    {
        $rows = $this->tc->query(
            self::WAREHOUSE_STATUS_SQL,
            ['code' => $code],
            'price-lookup',
            'findWarehouseStatusByCode',
        );

        return $rows[0]['warehouseStatus'] ?? null;
    }

    public function listProductCodes(int $limit): array
    {
        $rows = $this->tc->query(
            self::PRODUCT_CODES_SQL,
            [
                'maskPattern' => PriceLookupColourMask::SQL_MASK_PATTERN,
                'limit' => max(1, $limit),
            ],
            'price-lookup',
            'listProductCodes',
        );

        $codes = [];
        foreach ($rows as $row) {
            $code = $row['productCode'] ?? null;
            if (is_scalar($code) && (string) $code !== '') {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{
     *     productCode: string,
     *     cost: string,
     *     setupLength: string|null,
     *     lastUpdated: string|null,
     *     warehouseStatus: mixed
     * }
     */
    private function mapRow(array $row): array
    {
        $productCode = $row['productCode'] ?? '';
        $cost = $row['cost'] ?? '0';
        $setup = $row['setupLength'] ?? null;
        $updated = $row['lastUpdated'] ?? null;

        return [
            'productCode' => strtoupper(trim(is_scalar($productCode) ? (string) $productCode : '')),
            'cost' => is_scalar($cost) ? (string) $cost : '0',
            'setupLength' => is_scalar($setup) ? (string) $setup : null,
            'lastUpdated' => is_scalar($updated) ? (string) $updated : null,
            'warehouseStatus' => $row['warehouseStatus'] ?? null,
        ];
    }
}
