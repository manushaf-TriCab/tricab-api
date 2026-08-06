<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Entity;

use DateTimeImmutable;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\Exception\InvalidProductLifecycleException;
use TriCab\Core\Product\Domain\Exception\InvalidProductValue;
use TriCab\Core\Product\Domain\ValueObject\InternalProductCode;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryId;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyId;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductLifecycleStatusCode;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSeriesId;
use TriCab\Core\Product\Domain\ValueObject\ProductStatusCode;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

/**
 * Product aggregate root.
 *
 * Lifecycle semantics:
 * - Archived: business status only (status=archived). Remains visible to authorised admin queries.
 * - Soft-deleted: DeletedAtUtc set via explicit delete workflow. Excluded from normal queries.
 * - Inactive (IsActive=false): availability flag, independent of archive/delete.
 * - Unpublished / Discontinued / End-of-life: status or lifecycle codes.
 *
 * ProductVariant is a separate aggregate root associated by ProductId.
 */
final class Product
{
    private readonly string $productName;

    public function __construct(
        private readonly ?ProductId $id,
        private readonly ProductPublicId $publicId,
        string $productName,
        private readonly ProductStatusCode $status,
        private readonly ProductLifecycleStatusCode $lifecycleStatus,
        private readonly ?ProductFamilyId $familyId = null,
        private readonly ?ProductCategoryId $categoryId = null,
        private readonly ?ProductSeriesId $seriesId = null,
        private readonly ?string $internalName = null,
        private readonly ?InternalProductCode $internalCode = null,
        private readonly ?string $shortDescription = null,
        private readonly ?string $fullDescription = null,
        private readonly ?string $legacyProductId = null,
        private readonly ?string $erpReference = null,
        private readonly bool $isFeatured = false,
        private readonly ?DateTimeImmutable $publishAtUtc = null,
        private readonly ?DateTimeImmutable $unpublishAtUtc = null,
        private readonly ?DateTimeImmutable $discontinueAtUtc = null,
        private readonly bool $isActive = true,
        private readonly ?DateTimeImmutable $deletedAtUtc = null,
        private readonly ?UserId $deletedByUserId = null,
        private readonly ?RowVersionToken $rowVersion = null,
    ) {
        $normalized = trim($productName);
        if ($normalized === '') {
            throw InvalidProductValue::for('ProductName', $productName);
        }

        $this->productName = $normalized;

        if (
            $this->publishAtUtc !== null
            && $this->unpublishAtUtc !== null
            && $this->unpublishAtUtc < $this->publishAtUtc
        ) {
            throw InvalidProductLifecycleException::for(
                'Unpublish date cannot be earlier than publish date.',
            );
        }

        if ($this->deletedAtUtc === null && $this->deletedByUserId !== null) {
            throw InvalidProductLifecycleException::for(
                'DeletedByUserId requires DeletedAtUtc.',
            );
        }
    }

    public function id(): ?ProductId
    {
        return $this->id;
    }

    public function publicId(): ProductPublicId
    {
        return $this->publicId;
    }

    public function productName(): string
    {
        return $this->productName;
    }

    public function status(): ProductStatusCode
    {
        return $this->status;
    }

    public function lifecycleStatus(): ProductLifecycleStatusCode
    {
        return $this->lifecycleStatus;
    }

    public function familyId(): ?ProductFamilyId
    {
        return $this->familyId;
    }

    public function categoryId(): ?ProductCategoryId
    {
        return $this->categoryId;
    }

    public function seriesId(): ?ProductSeriesId
    {
        return $this->seriesId;
    }

    public function internalName(): ?string
    {
        return $this->internalName;
    }

    public function internalCode(): ?InternalProductCode
    {
        return $this->internalCode;
    }

    public function shortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function fullDescription(): ?string
    {
        return $this->fullDescription;
    }

    public function legacyProductId(): ?string
    {
        return $this->legacyProductId;
    }

    public function erpReference(): ?string
    {
        return $this->erpReference;
    }

    public function isFeatured(): bool
    {
        return $this->isFeatured;
    }

    public function publishAtUtc(): ?DateTimeImmutable
    {
        return $this->publishAtUtc;
    }

    public function unpublishAtUtc(): ?DateTimeImmutable
    {
        return $this->unpublishAtUtc;
    }

    public function discontinueAtUtc(): ?DateTimeImmutable
    {
        return $this->discontinueAtUtc;
    }

    /**
     * Availability flag only — independent of archive and soft-delete.
     */
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

    public function withLifecycleStatus(ProductLifecycleStatusCode $lifecycleStatus): self
    {
        return $this->cloneWith(lifecycleStatus: $lifecycleStatus);
    }

