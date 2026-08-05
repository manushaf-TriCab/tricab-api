<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductLookupItem;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/** Future Azure SQL: Regions/Markets/Industries/Applications + product link tables */
interface ProductMarketRepository
{
    /** @return list<ProductLookupItem> */
    public function listRegions(bool $activeOnly = true): array;

    /** @return list<ProductLookupItem> */
    public function listMarkets(?string $regionPublicId = null, bool $activeOnly = true): array;

    /** @return list<ProductLookupItem> */
    public function listIndustries(bool $activeOnly = true): array;

    /** @return list<ProductLookupItem> */
    public function listApplications(bool $activeOnly = true): array;

    /**
     * @param list<string> $regionPublicIds
     */
    public function replaceProductRegions(
        ProductPublicId $productPublicId,
        array $regionPublicIds,
        UserId $actorUserId,
    ): void;

    /**
     * @param list<string> $marketPublicIds
     */
    public function replaceProductMarkets(
        ProductPublicId $productPublicId,
        array $marketPublicIds,
        UserId $actorUserId,
    ): void;

    /**
     * @param list<string> $industryPublicIds
     */
    public function replaceProductIndustries(
        ProductPublicId $productPublicId,
        array $industryPublicIds,
        UserId $actorUserId,
    ): void;

    /**
     * @param list<string> $applicationPublicIds
     */
    public function replaceProductApplications(
        ProductPublicId $productPublicId,
        array $applicationPublicIds,
        UserId $actorUserId,
    ): void;
}
