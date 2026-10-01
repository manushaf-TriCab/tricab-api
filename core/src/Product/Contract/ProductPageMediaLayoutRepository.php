<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\DescriptionImagePresentation;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

interface ProductPageMediaLayoutRepository
{
    public function find(ProductPublicId $productPublicId): DescriptionImagePresentation;

    public function save(
        ProductPublicId $productPublicId,
        DescriptionImagePresentation $presentation,
        UserId $actorUserId,
    ): void;
}
