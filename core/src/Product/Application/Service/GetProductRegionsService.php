<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductRegionAssignmentItem;
use TriCab\Core\Product\Application\DTO\RegionListCriteria;
use TriCab\Core\Product\Application\Exception\ProductNotFound;
use TriCab\Core\Product\Contract\ProductAdminQueryRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetProductRegionsService
{
    public function __construct(
        private readonly ProductAdminQueryRepository $adminQuery,
        private readonly GetRegionsService $regions,
    ) {
    }

    /**
     * @return list<ProductRegionAssignmentItem>
     */
    public function execute(string $productPublicId): array
    {
        $publicId = ProductPublicId::fromString($productPublicId);
        $detail = $this->adminQuery->findDetailByPublicId($publicId);
        if ($detail === null) {
            throw ProductNotFound::forPublicId($productPublicId);
        }

        /** @var array<string, true> $selectedCodes */
        $selectedCodes = array_fill_keys($detail->regionCodes, true);

        $result = $this->regions->adminList(new RegionListCriteria(
            page: 1,
            pageSize: 100,
            isActive: true,
            sortBy: 'displayOrder',
            sortDirection: 'asc',
        ));

        $available = [];
        foreach ($result->items as $region) {
            $available[] = new ProductRegionAssignmentItem(
                publicId: $region->publicId,
                code: $region->code,
                displayName: $region->displayName,
                flag: $region->flag,
                selected: isset($selectedCodes[$region->code]),
            );
        }

        return $available;
    }
}
