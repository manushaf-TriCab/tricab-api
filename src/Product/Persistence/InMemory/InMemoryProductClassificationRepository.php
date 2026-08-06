<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductClassificationRecord;
use TriCab\Core\Product\Application\DTO\ProductClassificationUsage;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Application\Exception\InvalidProductClassification;
use TriCab\Core\Product\Application\Exception\ProductClassificationConcurrencyConflict;
use TriCab\Core\Product\Contract\ProductClassificationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductCategoryId;
use TriCab\Core\Product\Domain\ValueObject\ProductFamilyId;
use TriCab\Core\Product\Domain\ValueObject\ProductSeriesId;

final class InMemoryProductClassificationRepository implements ProductClassificationRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listFamilies(bool $activeOnly = true): array
    {
        return $this->mapLookup($this->alive($this->store->families()), $activeOnly);
    }

    public function listCategories(?string $familyPublicId = null, bool $activeOnly = true): array
    {
        $items = array_values(array_filter(
            $this->alive($this->store->categories()),
            static fn (array $row): bool => $familyPublicId === null || ($row['parentPublicId'] ?? null) === $familyPublicId,
        ));

        return $this->mapLookup($items, $activeOnly);
    }

    public function listSeries(?string $categoryPublicId = null, bool $activeOnly = true): array
    {
        $items = array_values(array_filter(
            $this->alive($this->store->series()),
            static fn (array $row): bool => $categoryPublicId === null || ($row['parentPublicId'] ?? null) === $categoryPublicId,
        ));

        return $this->mapLookup($items, $activeOnly);
    }

    public function createFamily(array $data, UserId $actorUserId): ProductLookupItem
    {
        unset($actorUserId);
        $item = $this->newRow($data, null);
        $this->store->addFamily($item);

        return $this->toLookupItem($item);
    }

    public function createCategory(array $data, UserId $actorUserId): ProductLookupItem
    {
        unset($actorUserId);
        $item = $this->newRow($data, $data['familyPublicId'] ?? null);
        $this->store->addCategory($item);

        return $this->toLookupItem($item);
    }

    public function createSeries(array $data, UserId $actorUserId): ProductLookupItem
    {
        unset($actorUserId);
        $item = $this->newRow($data, $data['categoryPublicId'] ?? null);
        $this->store->addSeries($item);

        return $this->toLookupItem($item);
    }

    public function findFamilyIdByPublicId(string $publicId): ?ProductFamilyId
    {
        $row = $this->findRow($this->store->families(), $publicId, true);
        return $row === null ? null : ProductFamilyId::fromInt((int) ($row['id'] ?? 0));
    }

    public function findCategoryIdByPublicId(string $publicId): ?ProductCategoryId
    {
        $row = $this->findRow($this->store->categories(), $publicId, true);
        return $row === null ? null : ProductCategoryId::fromInt((int) ($row['id'] ?? 0));
    }

    public function findSeriesIdByPublicId(string $publicId): ?ProductSeriesId
    {
        $row = $this->findRow($this->store->series(), $publicId, true);
        return $row === null ? null : ProductSeriesId::fromInt((int) ($row['id'] ?? 0));
    }

    public function assertHierarchyValid(
        ?string $familyPublicId,
        ?string $categoryPublicId,
        ?string $seriesPublicId,
    ): void {
        if ($familyPublicId !== null && $this->findFamilyIdByPublicId($familyPublicId) === null) {
            throw InvalidProductClassification::forPublicId($familyPublicId);
        }
        if ($categoryPublicId !== null) {
            $categoryRow = $this->findRow($this->store->categories(), $categoryPublicId, true);
            if ($categoryRow === null) {
                throw InvalidProductClassification::forPublicId($categoryPublicId);
            }
            if ($familyPublicId !== null && ($categoryRow['parentPublicId'] ?? null) !== $familyPublicId) {
                throw InvalidProductClassification::invalidHierarchy(
                    sprintf('Category "%s" does not belong to family "%s".', $categoryPublicId, $familyPublicId),
                );
            }
        }
        if ($seriesPublicId !== null) {
            $seriesRow = $this->findRow($this->store->series(), $seriesPublicId, true);
            if ($seriesRow === null) {
                throw InvalidProductClassification::forPublicId($seriesPublicId);
            }
            if ($categoryPublicId !== null && ($seriesRow['parentPublicId'] ?? null) !== $categoryPublicId) {
                throw InvalidProductClassification::invalidHierarchy(
                    sprintf('Series "%s" does not belong to category "%s".', $seriesPublicId, $categoryPublicId),
                );
            }
        }
    }

    public function findFamilyByPublicId(string $publicId, bool $includeDeleted = false): ?ProductClassificationRecord
    {
        $row = $this->findRow($this->store->families(), $publicId, false, $includeDeleted);
        return $row === null ? null : $this->toRecord($row, 'family');
    }

    public function findCategoryByPublicId(string $publicId, bool $includeDeleted = false): ?ProductClassificationRecord
    {
        $row = $this->findRow($this->store->categories(), $publicId, false, $includeDeleted);
        return $row === null ? null : $this->toRecord($row, 'category');
    }

    public function findSeriesByPublicId(string $publicId, bool $includeDeleted = false): ?ProductClassificationRecord
    {
        $row = $this->findRow($this->store->series(), $publicId, false, $includeDeleted);
        return $row === null ? null : $this->toRecord($row, 'series');
    }

    public function listFamiliesAdmin(array $filters = []): array
    {
        return $this->listAdmin($this->store->families(), $filters, 'family');
    }

    public function listCategoriesAdmin(array $filters = []): array
    {
        return $this->listAdmin($this->store->categories(), $filters, 'category');
    }

    public function listSeriesAdmin(array $filters = []): array
    {
        return $this->listAdmin($this->store->series(), $filters, 'series');
    }

    public function updateFamily(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutate('family', $publicId, $expectedRowVersion, $actorUserId, $data);
    }

    public function updateCategory(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        if (isset($data['familyPublicId'])) {
            $data['parentPublicId'] = $data['familyPublicId'];
        }

        return $this->mutate('category', $publicId, $expectedRowVersion, $actorUserId, $data);
    }

    public function updateSeries(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        if (isset($data['categoryPublicId'])) {
            $data['parentPublicId'] = $data['categoryPublicId'];
        }

        return $this->mutate('series', $publicId, $expectedRowVersion, $actorUserId, $data);
    }

    public function setFamilyActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutate('family', $publicId, $expectedRowVersion, $actorUserId, ['isActive' => $isActive]);
    }

    public function setCategoryActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutate('category', $publicId, $expectedRowVersion, $actorUserId, ['isActive' => $isActive]);
    }

    public function setSeriesActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutate('series', $publicId, $expectedRowVersion, $actorUserId, ['isActive' => $isActive]);
    }

    public function softDeleteFamily(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutate('family', $publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => $this->store->nowUtc(),
            'isActive' => false,
        ]);
    }

    public function softDeleteCategory(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutate('category', $publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => $this->store->nowUtc(),
            'isActive' => false,
        ]);
    }

    public function softDeleteSeries(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutate('series', $publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => $this->store->nowUtc(),
            'isActive' => false,
        ]);
    }

    public function restoreFamily(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutate('family', $publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => null,
            'isActive' => true,
        ], true);
    }

    public function restoreCategory(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutate('category', $publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => null,
            'isActive' => true,
        ], true);
    }

    public function restoreSeries(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductClassificationRecord
    {
        return $this->mutate('series', $publicId, $expectedRowVersion, $actorUserId, [
            'deletedAtUtc' => null,
            'isActive' => true,
        ], true);
    }

    public function reorderFamilies(array $orderedPublicIds, UserId $actorUserId): void
    {
        $this->reorder('family', null, $orderedPublicIds, $actorUserId);
    }

    public function reorderCategories(string $familyPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        $this->reorder('category', $familyPublicId, $orderedPublicIds, $actorUserId);
    }

    public function reorderSeries(string $categoryPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        $this->reorder('series', $categoryPublicId, $orderedPublicIds, $actorUserId);
    }

    public function getFamilyUsage(string $publicId): ProductClassificationUsage
    {
        $activeCategories = 0;
        $inactiveCategories = 0;
        $deletedChildren = 0;
        foreach ($this->store->categories() as $row) {
            if (($row['parentPublicId'] ?? null) !== $publicId) {
                continue;
            }
            if (($row['deletedAtUtc'] ?? null) !== null) {
                $deletedChildren++;
                continue;
            }
            if ($row['isActive'] ?? true) {
                $activeCategories++;
            } else {
                $inactiveCategories++;
            }
        }

        return new ProductClassificationUsage(
            publicId: $publicId,
            level: 'family',
            productCount: $this->countProducts('familyPublicId', $publicId),
            activeCategoryCount: $activeCategories,
            inactiveCategoryCount: $inactiveCategories,
            deletedChildCount: $deletedChildren,
        );
    }

    public function getCategoryUsage(string $publicId): ProductClassificationUsage
    {
        $activeSeries = 0;
        $inactiveSeries = 0;
        $deletedChildren = 0;
        foreach ($this->store->series() as $row) {
            if (($row['parentPublicId'] ?? null) !== $publicId) {
                continue;
            }
            if (($row['deletedAtUtc'] ?? null) !== null) {
                $deletedChildren++;
                continue;
            }
            if ($row['isActive'] ?? true) {
                $activeSeries++;
            } else {
                $inactiveSeries++;
            }
        }

        return new ProductClassificationUsage(
            publicId: $publicId,
            level: 'category',
            productCount: $this->countProducts('categoryPublicId', $publicId),
            activeSeriesCount: $activeSeries,
            inactiveSeriesCount: $inactiveSeries,
            deletedChildCount: $deletedChildren,
        );
    }

    public function getSeriesUsage(string $publicId): ProductClassificationUsage
    {
        return new ProductClassificationUsage(
            publicId: $publicId,
            level: 'series',
            productCount: $this->countProducts('seriesPublicId', $publicId),
        );
    }

    public function familyCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExists($this->store->families(), $code, $excludePublicId);
    }

    public function categoryCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExists($this->store->categories(), $code, $excludePublicId);
    }

    public function seriesCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        return $this->codeExists($this->store->series(), $code, $excludePublicId);
    }

    /**
     * @param array{code: string, name: string, description?: ?string, displayOrder?: int, isActive?: bool} $data
     * @return array<string, mixed>
     */
    private function newRow(array $data, ?string $parentPublicId): array
    {
        $now = $this->store->nowUtc();

        return [
            'publicId' => $this->store->newPublicId(),
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'parentPublicId' => $parentPublicId,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isActive' => $data['isActive'] ?? true,
            'deletedAtUtc' => null,
            'rowVersion' => $this->store->nextRowVersion()->toBase64(),
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
        ];
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array{search?: ?string, activeOnly?: ?bool, includeDeleted?: bool, parentPublicId?: ?string} $filters
     * @return list<ProductClassificationRecord>
     */
    private function listAdmin(array $rows, array $filters, string $level): array
    {
        $includeDeleted = (bool) ($filters['includeDeleted'] ?? false);
        $activeOnly = array_key_exists('activeOnly', $filters) ? $filters['activeOnly'] : null;
        $search = isset($filters['search']) ? strtolower(trim((string) $filters['search'])) : '';
        $parentPublicId = $filters['parentPublicId'] ?? null;
        $out = [];
        foreach ($rows as $row) {
            $deleted = ($row['deletedAtUtc'] ?? null) !== null;
            if ($deleted && !$includeDeleted) {
                continue;
            }
            if ($activeOnly === true && !($row['isActive'] ?? true)) {
                continue;
            }
            if ($activeOnly === false && ($row['isActive'] ?? true)) {
                continue;
            }
            if ($parentPublicId !== null && ($row['parentPublicId'] ?? null) !== $parentPublicId) {
                continue;
            }
            if ($search !== '') {
                $hay = strtolower((string) ($row['name'] ?? '') . ' ' . (string) ($row['code'] ?? ''));
                if (!str_contains($hay, $search)) {
                    continue;
                }
            }
            $out[] = $this->toRecord($row, $level);
        }
        usort(
            $out,
            static fn (ProductClassificationRecord $a, ProductClassificationRecord $b): int =>
                [$a->displayOrder, $a->name] <=> [$b->displayOrder, $b->name],
        );

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mutate(
        string $level,
        string $publicId,
        string $expectedRowVersion,
        UserId $actorUserId,
        array $data,
        bool $includeDeleted = false,
    ): ProductClassificationRecord {
        unset($actorUserId);
        $rows = match ($level) {
            'family' => $this->store->families(),
            'category' => $this->store->categories(),
            default => $this->store->series(),
        };
        $row = $this->findRow($rows, $publicId, false, $includeDeleted);
        if ($row === null) {
            throw ProductClassificationConcurrencyConflict::forPublicId($publicId);
        }
        if ((string) ($row['rowVersion'] ?? '') !== $expectedRowVersion) {
            throw ProductClassificationConcurrencyConflict::forPublicId($publicId);
        }
        foreach ($data as $key => $value) {
            $row[$key] = $value;
        }
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        match ($level) {
            'family' => $this->store->replaceFamily($publicId, $row),
            'category' => $this->store->replaceCategory($publicId, $row),
            default => $this->store->replaceSeries($publicId, $row),
        };

        return $this->toRecord($row, $level);
    }

    /**
     * @param list<string> $orderedPublicIds
     */
    private function reorder(string $level, ?string $parentPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        unset($actorUserId);
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $rows = match ($level) {
                'family' => $this->store->families(),
                'category' => $this->store->categories(),
                default => $this->store->series(),
            };
            $row = $this->findRow($rows, $publicId, false);
            if ($row === null) {
                continue;
            }
            if ($parentPublicId !== null && ($row['parentPublicId'] ?? null) !== $parentPublicId) {
                continue;
            }
            $row['displayOrder'] = $order;
            $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
            $row['updatedAtUtc'] = $this->store->nowUtc();
            match ($level) {
                'family' => $this->store->replaceFamily($publicId, $row),
                'category' => $this->store->replaceCategory($publicId, $row),
                default => $this->store->replaceSeries($publicId, $row),
            };
            $order += 10;
        }
    }

    private function countProducts(string $field, string $publicId): int
    {
        $count = 0;
        foreach ($this->store->productRecords() as $productRow) {
            $product = $productRow['product'];
            $value = match ($field) {
                'familyPublicId' => $this->store->familyPublicIdForId($product->familyId()),
                'categoryPublicId' => $this->store->categoryPublicIdForId($product->categoryId()),
                'seriesPublicId' => $this->store->seriesPublicIdForId($product->seriesId()),
                default => null,
            };
            if ($value === $publicId) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function codeExists(array $rows, string $code, ?string $excludePublicId): bool
    {
        $needle = strtoupper(trim($code));
        foreach ($rows as $row) {
            if (($row['deletedAtUtc'] ?? null) !== null) {
                continue;
            }
            if ($excludePublicId !== null && ($row['publicId'] ?? null) === $excludePublicId) {
                continue;
            }
            if (strtoupper((string) ($row['code'] ?? '')) === $needle) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function alive(array $rows): array
    {
        return array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['deletedAtUtc'] ?? null) === null,
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<string, mixed>|null
     */
    private function findRow(array $rows, string $publicId, bool $activeOnly, bool $includeDeleted = false): ?array
    {
        foreach ($rows as $row) {
            if (($row['publicId'] ?? null) !== $publicId) {
                continue;
            }
            $deleted = ($row['deletedAtUtc'] ?? null) !== null;
            if ($deleted && !$includeDeleted) {
                return null;
            }
            if ($activeOnly && !($row['isActive'] ?? true)) {
                return null;
            }

            return $row;
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ProductLookupItem>
     */
    private function mapLookup(array $rows, bool $activeOnly): array
    {
        $items = [];
        foreach ($rows as $row) {
            if ($activeOnly && !($row['isActive'] ?? true)) {
                continue;
            }
            $items[] = $this->toLookupItem($row);
        }

        return $items;
    }

    /** @param array<string, mixed> $row */
    private function toLookupItem(array $row): ProductLookupItem
    {
        return new ProductLookupItem(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            code: AzureSqlValueMapper::stringValue($row['code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['name'] ?? ''),
            parentPublicId: AzureSqlValueMapper::nullableString($row['parentPublicId'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['isActive'] ?? true),
        );
    }

    /** @param array<string, mixed> $row */
    private function toRecord(array $row, string $level): ProductClassificationRecord
    {
        $usage = match ($level) {
            'family' => $this->getFamilyUsage((string) $row['publicId']),
            'category' => $this->getCategoryUsage((string) $row['publicId']),
            default => $this->getSeriesUsage((string) $row['publicId']),
        };
        $parentName = null;
        $parentPublicId = AzureSqlValueMapper::nullableString($row['parentPublicId'] ?? null);
        if ($parentPublicId !== null) {
            $parent = match ($level) {
                'category' => $this->findRow($this->store->families(), $parentPublicId, false, true),
                'series' => $this->findRow($this->store->categories(), $parentPublicId, false, true),
                default => null,
            };
            $parentName = $parent !== null ? AzureSqlValueMapper::nullableString($parent['name'] ?? null) : null;
        }

        return new ProductClassificationRecord(
            publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
            code: AzureSqlValueMapper::stringValue($row['code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['name'] ?? ''),
            description: AzureSqlValueMapper::nullableString($row['description'] ?? null),
            parentPublicId: $parentPublicId,
            parentName: $parentName,
            displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['isActive'] ?? true),
            isDeleted: ($row['deletedAtUtc'] ?? null) !== null,
            rowVersion: AzureSqlValueMapper::stringValue($row['rowVersion'] ?? 'AAAAAAAAAAA='),
            createdAtUtc: AzureSqlValueMapper::nullableString($row['createdAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['updatedAtUtc'] ?? null),
            productCount: $usage->productCount,
            activeChildCount: match ($level) {
                'family' => $usage->activeCategoryCount,
                'category' => $usage->activeSeriesCount,
                default => 0,
            },
            inactiveChildCount: match ($level) {
                'family' => $usage->inactiveCategoryCount,
                'category' => $usage->inactiveSeriesCount,
                default => 0,
            },
        );
    }
}
