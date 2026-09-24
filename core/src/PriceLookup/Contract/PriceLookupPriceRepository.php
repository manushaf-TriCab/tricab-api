<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Contract;

/**
 * Read-only price rows for staff Price Lookup.
 */
interface PriceLookupPriceRepository
{
    /**
     * @return list<array{
     *     productCode: string,
     *     cost: string,
     *     setupLength: string|null,
     *     lastUpdated: string|null,
     *     warehouseStatus: mixed
     * }>
     */
    public function searchByPrefix(string $dbco, string $likePattern, int $limit): array;

    /**
     * @return array{
     *     productCode: string,
     *     cost: string,
     *     setupLength: string|null,
     *     lastUpdated: string|null,
     *     warehouseStatus: mixed
     * }|null
     */
    public function findByCode(string $dbco, string $code): ?array;
}
