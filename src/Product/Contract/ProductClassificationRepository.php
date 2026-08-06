<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductClassificationRecord;
use TriCab\Core\Product\Application\DTO\ProductClassificationUsage;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryId;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyId;
use TriCab\Core\Product\Domain\ValueObject\ProductSeriesId;

/**
 * ProductFamilies / ProductCategories / ProductSeries persistence.
 */
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
     * @param array{code: string, name: string, description?: ?string, displayOrder?: int, isActive?: bool} $data
     */
    public function createFamily(array $data, UserId $actorUserId): ProductLookupItem;

    /**
     * @param array{code: string, name: string, familyPublicId: string, description?: ?string, displayOrder?: int, isActive?: bool} $data
     */
    public function createCategory(array $data, UserId $actorUserId): ProductLookupItem;

    /**
     * @param array{code: string, name: string, categoryPublicId: string, description?: ?string, displayOrder?: int, isActive?: bool} $data
     */
    public function createSeries(array $data, UserId $actorUserId): ProductLookupItem;

    public function findFamilyIdByPublicId(string $publicId): ?ProductFamilyId;

    public function findCategoryIdByPublicId(string $publicId): ?ProductCategoryId;

    public function findSeriesIdByPublicId(string $publicId): ?ProductSeriesId;

    /**
     * @throws \TriCab\Core\Product\Application\Exception\InvalidProductClassification
     */
    public function assertHierarchyValid(
        ?string $familyPublicId,
        ?string $categoryPublicId,
        ?string $seriesPublicId,
    ): void;

    public function findFamilyByPublicId(string $publicId, bool $includeDeleted = false): ?ProductClassificationRecord;

    public function findCategoryByPublicId(string $publicId, bool $includeDeleted = false): ?ProductClassificationRecord;

    public function findSeriesByPublicId(string $publicId, bool $includeDeleted = false): ?ProductClassificationRecord;

    /**
     * @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, parentPublicId?: ?string} $filters
     * @return list<ProductClassificationRecord>
     */
    public function listFamiliesAdmin(array $filters = []): array;

    /**
     * @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, parentPublicId?: ?string} $filters
     * @return list<ProductClassificationRecord>
     */
    public function listCategoriesAdmin(array $filters = []): array;

    /**
     * @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, parentPublicId?: ?string} $filters
     * @return list<ProductClassificationRecord>
     */
    public function listSeriesAdmin(array $filters = []): array;

    /**
     * @param array{name?: string, description?: ?string, displayOrder?: int, code?: string} $data
     */
    public function updateFamily(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    /**
     * @param array{name?: string, description?: ?string, displayOrder?: int, code?: string, familyPublicId?: string} $data
     */
    public function updateCategory(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    /**
     * @param array{name?: string, description?: ?string, displayOrder?: int, code?: string, categoryPublicId?: string} $data
     */
    public function updateSeries(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    public function setFamilyActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    public function setCategoryActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    public function setSeriesActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    public function softDeleteFamily(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    public function softDeleteCategory(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    public function softDeleteSeries(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    public function restoreFamily(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    public function restoreCategory(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    public function restoreSeries(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord;

    /**
     * @param list<string> $orderedPublicIds
     */
    public function reorderFamilies(array $orderedPublicIds, UserId $actorUserId): void;

    /**
     * @param list<string> $orderedPublicIds
     */
    public function reorderCategories(string $familyPublicId, array $orderedPublicIds, UserId $actorUserId): void;

    /**
     * @param list<string> $orderedPublicIds
     */
    public function reorderSeries(string $categoryPublicId, array $orderedPublicIds, UserId $actorUserId): void;

    public function getFamilyUsage(string $publicId): ProductClassificationUsage;

    public function getCategoryUsage(string $publicId): ProductClassificationUsage;

    public function getSeriesUsage(string $publicId): ProductClassificationUsage;

    public function familyCodeExists(string $code, ?string $excludePublicId = null): bool;

    public function categoryCodeExists(string $code, ?string $excludePublicId = null): bool;

    public function seriesCodeExists(string $code, ?string $excludePublicId = null): bool;
}
