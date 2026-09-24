<?php

declare(strict_types=1);

namespace TriCab\Core\PriceLookup\Application\Service;

use TriCab\Core\PriceLookup\Application\DTO\PriceLookupSearchHit;
use TriCab\Core\PriceLookup\Application\DTO\PriceLookupSearchResult;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupForbidden;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupProductNotFound;
use TriCab\Core\PriceLookup\Application\Exception\PriceLookupValidationException;
use TriCab\Core\PriceLookup\Contract\PriceLookupPriceRepository;
use TriCab\Core\PriceLookup\Domain\MakeStockStatus;
use TriCab\Core\PriceLookup\Domain\PriceLookupColourMask;
use TriCab\Core\PriceLookup\Domain\PriceLookupDbcoMapper;
use TriCab\Core\PriceLookup\Domain\PriceLookupSearchPattern;
use TriCab\Core\PriceLookup\Module;

final class SearchPriceLookupService
{
    public function __construct(
        private readonly PriceLookupPriceRepository $prices,
        private readonly PriceLookupDbcoMapper $dbcoMapper = new PriceLookupDbcoMapper(),
        private readonly PriceLookupSearchPattern $patterns = new PriceLookupSearchPattern(),
        private readonly PriceLookupColourMask $colourMask = new PriceLookupColourMask(),
        private readonly MakeStockStatus $makeStock = new MakeStockStatus(),
    ) {
    }

    /**
     * @param list<string> $actorPermissions
     */
    public function execute(string $q, string $company, array $actorPermissions): PriceLookupSearchResult
    {
        $companyCode = $this->dbcoMapper->assertKnownCompany($company);
        $dbco = $this->dbcoMapper->toDbco($companyCode);
        $query = trim($q);

        if ($query === '') {
            throw PriceLookupValidationException::invalidQuery();
        }

        if ($query === '*') {
            if (!$this->hasPermission($actorPermissions, Module::PERMISSION_FULL_LIST)) {
                throw new PriceLookupForbidden(
                    'Full product list requires ' . Module::PERMISSION_FULL_LIST . '.',
                );
            }

            $rows = $this->prices->searchByPrefix($dbco, '%', Module::FULL_LIST_LIMIT);

            return $this->toResult($rows, $companyCode, $query);
        }

        $like = $this->patterns->toLikePattern($query);
        $rows = $this->prices->searchByPrefix($dbco, $like, Module::SEARCH_LIMIT);

        if ($rows === []) {
            $masked = $this->colourMask->maskForLookup($query);
            if ($masked !== $query) {
                $maskedLike = $this->patterns->toLikePattern($masked);
                error_log('[price-lookup] first query empty, trying mask pattern for company=' . $companyCode);
                $rows = $this->prices->searchByPrefix($dbco, $maskedLike, Module::SEARCH_LIMIT);
            }
        }

        if ($rows === []) {
            throw new PriceLookupProductNotFound($query);
        }

        return $this->toResult($rows, $companyCode, $query);
    }

    /**
     * @param list<array{
     *     productCode: string,
     *     cost: string,
     *     setupLength: string|null,
     *     lastUpdated: string|null,
     *     warehouseStatus: mixed
     * }> $rows
     */
    private function toResult(array $rows, string $company, string $query): PriceLookupSearchResult
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = new PriceLookupSearchHit(
                productCode: $row['productCode'],
                status: $this->makeStock->fromWarehouseStatus($row['warehouseStatus']),
                setupLength: $row['setupLength'],
                lastUpdated: $row['lastUpdated'],
            );
        }

        return new PriceLookupSearchResult($items, count($items), $company, $query);
    }

    /**
     * @param list<string> $permissions
     */
    private function hasPermission(array $permissions, string $required): bool
    {
        $need = strtoupper($required);
        foreach ($permissions as $permission) {
            if (strtoupper(trim($permission)) === $need) {
                return true;
            }
        }

        return false;
    }
}
