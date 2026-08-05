<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryId;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyId;
use TriCab\Core\Product\Domain\ValueObject\ProductSeriesId;

/** Future Azure SQL: ProductFamilies / ProductCategories / ProductSeries */
interface ProductClassificationRepository
{
    /**
     * @return list<ProductLookupItem>
     */
    public function listFamilies(bool $activeOnly = true): array;

    /**
     * @return list<ProductLookupItem>
     */
    public function listCategories(?string $familyPublicId = null, bool $activeOnly = true): array;

    /**
     * @return list<ProductLookupItem>
     */
    public function listSeries(?string $categoryPublicId = null, bool $activeOnly = true): array;

    /**
     * @param array{code: string, name: string, description?: ?string, displayOrder?: int} $data
     */
    public function createFamily(array $data, UserId $actorUserId): ProductLookupItem;

    /**
     * @param array{code: string, name: string, familyPublicId?: ?string, description?: ?string, displayOrder?: int} $data
     */
    public function createCategory(array $data, UserId $actorUserId): ProductLookupItem;

    /**
     * @param array{code: string, name: string, categoryPublicId?: ?string, description?: ?string, displayOrder?: int} $data
     */
    public function createSeries(array $data, UserId $actorUserId): ProductLookupItem;

    public function findFamilyIdByPublicId(string $publicId): ?ProductFamilyId;

    public function findCategoryIdByPublicId(string $publicId): ?ProductCategoryId;

    public function findSeriesIdByPublicId(string $publicId): ?ProductSeriesId;

    /**
     * Validates that the (optional) family/category/series public ids exist, are
     * active, and — when more than one is provided — belong to a consistent
     * hierarchy (category under family, series under category).
     *
     * @throws \TriCab\Core\Product\Application\Exception\InvalidProductClassification
     */
    public function assertHierarchyValid(
        ?string $familyPublicId,
        ?string $categoryPublicId,
        ?string $seriesPublicId,
    ): void;
}
