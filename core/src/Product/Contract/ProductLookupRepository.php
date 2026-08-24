<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\ProductLookupItem;

/**
 * Combined lookup port for admin dropdowns.
 * Future Azure SQL: status / media / document type / currency tables + classification/market lookups.
 *
 * @phpstan-type LookupBundle array{
 *   statuses: list<ProductLookupItem>,
 *   lifecycleStatuses: list<ProductLookupItem>,
 *   families: list<ProductLookupItem>,
 *   categories: list<ProductLookupItem>,
 *   series: list<ProductLookupItem>,
 *   regions: list<ProductLookupItem>,
 *   markets: list<ProductLookupItem>,
 *   industries: list<ProductLookupItem>,
 *   applications: list<ProductLookupItem>,
 *   mediaTypes: list<ProductLookupItem>,
 *   documentTypes: list<ProductLookupItem>,
 *   currencies: list<ProductLookupItem>,
 *   specificationDefinitions: list<ProductLookupItem>,
 *   codeRuleSets: list<ProductLookupItem>,
 *   priceLists: list<PriceListLookupItem>,
 *   pricingUnits: list<PricingUnitLookupItem>
 * }
 */
interface ProductLookupRepository
{
    /**
     * @return LookupBundle
     */
    public function loadAdminLookups(): array;

    /** @return list<ProductLookupItem> */
    public function listStatuses(): array;

    /** @return list<ProductLookupItem> */
    public function listLifecycleStatuses(): array;

    /** @return list<ProductLookupItem> */
    public function listMediaTypes(): array;

    /** @return list<ProductLookupItem> */
    public function listDocumentTypes(): array;

    /** @return list<ProductLookupItem> */
    public function listCurrencies(): array;
}
