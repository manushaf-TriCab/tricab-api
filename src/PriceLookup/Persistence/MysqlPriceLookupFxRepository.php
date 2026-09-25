<?php

declare(strict_types=1);

namespace TriCab\Api\PriceLookup\Persistence;

use TriCab\Core\PriceLookup\Contract\PriceLookupFxRepository;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;

/**
 * Legacy FX for Price Lookup currencies via descript ↔ cyrte join.
 */
final class MysqlPriceLookupFxRepository implements PriceLookupFxRepository
{
    private const SQL = <<<'SQL'
SELECT CAST(cyrte.cyrte AS CHAR) AS rate
FROM descript
INNER JOIN cyrte ON descript.desc_id = cyrte.id
WHERE descript.desc_code = :code
ORDER BY cyrte.de DESC
LIMIT 1
SQL;

    public function __construct(
        private readonly LegacyReadOnlyConnection $tc,
    ) {
    }

    public function findLatestRate(string $currencyCode): ?string
    {
        $code = strtoupper(trim($currencyCode));
        if ($code === '') {
            return null;
        }

        $rows = $this->tc->query(
            self::SQL,
            ['code' => $code],
            'price-lookup',
            'findLatestRate',
        );

        $rate = $rows[0]['rate'] ?? null;
        if ($rows === [] || !is_scalar($rate) || !is_numeric((string) $rate)) {
            return null;
        }

        return (string) $rate;
    }
}
