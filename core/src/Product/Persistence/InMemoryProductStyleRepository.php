<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Persistence;

use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductStyleAssignment;
use TriCab\Core\Product\Application\DTO\ProductStyleInheritanceConfig;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Application\DTO\ProductStyleUsage;
use TriCab\Core\Product\Application\Exception\InvalidProductStyleHierarchy;
use TriCab\Core\Product\Application\Exception\ProductStyleConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductStyleNotFound;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;
use TriCab\Core\Shared\Time\Clock;
use TriCab\Core\Shared\Time\SystemClock;

/**
 * In-memory style hierarchy + inheritance store for tests and USER_REPOSITORY=memory.
 *
 * @phpstan-import-type SpecValue from \TriCab\Core\Product\Contract\ProductSpecificationRepository
 * @phpstan-import-type StyleAsset from ProductStyleRepository
 */
final class InMemoryProductStyleRepository implements ProductStyleRepository
{
    private readonly UuidV4Generator $uuids;

    /** @var list<array<string, mixed>> */
    private array $styles = [];

    /** @var array<string, list<SpecValue>> */
    private array $specValues = [];

    /** @var array<string, list<StyleAsset>> keyed by stylePublicId|resourceType */
    private array $assets = [];

    /** @var array<string, string> productPublicId => stylePublicId */
    private array $assignments = [];

    private int $rowVersionSequence = 1;

    public function __construct(
        private readonly Clock $clock = new SystemClock(),
    ) {
        $this->uuids = new UuidV4Generator();
    }

    public function listAll(bool $includeDeleted = false): array
    {
        $out = [];
        foreach ($this->styles as $row) {
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            $out[] = $this->toRecord($row);
        }
        usort(
            $out,
            static fn (ProductStyleRecord $a, ProductStyleRecord $b): int =>
                [$a->sortOrder, $a->styleCode] <=> [$b->sortOrder, $b->styleCode],
        );

        return $out;
    }

    public function findByPublicId(string $publicId, bool $includeDeleted = false): ?ProductStyleRecord
    {
        $row = $this->findRow($publicId, $includeDeleted);

        return $row === null ? null : $this->toRecord($row);
    }

    public function findByStyleCode(string $styleCode, bool $includeDeleted = false): ?ProductStyleRecord
    {
        $code = strtoupper(trim($styleCode));
        foreach ($this->styles as $row) {
            if (strtoupper((string) $row['styleCode']) !== $code) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }

            return $this->toRecord($row);
        }

