<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\Exception\InvalidProductLifecycleException;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\DisplayOrder;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Domain\ValueObject\Sku;

/**
 * ProductVariant aggregate root.
 *
 * Associated with a Product via ProductId. Independently editable with its own
 * RowVersion. Not a child entity of the Product aggregate.
 *
 * Archive vs soft-delete semantics match Product.
 */
final class ProductVariant
{
    private readonly string $variantName;

    public function __construct(
        private readonly ?ProductVariantId $id,
        private readonly ProductVariantPublicId $publicId,
        private readonly ProductId $productId,
        string $variantName,
        private readonly ProductStatusCode $status,
        private readonly DisplayOrder $displayOrder,
        private readonly ?Sku $sku = null,
        private readonly ?ProductCodeValue $variantCode = null,
        private readonly ?string $minimumOrderQuantity = null,
        private readonly ?string $weightKg = null,
        private readonly ?string $packagingDescription = null,
        private readonly ?string $erpReference = null,
        private readonly bool $isActive = true,
        private readonly ?DateTimeImmutable $deletedAtUtc = null,
        private readonly ?UserId $deletedByUserId = null,
        private readonly ?RowVersionToken $rowVersion = null,
    ) {
        $normalized = trim($variantName);
        if ($normalized === '') {
            throw InvalidProductValue::for('VariantName', $variantName);
        }

        if ($minimumOrderQuantity !== null && (float) $minimumOrderQuantity <= 0) {
            throw InvalidProductValue::for('MinimumOrderQuantity', (string) $minimumOrderQuantity);
        }

        if ($this->deletedAtUtc === null && $this->deletedByUserId !== null) {
            throw InvalidProductLifecycleException::for(
                'DeletedByUserId requires DeletedAtUtc.',
            );
        }

        $this->variantName = $normalized;
    }

    public function id(): ?ProductVariantId
    {
        return $this->id;
    }

    public function publicId(): ProductVariantPublicId
    {
        return $this->publicId;
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function variantName(): string
    {
        return $this->variantName;
    }

    public function status(): ProductStatusCode
    {
        return $this->status;
    }

    public function displayOrder(): DisplayOrder
    {
        return $this->displayOrder;
    }

    public function sku(): ?Sku
    {
        return $this->sku;
    }

    public function variantCode(): ?ProductCodeValue
    {
        return $this->variantCode;
    }

    public function minimumOrderQuantity(): ?string
    {
        return $this->minimumOrderQuantity;
    }

    public function weightKg(): ?string
    {
        return $this->weightKg;
    }

    public function packagingDescription(): ?string
    {
        return $this->packagingDescription;
    }

    public function erpReference(): ?string
    {
        return $this->erpReference;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isArchived(): bool
    {
        return $this->status->isArchived();
    }

    public function isSoftDeleted(): bool
    {
        return $this->deletedAtUtc !== null;
    }

    public function deletedAtUtc(): ?DateTimeImmutable
    {
        return $this->deletedAtUtc;
    }

    public function deletedByUserId(): ?UserId
    {
        return $this->deletedByUserId;
    }

    public function rowVersion(): ?RowVersionToken
    {
        return $this->rowVersion;
    }

    public function withStatus(ProductStatusCode $status): self
    {
        return $this->cloneWith(status: $status);
    }

    /**
     * Replace mutable variant content with an already-merged snapshot.
     */
    public function withContent(
        string $variantName,
        ProductStatusCode $status,
        DisplayOrder $displayOrder,
        ?Sku $sku,
        ?ProductCodeValue $variantCode,
        ?string $minimumOrderQuantity,
        ?string $weightKg,
        ?string $packagingDescription,
        ?string $erpReference,
        bool $isActive,
    ): self {
        $this->assertEditable();

        return new self(
            $this->id,
            $this->publicId,
            $this->productId,
            $variantName,
            $status,
            $displayOrder,
            $sku,
            $variantCode,
            $minimumOrderQuantity,
            $weightKg,
            $packagingDescription,
            $erpReference,
            $isActive,
            $this->deletedAtUtc,
            $this->deletedByUserId,
            $this->rowVersion,
        );
    }

    public function archive(): self
    {
        $this->assertNotSoftDeleted();

        return $this->cloneWith(status: ProductStatusCode::archived());
    }

    public function restoreFromArchive(): self
    {
        $this->assertNotSoftDeleted();

        if (!$this->isArchived()) {
            throw InvalidProductLifecycleException::for(
                'Only archived variants can be restored from archive.',
            );
        }

        return $this->cloneWith(status: ProductStatusCode::draft());
    }

    public function softDelete(DateTimeImmutable $deletedAtUtc, UserId $deletedByUserId): self
    {
        if ($this->isSoftDeleted()) {
            throw InvalidProductLifecycleException::for('Variant is already soft-deleted.');
        }

        return $this->cloneWith(
            deletedAtUtc: $deletedAtUtc,
            deletedByUserId: $deletedByUserId,
            replaceDeletion: true,
        );
    }

    public function restoreFromDeletion(): self
    {
        if (!$this->isSoftDeleted()) {
            throw InvalidProductLifecycleException::for(
                'Only soft-deleted variants can be restored from deletion.',
            );
        }

        return $this->cloneWith(
            deletedAtUtc: null,
            deletedByUserId: null,
            replaceDeletion: true,
        );
    }

    public function assertEditable(): void
    {
        if ($this->isSoftDeleted()) {
            throw InvalidProductLifecycleException::for(
                'Soft-deleted variants cannot be edited. Restore from deletion first.',
            );
        }
    }

    public function assertNotSoftDeleted(): void
    {
        if ($this->isSoftDeleted()) {
            throw InvalidProductLifecycleException::for('Operation is not allowed on soft-deleted variants.');
        }
    }

    private function cloneWith(
        ?ProductStatusCode $status = null,
        ?DateTimeImmutable $deletedAtUtc = null,
        ?UserId $deletedByUserId = null,
        bool $replaceDeletion = false,
    ): self {
        return new self(
            $this->id,
            $this->publicId,
            $this->productId,
            $this->variantName,
            $status ?? $this->status,
            $this->displayOrder,
            $this->sku,
            $this->variantCode,
            $this->minimumOrderQuantity,
            $this->weightKg,
            $this->packagingDescription,
            $this->erpReference,
            $this->isActive,
            $replaceDeletion ? $deletedAtUtc : ($deletedAtUtc ?? $this->deletedAtUtc),
            $replaceDeletion ? $deletedByUserId : ($deletedByUserId ?? $this->deletedByUserId),
            $this->rowVersion,
        );
    }
}
