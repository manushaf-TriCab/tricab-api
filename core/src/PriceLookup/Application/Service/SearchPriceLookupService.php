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
    private readonly PriceLookupMaskFallback $maskFallback;

    public function __construct(
        private readonly PriceLookupPriceRepository $prices,
        private readonly PriceLookupDbcoMapper $dbcoMapper = new PriceLookupDbcoMapper(),
        private readonly PriceLookupSearchPattern $patterns = new PriceLookupSearchPattern(),
        private readonly PriceLookupColourMask $colourMask = new PriceLookupColourMask(),
        private readonly MakeStockStatus $makeStock = new MakeStockStatus(),
    ) {
        $this->maskFallback = new PriceLookupMaskFallback($prices, $colourMask);
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

        if ($this->colourMask->isMask($query)) {
            throw PriceLookupValidationException::maskCodeNotAllowed($query);
        }
        if ($this->colourMask->isXxCodeOfOtherStyle($query)) {
            throw PriceLookupValidationException::xxCodeNotValidForStyle($query);
        }

        $like = $this->patterns->toLikePattern($query);
        $rows = $this->prices->searchByPrefix($dbco, $like, Module::SEARCH_LIMIT);
        if ($rows !== []) {
            return $this->toResult($rows, $companyCode, $query);
        }

        // One hit for the code as entered, priced from its mask; picking it quotes the same code.
        $code = strtoupper($query);
        $fallback = $this->maskFallback->find($dbco, $code, $companyCode);
        if ($fallback === null) {
            throw new PriceLookupProductNotFound($query);
        }

        $hit = new PriceLookupSearchHit(
            productCode: $code,
            status: $this->makeStock->fromWarehouseStatus($this->prices->findWarehouseStatusByCode($code)),
            setupLength: $fallback['row']['setupLength'],
            lastUpdated: $fallback['row']['lastUpdated'],
            pricedFromMask: $fallback['mask'],
        );

        return new PriceLookupSearchResult([$hit], 1, $companyCode, $query);
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
