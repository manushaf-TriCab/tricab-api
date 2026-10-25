<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductStyleAssignment;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Application\DTO\ProductStyleUsage;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;

/**
 * Persistence for style hierarchy, per-type inheritance modes, style-owned
 * specifications/assets, and product→style assignment.
 *
 * Style hierarchy is independent of Family/Category/Series and Catalogue Navigation.
 *
 * @phpstan-import-type SpecValue from ProductSpecificationRepository
     * @phpstan-type StyleAsset array{
     *   publicId?: string,
     *   resourceType: string,
     *   title: string,
     *   roleCode: ?string,
     *   altText: ?string,
     *   storageProviderCode: ?string,
     *   storageContainer: ?string,
     *   storagePath: ?string,
     *   publicUrl: ?string,
     *   contentType: ?string,
     *   checksumSha256: ?string,
     *   displayOrder: int,
     *   isPrimary: bool,
     *   uploadStatusCode?: string,
     *   byteSize?: int|null,
     *   originalFileName?: string|null,
     *   metadata: array<string, mixed>
     * }
 */
interface ProductStyleRepository
{
    /**
     * @return list<ProductStyleRecord>
     */
    public function listAll(bool $includeDeleted = false): array;

    public function findByPublicId(string $publicId, bool $includeDeleted = false): ?ProductStyleRecord;

    public function findByStyleCode(string $styleCode, bool $includeDeleted = false): ?ProductStyleRecord;

    public function styleCodeExists(string $code, ?string $excludePublicId = null): bool;

    /**
     * Immediate parent first, then grandparents toward the root.
     *
     * @return list<ProductStyleRecord>
     */
    public function listAncestors(string $publicId): array;

    /**
     * @return list<ProductStyleRecord>
     */
    public function listChildren(string $parentPublicId, bool $includeDeleted = false): array;

    /**
     * @param array{
     *   styleCode: string,
     *   displayName: string,
     *   parentPublicId: ?string,
     *   sortOrder: int,
     *   isActive: bool,
     *   inheritance: array<string, string>
     * } $data
     */
    public function create(array $data, UserId $actorUserId): ProductStyleRecord;

    /**
     * @param array<string, mixed> $data
     */
    public function update(
        string $publicId,
        array $data,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductStyleRecord;

    public function setParent(
        string $publicId,
        ?string $parentPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductStyleRecord;

    public function setActive(
        string $publicId,
        bool $isActive,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductStyleRecord;

    public function softDelete(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductStyleRecord;

    public function restore(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductStyleRecord;

    /**
     * @param list<string> $orderedPublicIds
     */
    public function reorder(?string $parentPublicId, array $orderedPublicIds, UserId $actorUserId): void;

    /**
     * @param array<string, string> $modes
     */
    public function updateInheritance(
        string $publicId,
        array $modes,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductStyleRecord;

    public function getUsage(string $publicId): ProductStyleUsage;

    /**
     * @return list<SpecValue>
     */
    public function listStyleSpecificationValues(string $stylePublicId): array;

    /**
     * @param list<string> $stylePublicIds
     * @return array<string, list<SpecValue>>
     */
    public function listSpecificationValuesForStyles(array $stylePublicIds): array;

    /**
     * @param list<SpecValue> $values
     */
    public function replaceStyleSpecificationValues(
        string $stylePublicId,
        array $values,
        UserId $actorUserId,
    ): void;

    /**
     * @return list<StyleAsset>
     */
    public function listStyleAssets(string $stylePublicId, StyleResourceType $type): array;

    /**
     * @param list<string> $stylePublicIds
     * @return array<string, list<StyleAsset>>
     */
    public function listAssetsForStyles(array $stylePublicIds, StyleResourceType $type): array;

    /**
     * @param list<StyleAsset> $assets
     */
    public function replaceStyleAssets(
        string $stylePublicId,
        StyleResourceType $type,
        array $assets,
        UserId $actorUserId,
    ): void;

    /**
     * @return StyleAsset|null
     */
    public function findStyleAssetByPublicId(string $stylePublicId, string $assetPublicId): ?array;

    /**
     * @param StyleAsset $asset
     * @return StyleAsset
     */
    public function createStyleAssetUploadSession(string $stylePublicId, array $asset, UserId $actorUserId): array;

    /**
     * @param array<string, mixed> $data
     * @return StyleAsset
     */
    public function markStyleAssetUploadCompleted(string $stylePublicId, string $assetPublicId, array $data, UserId $actorUserId): array;

    public function softDeleteStyleAsset(string $stylePublicId, string $assetPublicId, UserId $actorUserId): void;

    /**
     * Count non-deleted metadata rows that still reference the blob path.
     * Used so deleting one style/product record does not remove a shared blob.
     */
    public function countActiveStoragePathReferences(string $storagePath, ?string $storageContainer = null): int;

    public function findAssignmentByProductPublicId(string $productPublicId): ?ProductStyleAssignment;

    public function assignProduct(string $productPublicId, string $stylePublicId, UserId $actorUserId): ProductStyleAssignment;

    public function unassignProduct(string $productPublicId, UserId $actorUserId): void;

    /**
     * @return list<string>
     */
    public function listAssignedProductPublicIds(string $stylePublicId): array;

    /**
     * listAssignedProductPublicIds for every style in one read, keyed by style public id.
     * Styles without assignments may be absent.
     *
     * @return array<string, list<string>>
     */
    public function listAssignedProductPublicIdsByStyle(): array;
}
