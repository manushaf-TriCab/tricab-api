<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Restricted pricing port. Never used by public product reads.
 * Future Azure SQL: PriceLists / ProductPrices / ProductVariantPrices / CustomerProductPrices
 */
interface ProductPricingRepository
{
    /**
     * @return list<ProductPricingSummary>
     */
    public function listByProduct(ProductPublicId $productPublicId): array;

    /**
     * @param array{
     *   currencyCode: string,
     *   unitPrice: string,
     *   minimumQuantity: string,
     *   priceListPublicId?: ?string,
     *   regionPublicId?: ?string,
     *   marketPublicId?: ?string,
     *   discountPercent?: ?string,
     *   taxInclusive?: bool,
     *   effectiveFromUtc?: ?string,
     *   effectiveToUtc?: ?string,
     *   variantPublicId?: ?string
     * } $data
     */
    public function upsert(
        ProductPublicId $productPublicId,
        array $data,
        UserId $actorUserId,
    ): ProductPricingSummary;
}
