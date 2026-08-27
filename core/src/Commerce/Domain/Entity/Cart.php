<?php

declare(strict_types=1);

namespace TriCab\Core\Commerce\Domain\Entity;

use TriCab\Core\Commerce\Domain\Exception\InvalidCartValue;
use TriCab\Core\Commerce\Domain\ValueObject\CartId;
use TriCab\Core\Commerce\Domain\ValueObject\CartPricingMode;
use TriCab\Core\Commerce\Domain\ValueObject\CartPublicId;
use TriCab\Core\Commerce\Domain\ValueObject\CartStatus;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class Cart
{
    /**
     * @param list<CartLine> $lines
     */
    public function __construct(
        private readonly ?CartId $id,
        private readonly CartPublicId $publicId,
        private readonly int $ownerUserId,
        private readonly ?int $effectiveCustomerId,
        private readonly CartPricingMode $pricingMode,
        private readonly string $currencyCode,
        private CartStatus $status,
        private ?string $pricingFingerprint,
        private RowVersionToken $rowVersion,
        private array $lines = [],
    ) {
        if ($this->ownerUserId < 1) {
            throw InvalidCartValue::for('OwnerUserId', (string) $this->ownerUserId);
        }
        $currency = strtoupper(trim($this->currencyCode));
        if (strlen($currency) !== 3) {
            throw InvalidCartValue::for('CurrencyCode', $this->currencyCode);
        }
    }

    public function id(): ?CartId
    {
        return $this->id;
    }

    public function publicId(): CartPublicId
    {
        return $this->publicId;
    }

    public function ownerUserId(): int
    {
        return $this->ownerUserId;
    }

    public function effectiveCustomerId(): ?int
    {
        return $this->effectiveCustomerId;
    }

    public function pricingMode(): CartPricingMode
    {
        return $this->pricingMode;
    }

    public function currencyCode(): string
    {
        return strtoupper($this->currencyCode);
    }

    public function status(): CartStatus
    {
        return $this->status;
    }

    public function pricingFingerprint(): ?string
    {
        return $this->pricingFingerprint;
    }

    public function rowVersion(): RowVersionToken
    {
        return $this->rowVersion;
    }

    /**
     * @return list<CartLine>
     */
    public function lines(): array
    {
        return $this->lines;
    }

    /**
     * @param list<CartLine> $lines
     */
    public function withLines(array $lines): self
    {
        $clone = clone $this;
        $clone->lines = array_values($lines);

        return $clone;
    }

    public function withStatus(CartStatus $status): self
    {
        $clone = clone $this;
        $clone->status = $status;

        return $clone;
    }

    public function withRowVersion(RowVersionToken $rowVersion): self
    {
        $clone = clone $this;
        $clone->rowVersion = $rowVersion;

        return $clone;
    }

    public function withPricingFingerprint(?string $fingerprint): self
    {
        $clone = clone $this;
        $clone->pricingFingerprint = $fingerprint;

        return $clone;
    }
}
