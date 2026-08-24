<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\LegacyBaseCostComparison;
use TriCab\Core\Product\Application\DTO\ProductBaseCostHistoryEntry;
use TriCab\Core\Product\Application\DTO\ProductPricingMatrixRow;
use TriCab\Core\Product\Application\DTO\ProductPricingOverview;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Restricted pricing port. Never used by public product reads.
 * Tables: PriceLists, ProductPrices, ProductVariantPrices, Currencies.
 */
interface ProductPricingRepository
{
    /**
     * @return list<ProductPricingSummary>
     */
    public function listByProduct(ProductPublicId $productPublicId): array;

    public function getOverview(ProductPublicId $productPublicId): ProductPricingOverview;

    /**
     * @return list<ProductPricingMatrixRow>
     */
    public function listMatrixByProduct(ProductPublicId $productPublicId): array;

    /**
     * @return list<ProductBaseCostHistoryEntry>
     */
    public function listHistory(
        ProductPublicId $productPublicId,
        ?string $variantPublicId = null,
        ?string $priceListPublicId = null,
    ): array;

    public function findByPublicId(string $publicId): ?ProductPricingSummary;

    /**
     * @param array{
     *   currencyCode: string,
     *   baseCost: string,
     *   minimumQuantity: string,
     *   priceListPublicId?: ?string,
     *   regionPublicId?: ?string,
     *   marketPublicId?: ?string,
     *   discountPercent?: ?string,
     *   taxInclusive?: bool,
     *   effectiveFromUtc?: ?string,
     *   effectiveToUtc?: ?string,
     *   variantPublicId?: ?string,
     *   priceSourceCode?: ?string,
     *   createRevision?: bool,
     *   pricingPublicId?: ?string,
     *   expectedRowVersion?: ?string,
     *   unitOfMeasurePublicId?: ?string
     * } $data
     */
    public function upsert(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductPricingSummary;

    public function setProductDefaultPricingUnit(
        ProductPublicId $productPublicId,
        ?string $pricingUnitPublicId,
        UserId $actorUserId,
    ): ProductPricingOverview;

    public function setActive(
        string $pricingPublicId,
        bool $isActive,
        UserId $actorUserId,
        ?string $expectedRowVersion = null,
    ): ProductPricingSummary;
}
