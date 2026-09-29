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

    /**
     * AU warehouse (wh 7) stock status for a product code, read from the product master rather
     * than tc.price. Masks aren't in tpro, so a mask-priced cable reads its own status here.
     */
    public function findWarehouseStatusByCode(string $code): mixed;

    /**
     * Every product code in the product master (tpro), upper-cased, distinct and sorted,
     * without mask codes or XX codes of styles that can't have one. Codes only: never costs or prices.
     *
     * @return list<string>
     */
    public function listProductCodes(int $limit): array;
}
