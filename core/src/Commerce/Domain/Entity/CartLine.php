<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\Entity;

use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;
use TriCab\Core\Commerce\Domain\ValueObject\CartId;
use TriCab\Core\Commerce\Domain\ValueObject\CartLineId;
use TriCab\Core\Commerce\Domain\ValueObject\CartLinePublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class CartLine
{
    public function __construct(
        private readonly ?CartLineId $id,
        private readonly CartLinePublicId $publicId,
        private readonly ?CartId $cartId,
        private readonly int $productVariantId,
        private readonly string $variantPublicId,
        private readonly ?string $purchaseOptionKey,
        private int $quantity,
        private ?string $length,
        private ?string $uomCode,
        private ?string $availability,
        private string $pricingStatus,
        private ?string $unitSellPrice,
        private ?string $lineTotal,
        private string $currencyCode,
        private ?string $marginSource,
        private ?string $pricingFingerprint,
        private ?string $resolvedAtUtc,
        private RowVersionToken $rowVersion,
        private readonly ?string $variantCode = null,
        private readonly ?string $displayName = null,
    ) {
        if ($this->productVariantId < 1) {
            throw InvalidCartValue::for('ProductVariantId', (string) $this->productVariantId);
        }
        if ($this->quantity < 1) {
            throw InvalidCartValue::for('Quantity', (string) $this->quantity);
        }
        if ($this->length !== null && bccomp($this->length, '0', 3) <= 0) {
            throw InvalidCartValue::for('Length', $this->length);
        }
    }

    public function id(): ?CartLineId
    {
        return $this->id;
    }

    public function publicId(): CartLinePublicId
    {
        return $this->publicId;
    }

    public function cartId(): ?CartId
    {
        return $this->cartId;
    }

    public function productVariantId(): int
    {
        return $this->productVariantId;
    }

    public function variantPublicId(): string
    {
        return $this->variantPublicId;
    }

    public function purchaseOptionKey(): ?string
    {
        return $this->purchaseOptionKey;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }

    public function length(): ?string
    {
        return $this->length;
    }

    public function uomCode(): ?string
    {
        return $this->uomCode;
    }

    public function availability(): ?string
    {
        return $this->availability;
    }

    public function pricingStatus(): string
    {
        return $this->pricingStatus;
    }

    public function unitSellPrice(): ?string
    {
        return $this->unitSellPrice;
    }

    public function lineTotal(): ?string
    {
        return $this->lineTotal;
    }

    public function currencyCode(): string
    {
        return $this->currencyCode;
    }

    public function marginSource(): ?string
    {
        return $this->marginSource;
    }

    public function pricingFingerprint(): ?string
    {
        return $this->pricingFingerprint;
    }

    public function resolvedAtUtc(): ?string
    {
        return $this->resolvedAtUtc;
    }

    public function rowVersion(): RowVersionToken
    {
        return $this->rowVersion;
    }

    public function variantCode(): ?string
    {
        return $this->variantCode;
    }

    public function displayName(): ?string
    {
        return $this->displayName;
    }

    public function withQuantity(int $quantity): self
    {
        $clone = clone $this;
        $clone->quantity = $quantity;

        return $clone;
    }

    public function withPricing(
        string $pricingStatus,
        ?string $unitSellPrice,
        ?string $lineTotal,
        string $currencyCode,
        ?string $marginSource,
        ?string $pricingFingerprint,
        ?string $resolvedAtUtc,
        ?string $uomCode = null,
        ?string $availability = null,
        ?string $length = null,
        ?int $quantity = null,
        ?RowVersionToken $rowVersion = null,
    ): self {
        $clone = clone $this;
        $clone->pricingStatus = $pricingStatus;
        $clone->unitSellPrice = $unitSellPrice;
        $clone->lineTotal = $lineTotal;
        $clone->currencyCode = $currencyCode;
        $clone->marginSource = $marginSource;
        $clone->pricingFingerprint = $pricingFingerprint;
        $clone->resolvedAtUtc = $resolvedAtUtc;
        if ($uomCode !== null) {
            $clone->uomCode = $uomCode;
        }
        if ($availability !== null) {
            $clone->availability = $availability;
        }
        if ($length !== null) {
            $clone->length = $length;
        }
        if ($quantity !== null) {
            $clone->quantity = $quantity;
        }
        if ($rowVersion !== null) {
            $clone->rowVersion = $rowVersion;
        }

        return $clone;
    }

    public function matchesMergeKey(
        int $productVariantId,
        ?string $purchaseOptionKey,
        ?string $length,
        string $pricingStatus,
        ?string $pricingFingerprint,
    ): bool {
        if ($this->productVariantId !== $productVariantId) {
            return false;
        }
        if (($this->purchaseOptionKey ?? '') !== ($purchaseOptionKey ?? '')) {
            return false;
        }
        if (!$this->lengthsEqual($this->length, $length)) {
            return false;
        }
        if ($this->pricingStatus !== $pricingStatus) {
            return false;
        }
        // Same fingerprint family: identical fingerprint, or both null TBC
        if ($pricingFingerprint !== null && $this->pricingFingerprint !== null) {
            return $this->pricingFingerprint === $pricingFingerprint;
        }

        return $this->pricingFingerprint === $pricingFingerprint;
    }

    private function lengthsEqual(?string $a, ?string $b): bool
    {
        if ($a === null && $b === null) {
            return true;
        }
        if ($a === null || $b === null) {
            return false;
        }

        return bccomp($a, $b, 3) === 0;
    }
}
