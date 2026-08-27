<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Pricing;

use TriCab\Core\Product\Application\Pricing\CableMarginPrecedenceResolver;
use TriCab\Core\Product\Application\Pricing\CustomerPricingMarginRepository;
use TriCab\Core\Product\Migration\Live\LegacyReadOnlyConnection;

/**
 * Read-only legacy tmktm margin resolution behind a dedicated repository.
 * Other layers must not query tmktm directly.
 */
final class MysqlLegacyCustomerMarginRepository implements CustomerPricingMarginRepository
{
    private const DESC_TYPE_STYLE = 24;
    private const DESC_TYPE_COUNTRY = 21;
    private const DESC_TYPE_TERRITORY = 34;
    private const DESC_TYPE_MARKET = 49;

    public function __construct(
        private readonly LegacyReadOnlyConnection $tc,
        private readonly CableMarginPrecedenceResolver $precedence = new CableMarginPrecedenceResolver(),
    ) {
    }

    public function resolveCableMargin(
        string $styleCode,
        string $countryCode,
        ?string $territoryCode,
        ?string $marketCode,
        int $coreCount,
        string $conductorCond,
    ): ?array {
        $stylg = $this->resolveStyleId($styleCode);
        $cntry = $this->resolveDescriptId($countryCode, self::DESC_TYPE_COUNTRY);
        if ($stylg === null || $cntry === null) {
            return null;
        }

        $ter = $territoryCode !== null && $territoryCode !== ''
            ? $this->resolveTerritoryId($territoryCode)
            : null;
        $mkt = $marketCode !== null && $marketCode !== ''
            ? $this->resolveMarketId($marketCode)
            : null;

        $cond = strtolower($conductorCond) === 'a' ? 'a' : 'p';
        $params = [
            'cntry' => $cntry,
            'pt' => CableMarginPrecedenceResolver::PT_CABLE,
            'cond' => $cond,
            'stylg' => $stylg,
        ];
        $geo = '((`ter` IS NULL OR `ter` IN (0, 3400)) AND (`mkt` IS NULL OR `mkt` IN (0, 4900)))';
        if ($ter !== null) {
            $params['terMatch'] = $ter;
            $geo .= ' OR (`ter` = :terMatch AND (`mkt` IS NULL OR `mkt` IN (0, 4900)))';
        }
        if ($mkt !== null) {
            $params['mktMatch'] = $mkt;
            $geo .= ' OR (`mkt` = :mktMatch AND (`ter` IS NULL OR `ter` IN (0, 3400)))';
        }
        if ($ter !== null && $mkt !== null) {
            $params['terBoth'] = $ter;
            $params['mktBoth'] = $mkt;
            $geo .= ' OR (`ter` = :terBoth AND `mkt` = :mktBoth)';
        }

        $rows = $this->tc->query(
            "SELECT stylg, ter, mkt, ncond, mgnr_lo
             FROM `tmktm`
             WHERE `cntry` = :cntry
               AND `pt` = :pt
               AND `cond` = :cond
               AND (`stylg` = :stylg OR `stylg` = 0 OR `stylg` IS NULL)
               AND ({$geo})
             LIMIT 200",
            $params,
            'customer-pricing',
            'tmktm-candidates',
        );

        $classified = [];
        foreach ($rows as $row) {
            $rowStyle = isset($row['stylg']) ? (int) $row['stylg'] : 0;
            $rowTer = isset($row['ter']) && $row['ter'] !== null && (int) $row['ter'] !== 0 && (int) $row['ter'] !== 3400
                ? (int) $row['ter']
                : null;
            $rowMkt = isset($row['mkt']) && $row['mkt'] !== null && (int) $row['mkt'] !== 0 && (int) $row['mkt'] !== 4900
                ? (int) $row['mkt']
                : null;

            $level = match (true) {
                $rowTer !== null && $rowMkt !== null => 'termkt',
                $rowTer !== null => 'ter',
                $rowMkt !== null => 'mkt',
                default => 'ct',
            };

            $margin = (string) ($row['mgnr_lo'] ?? '');
            if ($margin === '' || !is_numeric($margin)) {
                continue;
            }

            $classified[] = [
                'withStyle' => $rowStyle === $stylg,
                'level' => $level,
                'ncond' => isset($row['ncond']) ? (int) $row['ncond'] : 0,
                'marginPercent' => $margin,
            ];
        }

        $resolved = $this->precedence->resolve($classified, $coreCount);
        if ($resolved === null || $resolved['marginPercent'] === '' || !is_numeric($resolved['marginPercent'])) {
            return null;
        }

        return [
            'marginPercent' => $resolved['marginPercent'],
            'status' => 'OK',
            'ruleLevel' => $resolved['ruleLevel'],
            'coreClass' => $resolved['coreClass'],
        ];
    }

    private function resolveStyleId(string $styleCode): ?int
    {
        $code = strtoupper(trim($styleCode));
        if ($code === '') {
            return null;
        }
        $exact = $this->resolveDescriptId($code, self::DESC_TYPE_STYLE);
        if ($exact !== null) {
            return $exact;
        }
        // Legacy often stores 2-char style codes (BVA → BV).
        if (strlen($code) > 2) {
            return $this->resolveDescriptId(substr($code, 0, 2), self::DESC_TYPE_STYLE);
        }

        return null;
    }

    private function resolveTerritoryId(string $code): ?int
    {
        $raw = strtoupper(trim($code));
        $id = $this->resolveDescriptId($raw, self::DESC_TYPE_TERRITORY);
        if ($id !== null) {
            return $id;
        }
        // VIC-METRO → VIC
        if (str_contains($raw, '-')) {
            return $this->resolveDescriptId(explode('-', $raw, 2)[0], self::DESC_TYPE_TERRITORY);
        }

        return null;
    }

    private function resolveMarketId(string $code): ?int
    {
        $raw = strtoupper(trim($code));
        if ($raw !== '' && ctype_digit($raw)) {
            return (int) $raw;
        }
        $id = $this->resolveDescriptId($raw, self::DESC_TYPE_MARKET);
        if ($id !== null) {
            return $id;
        }
        // Azure / new-web long codes → legacy desc_code where known.
        $aliases = [
            'ELECTRICAL' => 'ELE',
            'INDUSTRIAL' => 'IND',
            'CONSULTANTS' => 'CONSULT1',
            'CONSULTANT' => 'CONSULT1',
        ];
        if (isset($aliases[$raw])) {
            $id = $this->resolveDescriptId($aliases[$raw], self::DESC_TYPE_MARKET);
            if ($id !== null) {
                return $id;
            }
        }
        // ELECTRICAL → ELE (first 3 chars) when alias table misses.
        if (strlen($raw) > 3) {
            return $this->resolveDescriptId(substr($raw, 0, 3), self::DESC_TYPE_MARKET);
        }

        return null;
    }

    private function resolveDescriptId(string $code, int $descType): ?int
    {
        $rows = $this->tc->query(
            'SELECT `desc_id` FROM `descript` WHERE `desc_code` = :c AND `desc_type` = :t LIMIT 1',
            ['c' => strtoupper(trim($code)), 't' => $descType],
            'customer-pricing',
            'descript',
        );
        if ($rows === []) {
            return null;
        }

        return (int) $rows[0]['desc_id'];
    }
}
