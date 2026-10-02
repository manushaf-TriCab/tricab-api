<?php

declare(strict_types=1);

namespace TriCab\Api\Rfq\Legacy;

use Throwable;
use TriCab\Api\Infrastructure\Cache\ApplicationCache;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;
use TriCab\Core\Rfq\Contract\RfqDefaultMargins;

/**
 * 4js's default margins from the legacy mirror, read only: the source's
 * `matcher/default_margin.py` (`default_margins_for_customer`), which mirrors
 * the quote item path in M111qotia / M011pro.
 *
 * The margin depends only on the customer's context and the product's style
 * group, so it is looked up once per style and shared by every code in it.
 * `tmktm.mgnr_hi` is chosen by customer country, market and territory; a row
 * for this customer beats one for the territory, which beats one for the
 * whole market. Answers are cached for five minutes
 * (`TRICAB_DEFAULT_MARGIN_CACHE_SECONDS` in the source).
 *
 * Soft on failure: nothing is cached and nothing is answered.
 */
final class LegacyRfqDefaultMargins implements RfqDefaultMargins
{
    public const CACHE_SECONDS = 300;

    /** What `forCustomer` answers for a style 4js has a group for but no rule: the parser's cost-value margin. */
    public const COST_RULE = RfqDefaultMargins::COST_RULE;

    public function __construct(
        private readonly LegacyReadOnlyConnection $legacy,
        private readonly ApplicationCache $cache,
    ) {
    }

    public function forCustomer(int $customerId, array $styles): array
    {
        $wanted = array_values(array_unique(array_filter($styles, static fn (string $style): bool => $style !== '')));
        if ($customerId < 1 || $wanted === []) {
            return [];
        }

        $byStyle = [];
        $missing = [];
        foreach ($wanted as $style) {
            $hit = $this->cached($customerId, $style);
            if ($hit === false) {
                $missing[] = $style;
            } elseif ($hit !== null) {
                $byStyle[$style] = $hit;
            }
        }
        if ($missing !== []) {
            try {
                $found = $this->lookup($customerId, $missing);
            } catch (Throwable $failure) {
                error_log(sprintf('RFQ default margins unavailable for customer %d: %s', $customerId, $failure::class));

                return self::byCode($styles, $byStyle);
            }
            foreach ($missing as $style) {
                $this->remember($customerId, $style, $found[$style] ?? null);
                if (isset($found[$style])) {
                    $byStyle[$style] = $found[$style];
                }
            }
        }

        return self::byCode($styles, $byStyle);
    }

    /**
     * @param array<string, string> $styles
     * @param array<string, int|string> $byStyle
     *
     * @return array<string, int|string>
     */
    private static function byCode(array $styles, array $byStyle): array
    {
        $out = [];
        foreach ($styles as $code => $style) {
            if (isset($byStyle[$style])) {
                $out[$code] = $byStyle[$style];
            }
        }

        return $out;
    }

    /**
     * @param list<string> $styles
     *
     * @return array<string, int|string> style => margin or COST_RULE, for styles 4js has a group for
     */
    private function lookup(int $customerId, array $styles): array
    {
        $customer = $this->legacy->query(
            'SELECT t.cntry AS cntry, c.country AS country, c.market AS market, c.territory AS territory
             FROM tc.tcus t LEFT JOIN tc.cust c ON c.id = t.id WHERE t.id = :customer LIMIT 1',
            ['customer' => $customerId],
            self::class,
            'customer',
        )[0] ?? null;
        if ($customer === null) {
            return [];
        }
        $countryId = $this->positive($customer['cntry'] ?? null) ?? $this->dimension($customer['country'] ?? null, 'cntry');
        $marketId = $this->dimension($customer['market'] ?? null, 'mkt');
        $territoryId = $this->dimension($customer['territory'] ?? null, 'ter');
        if ($countryId === null || $marketId === null) {
            return [];
        }

        $params = [];
        foreach ($styles as $i => $style) {
            $params['style' . $i] = $style;
        }
        $marks = implode(', ', array_map(static fn (string $name): string => ':' . $name, array_keys($params)));
        $ids = [];
        foreach ($this->legacy->query(
            "SELECT desc_id, desc_code FROM tc.descript
             WHERE desc_code IN ({$marks}) AND desc_id IN (SELECT DISTINCT stylg FROM tc.tmktm WHERE stylg IS NOT NULL)",
            $params,
            self::class,
            'styles',
        ) as $row) {
            $id = $this->positive($row['desc_id'] ?? null);
            if ($id !== null && is_string($row['desc_code'] ?? null)) {
                $ids[strtoupper(trim($row['desc_code']))] = $id;
            }
        }

        $out = [];
        foreach ($styles as $style) {
            $styleId = $ids[$style] ?? null;
            if ($styleId === null) {
                continue;
            }
            $row = $this->legacy->query(
                'SELECT mgnr_hi FROM tc.tmktm
                 WHERE stylg = :style AND cntry = :country AND mkt = :market AND (ter = :territory OR ter = 0 OR ter IS NULL) AND (cust = :customer OR cust IS NULL)
                 ORDER BY CASE WHEN cust = :customerFirst THEN 0 ELSE 1 END, CASE WHEN ter = :territoryFirst THEN 0 ELSE 1 END
                 LIMIT 1',
                [
                    'style' => $styleId,
                    'country' => $countryId,
                    'market' => $marketId,
                    'territory' => $territoryId,
                    'customer' => $customerId,
                    'customerFirst' => $customerId,
                    'territoryFirst' => $territoryId,
                ],
                self::class,
                'margin',
            )[0] ?? null;
            // 4js shows the truncated margin it derives, so a fraction is never kept. A style group
            // the customer has no rule for falls back to the margin 4js sets by the line's cost
            // value (`x_cval2mgnr`), which needs the price and so is the parser's to work out.
            $out[$style] = $row !== null && is_numeric($row['mgnr_hi'] ?? null)
                ? max(0, (int) floor((float) $row['mgnr_hi']))
                : self::COST_RULE;
        }

        return $out;
    }

    private function dimension(mixed $code, string $column): ?int
    {
        $code = is_string($code) ? strtoupper(trim($code)) : '';
        if ($code === '' || !in_array($column, ['cntry', 'mkt', 'ter'], true)) {
            return null;
        }
        $row = $this->legacy->query(
            "SELECT d.desc_id AS desc_id FROM tc.descript d
             WHERE d.desc_code = :code AND d.desc_id IN (SELECT DISTINCT {$column} FROM tc.tmktm WHERE {$column} IS NOT NULL AND {$column} != 0)
             LIMIT 1",
            ['code' => $code],
            self::class,
            'dimension',
        )[0] ?? null;

        return $row === null ? null : $this->positive($row['desc_id'] ?? null);
    }

    private function positive(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @return int|string|null|false the cached margin, null for "no group", false for "not cached" */
    private function cached(int $customerId, string $style): int|string|null|false
    {
        try {
            $hit = $this->cache->get(self::key($customerId, $style));
        } catch (Throwable) {
            return false;
        }
        if ($hit === 'none') {
            return null;
        }

        return is_int($hit) || $hit === self::COST_RULE ? $hit : false;
    }

    private function remember(int $customerId, string $style, int|string|null $margin): void
    {
        try {
            $this->cache->set(self::key($customerId, $style), $margin ?? 'none', self::CACHE_SECONDS);
        } catch (Throwable) {
            // A cache that is down costs a legacy read next time.
        }
    }

    private static function key(int $customerId, string $style): string
    {
        return 'rfq.default-margin.v1.' . $customerId . '.' . md5($style);
    }
}
