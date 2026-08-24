<?php

declare(strict_types=1);

namespace TriCab\Core\Tests\Product\Doubles;

use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;

/**
 * In-memory legacy tc.price fixture for identity resolver tests.
 */
final class FakeLegacyTcPricingRepository implements LegacyTcRepository
{
    /** @var array<string, array<string, list<array{cost: string, dc: ?string}>>> dbco => code => rows */
    private array $prices = [];

    public function seedPrice(int $dbco, string $code, string $cost, ?string $dc = '2024-01-01'): void
    {
        $this->prices[(string) $dbco][strtoupper($code)][] = ['cost' => $cost, 'dc' => $dc];
    }

    public function findVariants(array $filter = []): array
    {
        unset($filter);

        return [];
    }

    public function findSpecifications(array $filter = []): array
    {
        unset($filter);

        return [];
    }

    public function findCodes(array $filter = []): array
    {
        unset($filter);

        return [];
    }

    public function findAccessories(array $filter = []): array
    {
        unset($filter);

        return [];
    }

    public function findDescriptions(array $filter = []): array
    {
        unset($filter);

        return [];
    }

    public function inspectSchema(): array
    {
        return ['tables' => ['price'], 'columns' => ['price' => ['code', 'dbco', 'cost', 'dc']], 'rowCounts' => ['price' => 0]];
    }

    public function findLatestBaseCost(string $itemCode, int $debtorCompanyCode): ?array
    {
        $inspection = $this->inspectPricingCode($itemCode, $debtorCompanyCode);
        if ($inspection === null || !$inspection['exists'] || $inspection['latestCost'] === null) {
            return null;
        }

        return [
            'cost' => $inspection['latestCost'],
            'lastUpdatedUtc' => $inspection['latestDc'],
        ];
    }

    public function aggregatePriceContextByDbco(int $dbco): ?array
    {
        $codes = $this->prices[(string) $dbco] ?? [];
        $count = 0;
        foreach ($codes as $rows) {
            $count += count($rows);
        }

        return [
            'dbco' => $dbco,
            'priceRowCount' => $count,
            'distinctCodeCount' => count($codes),
        ];
    }

    public function inspectPricingCode(string $code, int $dbco): ?array
    {
        $batch = $this->inspectPricingCodes([$code], $dbco);

        return $batch[strtoupper($code)] ?? [
            'exists' => false,
            'revisionCount' => 0,
            'latestDc' => null,
            'latestCost' => null,
            'zeroCost' => false,
            'duplicateLatestDc' => false,
        ];
    }

    public function inspectPricingCodes(array $codes, int $dbco): array
    {
        $out = [];
        foreach ($codes as $code) {
            $normalized = strtoupper($code);
            $rows = $this->prices[(string) $dbco][$normalized] ?? [];
            if ($rows === []) {
                $out[$normalized] = [
                    'exists' => false,
                    'revisionCount' => 0,
                    'latestDc' => null,
                    'latestCost' => null,
                    'zeroCost' => false,
                    'duplicateLatestDc' => false,
                ];
                continue;
            }

            usort($rows, static fn (array $a, array $b): int => strcmp((string) ($b['dc'] ?? ''), (string) ($a['dc'] ?? '')));
            $latestDc = $rows[0]['dc'] ?? null;
            $latestCost = $rows[0]['cost'];
            $sameDcRows = array_values(array_filter($rows, static fn (array $row): bool => ($row['dc'] ?? null) === $latestDc));
            $distinctCosts = count(array_unique(array_map(static fn (array $row): string => $row['cost'], $sameDcRows)));

            $out[$normalized] = [
                'exists' => true,
                'revisionCount' => count($rows),
                'latestDc' => $latestDc,
                'latestCost' => $latestCost,
                'zeroCost' => bccomp($latestCost, '0', 6) === 0,
                'duplicateLatestDc' => count($sameDcRows) > 1 && $distinctCosts > 1,
            ];
        }

        return $out;
    }

    public function discoverProductRelatedTables(): array
    {
        return [];
    }

    public function discoverTproVsTproExt(): array
    {
        return [];
    }

    public function discoverVariantCountsByStyle(): array
    {
        return [];
    }

    public function discoverSpecLabels(): array
    {
        return [];
    }

    public function discoverStyleCodes(): array
    {
        return [];
    }

    public function discoverColumnValueCounts(string $table, string $column, int $limit = 200): array
    {
        unset($table, $column, $limit);

        return [];
    }

    public function discoverNumericCableStyles(): array
    {
        return [];
    }

    public function discoverAccessoryIdentitySummary(): array
    {
        return [];
    }

    public function discoverDvFieldMappings(int $limit = 5000): array
    {
        unset($limit);

        return [];
    }

    public function discoverAccessorySpecScopeEvidence(): array
    {
        return [];
    }

    public function discoverTproExclusionSummary(): array
    {
        return [];
    }

    public function discoverVariantReadinessSummary(): array
    {
        return [];
    }
}
