<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Product\Persistence\ProductPersistenceException;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\Exception\DuplicateVariantSku;
use TriCab\Core\Product\Contract\ProductVariantRepository;
use TriCab\Core\Product\Domain\Entity\ProductVariant;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Domain\ValueObject\Sku;

final class InMemoryProductVariantRepository implements ProductVariantRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function findByPublicId(ProductVariantPublicId $publicId): ?ProductVariant
    {
        $record = $this->store->variantRecordByPublicId($publicId);

        return $record !== null ? $record['variant'] : null;
    }

    public function findById(ProductVariantId $variantId): ?ProductVariant
    {
        $record = $this->store->variantRecordById($variantId);

        return $record !== null ? $record['variant'] : null;
    }

    public function findIdByPublicId(ProductVariantPublicId $publicId): ?ProductVariantId
    {
        $record = $this->store->variantRecordByPublicId($publicId);

        return $record !== null ? $record['variant']->id() : null;
    }

    public function skuExists(Sku $sku, ?ProductVariantPublicId $exceptPublicId = null): bool
    {
        foreach ($this->store->variantRecords() as $record) {
            $variant = $record['variant'];
            if ($variant->isSoftDeleted()) {
                continue;
            }
            if ($variant->sku()?->toString() !== $sku->toString()) {
                continue;
            }
            if ($exceptPublicId !== null && $variant->publicId()->equals($exceptPublicId)) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function findRowVersion(ProductVariantPublicId $publicId): ?RowVersionToken
    {
        $record = $this->store->variantRecordByPublicId($publicId);

        return $record !== null ? $record['variant']->rowVersion() : null;
    }

    public function save(
        ProductVariant $variant,
        UserId $actorUserId,
        ?RowVersionToken $expectedRowVersion = null,
    ): ProductVariant {
        $this->assertParentProductUsable($variant->productId());

        if ($variant->sku() !== null && $this->skuExists($variant->sku(), $variant->publicId())) {
            throw DuplicateVariantSku::forSku($variant->sku()->toString());
        }

        if ($variant->id() === null) {
            return $this->insert($variant, $actorUserId);
        }

        if ($expectedRowVersion === null) {
            throw ProductPersistenceException::expectedRowVersionRequired();
        }

        return $this->update($variant, $actorUserId, $expectedRowVersion);
    }

    private function assertParentProductUsable(ProductId $productId): void
    {
        $record = $this->store->productRecordById($productId);
        if ($record === null) {
            throw ProductPersistenceException::parentProductInvalid(
                sprintf('Parent product %d does not exist.', $productId->toInt()),
            );
        }

        if ($record['product']->isSoftDeleted()) {
            throw ProductPersistenceException::parentProductInvalid(
                'Cannot save a variant under a soft-deleted parent product.',
            );
        }
    }

    private function insert(ProductVariant $variant, UserId $actorUserId): ProductVariant
    {
        $variantId = $this->store->allocateVariantId();
        $rowVersion = $this->store->nextRowVersion();
        $now = $this->store->nowUtc();
        $saved = $this->withIdentity($variant, $variantId, $rowVersion);

        $this->store->saveVariantRecord($variantId, [
            'variant' => $saved,
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
            'createdByUserId' => $actorUserId->toInt(),
            'updatedByUserId' => $actorUserId->toInt(),
        ]);

        return $saved;
    }

    private function update(
        ProductVariant $variant,
        UserId $actorUserId,
        RowVersionToken $expectedRowVersion,
    ): ProductVariant {
        $variantId = $variant->id();
        if ($variantId === null) {
            throw ProductPersistenceException::expectedRowVersionRequired();
        }

        $existing = $this->store->variantRecordById($variantId);
        if ($existing === null) {
            throw ProductPersistenceException::variantNotFound($variant->publicId()->toString());
        }

        $currentVersion = $existing['variant']->rowVersion();
        if ($currentVersion === null || !$currentVersion->equals($expectedRowVersion)) {
            throw ProductConcurrencyException::forPublicId($variant->publicId()->toString());
        }

        $rowVersion = $this->store->nextRowVersion();
        $saved = $this->withIdentity($variant, $variantId, $rowVersion);

        $this->store->saveVariantRecord($variantId, [
            'variant' => $saved,
            'createdAtUtc' => $existing['createdAtUtc'],
            'updatedAtUtc' => $this->store->nowUtc(),
            'createdByUserId' => $existing['createdByUserId'],
            'updatedByUserId' => $actorUserId->toInt(),
        ]);

        return $saved;
    }

    private function withIdentity(
        ProductVariant $variant,
        ProductVariantId $id,
        RowVersionToken $rowVersion,
    ): ProductVariant {
        return new ProductVariant(
            id: $id,
            publicId: $variant->publicId(),
            productId: $variant->productId(),
            variantName: $variant->variantName(),
            status: $variant->status(),
            displayOrder: $variant->displayOrder(),
            sku: $variant->sku(),
            variantCode: $variant->variantCode(),
            minimumOrderQuantity: $variant->minimumOrderQuantity(),
            weightKg: $variant->weightKg(),
            packagingDescription: $variant->packagingDescription(),
            erpReference: $variant->erpReference(),
            isActive: $variant->isActive(),
            deletedAtUtc: $variant->deletedAtUtc(),
            deletedByUserId: $variant->deletedByUserId(),
            rowVersion: $rowVersion,
        );
    }
}
