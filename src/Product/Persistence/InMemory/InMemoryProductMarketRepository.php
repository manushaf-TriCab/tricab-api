<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Contract\ProductMarketRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class InMemoryProductMarketRepository implements ProductMarketRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listRegions(bool $activeOnly = true): array
    {
        return $this->mapLookup($this->store->regions(), $activeOnly);
    }

    public function listMarkets(?string $regionPublicId = null, bool $activeOnly = true): array
    {
        $items = array_filter(
            $this->store->markets(),
            static fn (array $row): bool => $regionPublicId === null || ($row['parentPublicId'] ?? null) === $regionPublicId,
        );

        return $this->mapLookup(array_values($items), $activeOnly);
    }

    public function listIndustries(bool $activeOnly = true): array
    {
        return $this->mapLookup($this->store->industries(), $activeOnly);
    }

    public function listApplications(bool $activeOnly = true): array
    {
        return $this->mapLookup($this->store->applications(), $activeOnly);
    }

    public function replaceProductRegions(
        ProductPublicId $productPublicId,
        array $regionPublicIds,
        UserId $actorUserId,
    ): void {
        $this->store->setProductRegions($productPublicId->toString(), $regionPublicIds);
    }

    public function replaceProductMarkets(
        ProductPublicId $productPublicId,
        array $marketPublicIds,
        UserId $actorUserId,
    ): void {
        $this->store->setProductMarkets($productPublicId->toString(), $marketPublicIds);
    }

    public function replaceProductIndustries(
        ProductPublicId $productPublicId,
        array $industryPublicIds,
        UserId $actorUserId,
    ): void {
        $this->store->setProductIndustries($productPublicId->toString(), $industryPublicIds);
    }

    public function replaceProductApplications(
        ProductPublicId $productPublicId,
        array $applicationPublicIds,
        UserId $actorUserId,
    ): void {
        $this->store->setProductApplications($productPublicId->toString(), $applicationPublicIds);
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
            $items[] = new ProductLookupItem(
                publicId: AzureSqlValueMapper::stringValue($row['publicId'] ?? ''),
                code: AzureSqlValueMapper::stringValue($row['code'] ?? ''),
                name: AzureSqlValueMapper::stringValue($row['name'] ?? ''),
                parentPublicId: AzureSqlValueMapper::nullableString($row['parentPublicId'] ?? null),
                displayOrder: AzureSqlValueMapper::intValue($row['displayOrder'] ?? 0),
                isActive: AzureSqlValueMapper::boolValue($row['isActive'] ?? true),
            );
        }

        return $items;
    }
}
