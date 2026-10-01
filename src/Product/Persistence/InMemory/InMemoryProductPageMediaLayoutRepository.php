<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductPageMediaLayoutRepository;
use TriCab\Core\Product\Domain\ValueObject\DescriptionImagePresentation;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class InMemoryProductPageMediaLayoutRepository implements ProductPageMediaLayoutRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function find(ProductPublicId $productPublicId): DescriptionImagePresentation
    {
        $stored = $this->store->pageMediaLayoutForProduct($productPublicId);
        if ($stored === null) {
            return DescriptionImagePresentation::defaults();
        }

        return DescriptionImagePresentation::resolve($stored['width'], $stored['alignment']);
    }

    public function save(
        ProductPublicId $productPublicId,
        DescriptionImagePresentation $presentation,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        $this->store->setPageMediaLayout(
            $productPublicId,
            $presentation->width(),
            $presentation->alignment(),
        );
    }
}
