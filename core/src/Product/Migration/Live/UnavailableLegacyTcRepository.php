<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Live;

use TriCab\Core\Product\Migration\Contract\LegacyTcRepository;

final class UnavailableLegacyTcRepository implements LegacyTcRepository
{
    public function findVariants(array $filter = []): array
    {
        return [];
    }

    public function findSpecifications(array $filter = []): array
    {
        return [];
    }

    public function findCodes(array $filter = []): array
    {
        return [];
    }

    public function findAccessories(array $filter = []): array
    {
        return [];
    }

    public function findDescriptions(array $filter = []): array
    {
        return [];
    }

    public function inspectSchema(): array
    {
        return ['tables' => [], 'columns' => [], 'rowCounts' => []];
    }

    public function findLatestBaseCost(string $itemCode, int $debtorCompanyCode): ?array
    {
        unset($itemCode, $debtorCompanyCode);

        return null;
    }

    public function aggregatePriceContextByDbco(int $dbco): ?array
    {
        unset($dbco);

        return null;
    }

    public function inspectPricingCode(string $code, int $dbco): ?array
    {
        unset($code, $dbco);

        return null;
    }

    public function inspectPricingCodes(array $codes, int $dbco): array
    {
        unset($codes, $dbco);

        return [];
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
