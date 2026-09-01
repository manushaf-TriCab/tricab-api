<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ThumbnailBackgroundColor;

interface ProductThumbnailPresentationRepository
{
    public function findThumbnailBackgroundColor(ProductPublicId $productPublicId): ?string;

    public function updateThumbnailBackgroundColor(
        ProductPublicId $productPublicId,
        ?ThumbnailBackgroundColor $color,
        UserId $actorUserId,
    ): void;
}
