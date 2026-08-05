<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Application\Exception\InvalidProductClassification;
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
        return $this->mapLookup($this->store->families(), $activeOnly);
    }

    public function listCategories(?string $familyPublicId = null, bool $activeOnly = true): array
    {
        $items = array_filter(
            $this->store->categories(),
            static fn (array $row): bool => $familyPublicId === null || ($row['parentPublicId'] ?? null) === $familyPublicId,
        );

        return $this->mapLookup(array_values($items), $activeOnly);
    }

    public function listSeries(?string $categoryPublicId = null, bool $activeOnly = true): array
    {
        $items = array_filter(
            $this->store->series(),
            static fn (array $row): bool => $categoryPublicId === null || ($row['parentPublicId'] ?? null) === $categoryPublicId,
        );

        return $this->mapLookup(array_values($items), $activeOnly);
    }

    public function createFamily(array $data, UserId $actorUserId): ProductLookupItem
    {
        $item = [
            'publicId' => $this->store->newPublicId(),
            'code' => $data['code'],
            'name' => $data['name'],
            'parentPublicId' => null,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isActive' => true,
        ];
        $this->store->addFamily($item);

        return $this->toLookupItem($item);
    }

    public function createCategory(array $data, UserId $actorUserId): ProductLookupItem
    {
        $item = [
            'publicId' => $this->store->newPublicId(),
            'code' => $data['code'],
            'name' => $data['name'],
            'parentPublicId' => $data['familyPublicId'] ?? null,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isActive' => true,
        ];
        $this->store->addCategory($item);

        return $this->toLookupItem($item);
    }

    public function createSeries(array $data, UserId $actorUserId): ProductLookupItem
    {
        $item = [
            'publicId' => $this->store->newPublicId(),
            'code' => $data['code'],
            'name' => $data['name'],
            'parentPublicId' => $data['categoryPublicId'] ?? null,
            'displayOrder' => $data['displayOrder'] ?? 0,
            'isActive' => true,
        ];
        $this->store->addSeries($item);

        return $this->toLookupItem($item);
    }

    public function findFamilyIdByPublicId(string $publicId): ?ProductFamilyId
    {
        foreach ($this->store->families() as $row) {
            if (($row['publicId'] ?? null) === $publicId && ($row['isActive'] ?? true)) {
                return ProductFamilyId::fromInt(AzureSqlValueMapper::intValue($row['id'] ?? 0));
            }
        }

        return null;
    }

    public function findCategoryIdByPublicId(string $publicId): ?ProductCategoryId
    {
        foreach ($this->store->categories() as $row) {
            if (($row['publicId'] ?? null) === $publicId && ($row['isActive'] ?? true)) {
                return ProductCategoryId::fromInt(AzureSqlValueMapper::intValue($row['id'] ?? 0));
            }
        }

        return null;
    }

    public function findSeriesIdByPublicId(string $publicId): ?ProductSeriesId
    {
        foreach ($this->store->series() as $row) {
            if (($row['publicId'] ?? null) === $publicId && ($row['isActive'] ?? true)) {
                return ProductSeriesId::fromInt(AzureSqlValueMapper::intValue($row['id'] ?? 0));
            }
        }

        return null;
    }

    public function assertHierarchyValid(
        ?string $familyPublicId,
        ?string $categoryPublicId,
        ?string $seriesPublicId,
    ): void {
        if ($familyPublicId !== null && $this->findFamilyIdByPublicId($familyPublicId) === null) {
            throw InvalidProductClassification::forPublicId($familyPublicId);
        }

        $categoryRow = null;
        if ($categoryPublicId !== null) {
            foreach ($this->store->categories() as $row) {
                if (($row['publicId'] ?? null) === $categoryPublicId && ($row['isActive'] ?? true)) {
                    $categoryRow = $row;
                    break;
                }
            }
            if ($categoryRow === null) {
                throw InvalidProductClassification::forPublicId($categoryPublicId);
            }
            $categoryFamilyPublicId = $categoryRow['parentPublicId'] ?? null;
            if ($familyPublicId !== null && $categoryFamilyPublicId !== $familyPublicId) {
                throw InvalidProductClassification::invalidHierarchy(
                    sprintf('Category "%s" does not belong to family "%s".', $categoryPublicId, $familyPublicId),
                );
            }
        }

        if ($seriesPublicId !== null) {
            $seriesRow = null;
            foreach ($this->store->series() as $row) {
                if (($row['publicId'] ?? null) === $seriesPublicId && ($row['isActive'] ?? true)) {
                    $seriesRow = $row;
                    break;
                }
            }
            if ($seriesRow === null) {
                throw InvalidProductClassification::forPublicId($seriesPublicId);
            }
            $seriesCategoryPublicId = $seriesRow['parentPublicId'] ?? null;
            if ($categoryPublicId !== null && $seriesCategoryPublicId !== $categoryPublicId) {
                throw InvalidProductClassification::invalidHierarchy(
                    sprintf('Series "%s" does not belong to category "%s".', $seriesPublicId, $categoryPublicId),
                );
            }
        }
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

    /**
     * @param array<string, mixed> $row
     */
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
}
