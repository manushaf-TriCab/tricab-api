<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Migration\Contract;

/**
 * Strictly read-only tc product inspection API. No save/update/delete.
 */
interface LegacyTcRepository
{
    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findVariants(array $filter = []): array;

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findSpecifications(array $filter = []): array;

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findCodes(array $filter = []): array;

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findAccessories(array $filter = []): array;

    /**
     * @param array<string, mixed> $filter
     * @return list<array<string, mixed>>
     */
    public function findDescriptions(array $filter = []): array;

    /**
     * @return array{tables: list<string>, columns: array<string, list<string>>, rowCounts: array<string, int>}
     */
    public function inspectSchema(): array;

    /**
     * Read-only latest tc.price.cost for an item code and debtor company (dbco).
     *
     * @return array{cost: string, lastUpdatedUtc: ?string}|null
     */
    public function findLatestBaseCost(string $itemCode, int $debtorCompanyCode): ?array;
}