    public function withClassification(
        ?ProductFamilyId $familyId,
        ?ProductCategoryId $categoryId,
        ?ProductSeriesId $seriesId,
    ): self {
        return $this->cloneWith(
            familyId: $familyId,
            categoryId: $categoryId,
            seriesId: $seriesId,
            replaceClassification: true,
        );
    }

    public function withActive(bool $isActive): self
    {
        return $this->cloneWith(isActive: $isActive);
    }

    /**
     * Replace mutable product content with an already-merged snapshot.
     * Callers (application services) merge patch commands with current values.
     */
    public function withContent(
        string $productName,
        ?string $internalName,
        ?InternalProductCode $internalCode,
        ?string $shortDescription,
        ?string $fullDescription,
        ?string $legacyProductId,
        ?string $erpReference,
        bool $isFeatured,
        ?DateTimeImmutable $publishAtUtc,
        ?DateTimeImmutable $unpublishAtUtc,
        ?DateTimeImmutable $discontinueAtUtc,
    ): self {
        $this->assertEditable();

        return new self(
            $this->id,
            $this->publicId,
            $productName,
            $this->status,
            $this->lifecycleStatus,
            $this->familyId,
            $this->categoryId,
            $this->seriesId,
            $internalName,
            $internalCode,
            $shortDescription,
            $fullDescription,
            $legacyProductId,
            $erpReference,
            $isFeatured,
            $publishAtUtc,
            $unpublishAtUtc,
            $discontinueAtUtc,
            $this->isActive,
            $this->deletedAtUtc,
            $this->deletedByUserId,
            $this->rowVersion,
        );
    }

    /**
     * Business archive only — does not soft-delete.
     */
    public function archive(): self
    {
        $this->assertNotSoftDeleted();

        return $this->cloneWith(status: ProductStatusCode::archived());
    }

    /**
     * Restore from archived business status to draft. Does not clear soft-delete.
     */
    public function restoreFromArchive(): self
    {
        $this->assertNotSoftDeleted();

        if (!$this->isArchived()) {
            throw InvalidProductLifecycleException::for(
                'Only archived products can be restored from archive.',
            );
        }

        return $this->cloneWith(status: ProductStatusCode::draft());
    }

    /**
     * Explicit soft-delete workflow. Does not change business status to archived.
     */
    public function softDelete(DateTimeImmutable $deletedAtUtc, UserId $deletedByUserId): self
    {
        if ($this->isSoftDeleted()) {
            throw InvalidProductLifecycleException::for('Product is already soft-deleted.');
        }

        return $this->cloneWith(
            deletedAtUtc: $deletedAtUtc,
            deletedByUserId: $deletedByUserId,
            replaceDeletion: true,
        );
    }

    /**
     * Clear soft-delete markers. Does not change business status.
     */
    public function restoreFromDeletion(): self
    {
        if (!$this->isSoftDeleted()) {
            throw InvalidProductLifecycleException::for(
                'Only soft-deleted products can be restored from deletion.',
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
                'Soft-deleted products cannot be edited. Restore from deletion first.',
            );
        }
    }

    public function assertNotSoftDeleted(): void
    {
        if ($this->isSoftDeleted()) {
            throw InvalidProductLifecycleException::for('Operation is not allowed on soft-deleted products.');
        }
    }

    private function cloneWith(
        ?ProductStatusCode $status = null,
        ?ProductLifecycleStatusCode $lifecycleStatus = null,
        ?ProductFamilyId $familyId = null,
        ?ProductCategoryId $categoryId = null,
        ?ProductSeriesId $seriesId = null,
        bool $replaceClassification = false,
        ?bool $isActive = null,
        ?DateTimeImmutable $deletedAtUtc = null,
        ?UserId $deletedByUserId = null,
        bool $replaceDeletion = false,
    ): self {
        return new self(
            $this->id,
            $this->publicId,
            $this->productName,
            $status ?? $this->status,
            $lifecycleStatus ?? $this->lifecycleStatus,
            $replaceClassification ? $familyId : ($familyId ?? $this->familyId),
            $replaceClassification ? $categoryId : ($categoryId ?? $this->categoryId),
            $replaceClassification ? $seriesId : ($seriesId ?? $this->seriesId),
            $this->internalName,
            $this->internalCode,
            $this->shortDescription,
            $this->fullDescription,
            $this->legacyProductId,
            $this->erpReference,
            $this->isFeatured,
            $this->publishAtUtc,
            $this->unpublishAtUtc,
            $this->discontinueAtUtc,
            $isActive ?? $this->isActive,
            $replaceDeletion ? $deletedAtUtc : ($deletedAtUtc ?? $this->deletedAtUtc),
            $replaceDeletion ? $deletedByUserId : ($deletedByUserId ?? $this->deletedByUserId),
            $this->rowVersion,
        );
    }
}
