<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Product\Persistence\ProductPersistenceException;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductRepository;
use TriCab\Core\Product\Domain\Entity\Product;
use TriCab\Core\Product\Domain\Exception\ProductConcurrencyException;
use TriCab\Core\Product\Domain\ValueObject\ProductCodeValue;
use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class InMemoryProductRepository implements ProductRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function findByPublicId(ProductPublicId $publicId): ?Product
    {
        $record = $this->store->productRecordByPublicId($publicId);

        return $record !== null ? $record['product'] : null;
    }

    public function findById(ProductId $productId): ?Product
    {
        $record = $this->store->productRecordById($productId);

        return $record !== null ? $record['product'] : null;
    }

    public function findIdByPublicId(ProductPublicId $publicId): ?ProductId
    {
        $record = $this->store->productRecordByPublicId($publicId);

        return $record !== null ? $record['product']->id() : null;
    }

    public function productCodeExists(ProductCodeValue $code, ?ProductPublicId $exceptPublicId = null): bool
    {
        foreach ($this->store->productCodes() as $row) {
            if (($row['deleted'] ?? false) === true) {
                continue;
            }
            if (($row['codeValue'] ?? '') !== $code->toString()) {
                continue;
            }
            if ($exceptPublicId !== null && ($row['productPublicId'] ?? '') === $exceptPublicId->toString()) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function internalCodeExists(string $internalCode, ?ProductPublicId $exceptPublicId = null): bool
    {
        foreach ($this->store->productRecords() as $record) {
            $product = $record['product'];
            if ($product->isSoftDeleted()) {
                continue;
            }
            if ($product->internalCode()?->toString() !== $internalCode) {
                continue;
            }
            if ($exceptPublicId !== null && $product->publicId()->equals($exceptPublicId)) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function slugExists(ProductSlug $slug, ?string $regionPublicId = null, ?ProductPublicId $exceptPublicId = null): bool
    {
        foreach ($this->store->slugs() as $row) {
            if (($row['deleted'] ?? false) === true) {
                continue;
            }
            if (($row['slug'] ?? '') !== $slug->toString()) {
                continue;
            }
            if (($row['regionPublicId'] ?? null) !== $regionPublicId) {
                continue;
            }
            if ($exceptPublicId !== null && ($row['productPublicId'] ?? '') === $exceptPublicId->toString()) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function findRowVersion(ProductPublicId $publicId): ?RowVersionToken
    {
        $record = $this->store->productRecordByPublicId($publicId);

        return $record !== null ? $record['product']->rowVersion() : null;
    }

    public function save(
        Product $product,
        UserId $actorUserId,
        ?RowVersionToken $expectedRowVersion = null,
    ): Product {
        if ($product->id() === null) {
            return $this->insert($product, $actorUserId);
        }

        if ($expectedRowVersion === null) {
            throw ProductPersistenceException::expectedRowVersionRequired();
        }

        return $this->update($product, $actorUserId, $expectedRowVersion);
    }

    private function insert(Product $product, UserId $actorUserId): Product
    {
        $productId = $this->store->allocateProductId();
        $rowVersion = $this->store->nextRowVersion();
        $now = $this->store->nowUtc();
        $saved = $this->withIdentity($product, $productId, $rowVersion);

        $this->store->saveProductRecord($productId, [
            'product' => $saved,
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
            'createdByUserId' => $actorUserId->toInt(),
            'updatedByUserId' => $actorUserId->toInt(),
        ]);

        return $saved;
    }

    private function update(
        Product $product,
        UserId $actorUserId,
        RowVersionToken $expectedRowVersion,
    ): Product {
        $productId = $product->id();
        if ($productId === null) {
            throw ProductPersistenceException::expectedRowVersionRequired();
        }

        $existing = $this->store->productRecordById($productId);
        if ($existing === null) {
            throw ProductPersistenceException::productNotFound($product->publicId()->toString());
        }

        $currentVersion = $existing['product']->rowVersion();
        if ($currentVersion === null || !$currentVersion->equals($expectedRowVersion)) {
            throw ProductConcurrencyException::forPublicId($product->publicId()->toString());
        }

        $rowVersion = $this->store->nextRowVersion();
        $saved = $this->withIdentity($product, $productId, $rowVersion);

        $this->store->saveProductRecord($productId, [
            'product' => $saved,
            'createdAtUtc' => $existing['createdAtUtc'],
            'updatedAtUtc' => $this->store->nowUtc(),
            'createdByUserId' => $existing['createdByUserId'],
            'updatedByUserId' => $actorUserId->toInt(),
        ]);

        return $saved;
    }

    private function withIdentity(Product $product, ProductId $id, RowVersionToken $rowVersion): Product
    {
        return new Product(
            id: $id,
            publicId: $product->publicId(),
            productName: $product->productName(),
            status: $product->status(),
            lifecycleStatus: $product->lifecycleStatus(),
            familyId: $product->familyId(),
            categoryId: $product->categoryId(),
            seriesId: $product->seriesId(),
            internalName: $product->internalName(),
            internalCode: $product->internalCode(),
            shortDescription: $product->shortDescription(),
            fullDescription: $product->fullDescription(),
            legacyProductId: $product->legacyProductId(),
            erpReference: $product->erpReference(),
            isFeatured: $product->isFeatured(),
            publishAtUtc: $product->publishAtUtc(),
            unpublishAtUtc: $product->unpublishAtUtc(),
            discontinueAtUtc: $product->discontinueAtUtc(),
            isActive: $product->isActive(),
            deletedAtUtc: $product->deletedAtUtc(),
            deletedByUserId: $product->deletedByUserId(),
            rowVersion: $rowVersion,
        );
    }
}