        return null;
    }

    public function styleCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        $normalized = strtoupper(trim($code));
        foreach ($this->styles as $row) {
            if (strtoupper((string) $row['styleCode']) !== $normalized) {
                continue;
            }
            if ($excludePublicId !== null && (string) $row['publicId'] === $excludePublicId) {
                continue;
            }

            return true;
        }

        return false;
    }

    public function listAncestors(string $publicId): array
    {
        $out = [];
        $seen = [$publicId => true];
        $current = $this->findRow($publicId, true);
        while ($current !== null) {
            $parentPublicId = $current['parentPublicId'] ?? null;
            if (!is_string($parentPublicId) || $parentPublicId === '') {
                break;
            }
            if (isset($seen[$parentPublicId])) {
                break;
            }
            $seen[$parentPublicId] = true;
            $parent = $this->findRow($parentPublicId, true);
            if ($parent === null) {
                break;
            }
            $out[] = $this->toRecord($parent, false);
            $current = $parent;
        }

        return $out;
    }

    public function listChildren(string $parentPublicId, bool $includeDeleted = false): array
    {
        $out = [];
        foreach ($this->styles as $row) {
            if (($row['parentPublicId'] ?? null) !== $parentPublicId) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            $out[] = $this->toRecord($row);
        }
        usort(
            $out,
            static fn (ProductStyleRecord $a, ProductStyleRecord $b): int =>
                [$a->sortOrder, $a->styleCode] <=> [$b->sortOrder, $b->styleCode],
        );

        return $out;
    }

    public function create(array $data, UserId $actorUserId): ProductStyleRecord
    {
        unset($actorUserId);
        $publicId = $this->uuids->generate()->toString();
        $now = $this->clock->now()->format(DATE_ATOM);
        $row = [
            'publicId' => $publicId,
            'styleCode' => $data['styleCode'],
            'displayName' => $data['displayName'],
            'parentPublicId' => $data['parentPublicId'] ?? null,
            'sortOrder' => $data['sortOrder'] ?? 0,
            'isActive' => $data['isActive'] ?? true,
            'isDeleted' => false,
            'deletedAtUtc' => null,
            'rowVersion' => $this->nextRowVersion(),
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
            'inheritance' => $data['inheritance'],
        ];
        $this->styles[] = $row;

        return $this->toRecord($row);
    }

    public function update(
        string $publicId,
        array $data,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductStyleRecord {
        return $this->mutate($publicId, $expectedRowVersion, $actorUserId, $data);
    }

    public function setParent(
        string $publicId,
        ?string $parentPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductStyleRecord {
        if ($parentPublicId !== null && $this->findRow($parentPublicId, false) === null) {
            throw InvalidProductStyleHierarchy::parentNotFound($parentPublicId);
        }

        return $this->mutate($publicId, $expectedRowVersion, $actorUserId, [
            'parentPublicId' => $parentPublicId,
        ]);
    }

    public function setActive(
        string $publicId,
        bool $isActive,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductStyleRecord {
        return $this->mutate($publicId, $expectedRowVersion, $actorUserId, ['isActive' => $isActive]);
    }

    public function softDelete(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductStyleRecord
    {
        return $this->mutate($publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => $this->clock->now()->format(DATE_ATOM),
            'isDeleted' => true,
        ]);
    }

    public function restore(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductStyleRecord
    {
        return $this->mutate($publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => null,
            'isDeleted' => false,
        ], true);
    }

    public function reorder(?string $parentPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        unset($actorUserId);
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $index = $this->indexOf($publicId, false);
            if ($index === null) {
                continue;
            }
            $row = $this->styles[$index];
            $rowParent = $row['parentPublicId'] ?? null;
            if ($parentPublicId !== $rowParent) {
                continue;
            }
            $row['sortOrder'] = $order;
            $row['rowVersion'] = $this->nextRowVersion();
            $row['updatedAtUtc'] = $this->clock->now()->format(DATE_ATOM);
            $this->styles[$index] = $row;
            $order += 10;
        }
    }

    public function updateInheritance(
        string $publicId,
        array $modes,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): ProductStyleRecord {
        $existing = $this->requireRow($publicId, false);
        $config = ProductStyleInheritanceConfig::fromMap($modes, $this->inheritanceFromRow($existing));

        return $this->mutate($publicId, $expectedRowVersion, $actorUserId, [
            'inheritance' => $config->toMap(),
        ]);
    }

    public function getUsage(string $publicId): ProductStyleUsage
    {
        $this->requireRow($publicId, true);
        $childCount = 0;
        $activeChildCount = 0;
        $deletedChildCount = 0;
        foreach ($this->styles as $row) {
            if (($row['parentPublicId'] ?? null) !== $publicId) {
                continue;
            }
            if (($row['deletedAtUtc'] ?? null) !== null) {
                $deletedChildCount++;
                continue;
            }
            $childCount++;
            if (($row['isActive'] ?? true) === true) {
                $activeChildCount++;
            }
        }
        $productCount = count($this->listAssignedProductPublicIds($publicId));
        $parts = [];
        if ($productCount > 0) {
            $parts[] = sprintf('%d product(s)', $productCount);
        }
        if ($childCount > 0) {
            $parts[] = sprintf('%d child style(s)', $childCount);
        }
        if ($deletedChildCount > 0) {
            $parts[] = sprintf('%d archived child style(s)', $deletedChildCount);
        }

        return new ProductStyleUsage(
            publicId: $publicId,
            productCount: $productCount,
            childCount: $childCount,
            activeChildCount: $activeChildCount,
            deletedChildCount: $deletedChildCount,
            summary: $parts === [] ? 'No references.' : implode(', ', $parts) . '.',
        );
    }

    public function listStyleSpecificationValues(string $stylePublicId): array
    {
        return $this->specValues[$stylePublicId] ?? [];
    }

    public function listSpecificationValuesForStyles(array $stylePublicIds): array
    {
        $out = [];
        foreach ($stylePublicIds as $id) {
            $out[$id] = $this->specValues[$id] ?? [];
        }

        return $out;
    }

    public function replaceStyleSpecificationValues(
        string $stylePublicId,
        array $values,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        $this->requireRow($stylePublicId, false);
        $this->specValues[$stylePublicId] = array_values($values);
    }

    public function listStyleAssets(string $stylePublicId, StyleResourceType $type): array
    {
        $assets = $this->assets[$this->assetKey($stylePublicId, $type)] ?? [];
        $active = [];
        foreach ($assets as $asset) {
            $status = is_string($asset['uploadStatusCode'] ?? null) ? $asset['uploadStatusCode'] : 'active';
            if ($status === 'pending_upload' || $status === 'failed' || $status === 'deleted') {
                continue;
            }
            $active[] = $asset;
        }

        return $active;
    }

    public function listAssetsForStyles(array $stylePublicIds, StyleResourceType $type): array
    {
        $out = [];
        foreach ($stylePublicIds as $id) {
            $out[$id] = $this->listStyleAssets($id, $type);
        }

        return $out;
    }

    public function replaceStyleAssets(
        string $stylePublicId,
        StyleResourceType $type,
        array $assets,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        $this->requireRow($stylePublicId, false);
        $normalized = [];
        foreach ($assets as $asset) {
            $normalized[] = $this->normalizeAsset($asset, $type);
        }
        $this->assets[$this->assetKey($stylePublicId, $type)] = $normalized;
    }

    public function findStyleAssetByPublicId(string $stylePublicId, string $assetPublicId): ?array
    {
        foreach (StyleResourceType::all() as $type) {
            if ($type->isSpecifications()) {
                continue;
            }
            foreach ($this->assets[$this->assetKey($stylePublicId, $type)] ?? [] as $asset) {
                if (($asset['publicId'] ?? null) === $assetPublicId) {
                    return $asset;
                }
            }
        }

        return null;
    }

    public function createStyleAssetUploadSession(string $stylePublicId, array $asset, UserId $actorUserId): array
    {
        unset($actorUserId);
        $this->requireRow($stylePublicId, false);
        $type = StyleResourceType::fromString((string) $asset['resourceType']);
        $normalized = $this->normalizeAsset($asset, $type);
        $key = $this->assetKey($stylePublicId, $type);
        $this->assets[$key] = $this->assets[$key] ?? [];
        $this->assets[$key][] = $normalized;

        return $normalized;
    }

    public function markStyleAssetUploadCompleted(string $stylePublicId, string $assetPublicId, array $data, UserId $actorUserId): array
    {
        unset($actorUserId);
        foreach (StyleResourceType::all() as $type) {
            if ($type->isSpecifications()) {
                continue;
            }
            $key = $this->assetKey($stylePublicId, $type);
            foreach ($this->assets[$key] ?? [] as $index => $asset) {
                if (($asset['publicId'] ?? null) !== $assetPublicId) {
                    continue;
                }
                foreach ($data as $field => $value) {
                    $asset[$field] = $value;
                }
                $this->assets[$key][$index] = $asset;

                return $asset;
            }
        }

        throw ProductStyleNotFound::forPublicId($assetPublicId);
    }

    public function softDeleteStyleAsset(string $stylePublicId, string $assetPublicId, UserId $actorUserId): void
    {
        unset($actorUserId);
        foreach (StyleResourceType::all() as $type) {
            if ($type->isSpecifications()) {
                continue;
            }
            $key = $this->assetKey($stylePublicId, $type);
            $kept = [];
            $found = false;
            foreach ($this->assets[$key] ?? [] as $asset) {
                if (($asset['publicId'] ?? null) === $assetPublicId) {
                    $found = true;
                    continue;
                }
                $kept[] = $asset;
            }
            if ($found) {
                $this->assets[$key] = $kept;

                return;
            }
        }

        throw ProductStyleNotFound::forPublicId($assetPublicId);
    }

    public function countActiveStoragePathReferences(string $storagePath, ?string $storageContainer = null): int
    {
        $count = 0;
        foreach ($this->assets as $list) {
            foreach ($list as $asset) {
                if (($asset['storagePath'] ?? null) !== $storagePath) {
                    continue;
                }
                if ($storageContainer !== null && ($asset['storageContainer'] ?? null) !== $storageContainer) {
                    continue;
                }
                $count++;
            }
        }

        return $count;
    }

    public function findAssignmentByProductPublicId(string $productPublicId): ?ProductStyleAssignment
    {
        $stylePublicId = $this->assignments[$productPublicId] ?? null;
        if ($stylePublicId === null) {
            return null;
        }
        $style = $this->findByPublicId($stylePublicId, true);
        if ($style === null) {
            return null;
        }

        return $this->toAssignment($productPublicId, $style);
    }

    public function assignProduct(string $productPublicId, string $stylePublicId, UserId $actorUserId): ProductStyleAssignment
    {
        unset($actorUserId);
        $style = $this->findByPublicId($stylePublicId, false);
        if ($style === null) {
            throw ProductStyleNotFound::forPublicId($stylePublicId);
        }
        $this->assignments[$productPublicId] = $stylePublicId;

        return $this->toAssignment($productPublicId, $style);
    }

    public function unassignProduct(string $productPublicId, UserId $actorUserId): void
    {
        unset($actorUserId, $this->assignments[$productPublicId]);
    }

    public function listAssignedProductPublicIds(string $stylePublicId): array
    {
        $ids = [];
        foreach ($this->assignments as $productPublicId => $assignedStyle) {
            if ($assignedStyle === $stylePublicId) {
                $ids[] = $productPublicId;
            }
        }

        return $ids;
    }

    public function listAssignedProductPublicIdsByStyle(): array
    {
        $out = [];
        foreach ($this->assignments as $productPublicId => $assignedStyle) {
            $out[$assignedStyle][] = $productPublicId;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mutate(
        string $publicId,
        string $expectedRowVersion,
        UserId $actorUserId,
        array $data,
        bool $includeDeleted = false,
    ): ProductStyleRecord {
        unset($actorUserId);
        $index = $this->indexOf($publicId, $includeDeleted);
        if ($index === null) {
            throw ProductStyleConcurrencyConflict::forPublicId($publicId);
        }
        $row = $this->styles[$index];
        if ((string) ($row['rowVersion'] ?? '') !== $expectedRowVersion) {
            throw ProductStyleConcurrencyConflict::forPublicId($publicId);
        }
        foreach ($data as $key => $value) {
            $row[$key] = $value;
        }
        $row['rowVersion'] = $this->nextRowVersion();
        $row['updatedAtUtc'] = $this->clock->now()->format(DATE_ATOM);
        $this->styles[$index] = $row;

        return $this->toRecord($row);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireRow(string $publicId, bool $includeDeleted): array
    {
        $row = $this->findRow($publicId, $includeDeleted);
        if ($row === null) {
            throw ProductStyleNotFound::forPublicId($publicId);
        }

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRow(string $publicId, bool $includeDeleted): ?array
    {
        foreach ($this->styles as $row) {
            if ((string) $row['publicId'] !== $publicId) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                return null;
            }

            return $row;
        }

        return null;
    }

    private function indexOf(string $publicId, bool $includeDeleted): ?int
    {
        foreach ($this->styles as $index => $row) {
            if ((string) $row['publicId'] !== $publicId) {
                continue;
            }
            if (!$includeDeleted && ($row['deletedAtUtc'] ?? null) !== null) {
                return null;
            }

            return $index;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toRecord(array $row, bool $withGraph = true): ProductStyleRecord
    {
        $publicId = (string) $row['publicId'];
        $parentPublicId = isset($row['parentPublicId']) && is_string($row['parentPublicId']) && $row['parentPublicId'] !== ''
            ? $row['parentPublicId']
            : null;
        $parent = $parentPublicId !== null ? $this->findRow($parentPublicId, true) : null;
        $children = [];
        $activeChildCount = 0;
        $ancestors = [];
        if ($withGraph) {
            foreach ($this->styles as $child) {
                if (($child['parentPublicId'] ?? null) !== $publicId) {
                    continue;
                }
                if (($child['deletedAtUtc'] ?? null) !== null) {
                    continue;
                }
                $children[] = [
                    'publicId' => (string) $child['publicId'],
                    'styleCode' => (string) $child['styleCode'],
                    'displayName' => (string) $child['displayName'],
                    'isActive' => (bool) ($child['isActive'] ?? true),
                ];
                if (($child['isActive'] ?? true) === true) {
                    $activeChildCount++;
                }
            }
            foreach ($this->listAncestors($publicId) as $ancestor) {
                $ancestors[] = [
                    'publicId' => $ancestor->publicId,
                    'styleCode' => $ancestor->styleCode,
                    'displayName' => $ancestor->displayName,
                ];
            }
        }

        return new ProductStyleRecord(
            publicId: $publicId,
            styleCode: (string) $row['styleCode'],
            displayName: (string) $row['displayName'],
            parentPublicId: $parentPublicId,
            parentStyleCode: $parent !== null ? (string) $parent['styleCode'] : null,
            parentDisplayName: $parent !== null ? (string) $parent['displayName'] : null,
            sortOrder: (int) ($row['sortOrder'] ?? 0),
            isActive: (bool) ($row['isActive'] ?? true),
            isDeleted: ($row['deletedAtUtc'] ?? null) !== null,
            rowVersion: (string) $row['rowVersion'],
            inheritance: $this->inheritanceFromRow($row),
            createdAtUtc: isset($row['createdAtUtc']) && is_string($row['createdAtUtc']) ? $row['createdAtUtc'] : null,
            updatedAtUtc: isset($row['updatedAtUtc']) && is_string($row['updatedAtUtc']) ? $row['updatedAtUtc'] : null,
            productCount: count($this->listAssignedProductPublicIds($publicId)),
            childCount: count($children),
            activeChildCount: $activeChildCount,
            ancestors: $ancestors,
            children: $children,
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function inheritanceFromRow(array $row): ProductStyleInheritanceConfig
    {
        $map = is_array($row['inheritance'] ?? null) ? $row['inheritance'] : [];
        /** @var array<string, string> $typed */
        $typed = [];
        foreach ($map as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $typed[$key] = $value;
            }
        }

        return ProductStyleInheritanceConfig::fromMap($typed, ProductStyleInheritanceConfig::localAll());
    }

    private function toAssignment(string $productPublicId, ProductStyleRecord $style): ProductStyleAssignment
    {
        $ancestors = [];
        foreach ($style->ancestors as $ancestor) {
            $ancestors[] = $ancestor;
        }

        return new ProductStyleAssignment(
            productPublicId: $productPublicId,
            stylePublicId: $style->publicId,
            styleCode: $style->styleCode,
            displayName: $style->displayName,
            parentPublicId: $style->parentPublicId,
            parentStyleCode: $style->parentStyleCode,
            ancestors: $ancestors,
        );
    }

    private function assetKey(string $stylePublicId, StyleResourceType $type): string
    {
        return $stylePublicId . '|' . $type->toString();
    }

    /**
     * @param array<string, mixed> $asset
     * @return StyleAsset
     */
    private function normalizeAsset(array $asset, StyleResourceType $type): array
    {
        $publicId = isset($asset['publicId']) && is_string($asset['publicId']) && $asset['publicId'] !== ''
            ? $asset['publicId']
            : $this->uuids->generate()->toString();
        $metadata = is_array($asset['metadata'] ?? null) ? $asset['metadata'] : [];

        return [
            'publicId' => $publicId,
            'resourceType' => $type->toString(),
            'title' => is_string($asset['title'] ?? null) ? $asset['title'] : 'Asset',
            'roleCode' => isset($asset['roleCode']) && is_string($asset['roleCode']) ? $asset['roleCode'] : null,
            'altText' => isset($asset['altText']) && is_string($asset['altText']) ? $asset['altText'] : null,
            'storageProviderCode' => isset($asset['storageProviderCode']) && is_string($asset['storageProviderCode'])
                ? $asset['storageProviderCode']
                : 'azure-blob',
            'storageContainer' => isset($asset['storageContainer']) && is_string($asset['storageContainer'])
                ? $asset['storageContainer']
                : null,
            'storagePath' => isset($asset['storagePath']) && is_string($asset['storagePath']) ? $asset['storagePath'] : null,
            'publicUrl' => isset($asset['publicUrl']) && is_string($asset['publicUrl']) ? $asset['publicUrl'] : null,
            'contentType' => isset($asset['contentType']) && is_string($asset['contentType']) ? $asset['contentType'] : null,
            'checksumSha256' => isset($asset['checksumSha256']) && is_string($asset['checksumSha256'])
                ? $asset['checksumSha256']
                : null,
            'displayOrder' => is_int($asset['displayOrder'] ?? null) ? $asset['displayOrder'] : 0,
            'isPrimary' => (bool) ($asset['isPrimary'] ?? false),
            'uploadStatusCode' => isset($asset['uploadStatusCode']) && is_string($asset['uploadStatusCode'])
                ? $asset['uploadStatusCode']
                : 'active',
            'byteSize' => isset($asset['byteSize']) && is_int($asset['byteSize']) ? $asset['byteSize'] : null,
            'originalFileName' => isset($asset['originalFileName']) && is_string($asset['originalFileName'])
                ? $asset['originalFileName']
                : null,
            'metadata' => $metadata,
        ];
    }

    private function nextRowVersion(): string
    {
        $binary = pack('J', $this->rowVersionSequence++);

        return RowVersionToken::fromBinary($binary)->toBase64();
    }
}
