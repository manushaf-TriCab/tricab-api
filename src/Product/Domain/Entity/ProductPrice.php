<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Entity;

use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\CurrencyCode;
use TriCab\Core\Product\Domain\ValueObject\EffectiveDateRange;
use TriCab\Core\Product\Domain\ValueObject\Money;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;

/**
 * Internal pricing line. Never included in public product models by default.
 */
final class ProductPrice
{
    public function __construct(
        private readonly Money $unitPrice,
        private readonly CurrencyCode $currency,
        private readonly EffectiveDateRange $effectiveRange,
        private readonly string $minimumQuantity,
        private readonly ?ProductId $productId = null,
        private readonly ?ProductVariantId $productVariantId = null,
        private readonly ?string $discountPercent = null,
        private readonly bool $taxInclusive = false,
    ) {
        if ($productId === null && $productVariantId === null) {
            throw InvalidProductValue::for('ProductPriceOwner', 'missing');
        }

        if ((float) $minimumQuantity <= 0) {
            throw InvalidProductValue::for('MinimumQuantity', $minimumQuantity);
        }

        if (
            $discountPercent !== null
            && ((float) $discountPercent < 0 || (float) $discountPercent > 100)
        ) {
            throw InvalidProductValue::for('DiscountPercent', $discountPercent);
        }

        if (!$unitPrice->currency()->equals($currency)) {
            throw InvalidProductValue::for('CurrencyMismatch', $currency->toString());
        }
    }

    public function unitPrice(): Money
    {
        return $this->unitPrice;
    }

    public function currency(): CurrencyCode
    {
        return $this->currency;
    }

    public function effectiveRange(): EffectiveDateRange
    {
        return $this->effectiveRange;
    }

    public function minimumQuantity(): string
    {
        return $this->minimumQuantity;
    }

    public function productId(): ?ProductId
    {
        return $this->productId;
    }

    public function productVariantId(): ?ProductVariantId
    {
        return $this->productVariantId;
    }

    public function discountPercent(): ?string
    {
        return $this->discountPercent;
    }

    public function taxInclusive(): bool
    {
        return $this->taxInclusive;
    }
}
