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

}
