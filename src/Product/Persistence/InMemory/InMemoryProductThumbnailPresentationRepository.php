<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Contract\ProductThumbnailPresentationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ThumbnailBackgroundColor;

final class InMemoryProductThumbnailPresentationRepository implements ProductThumbnailPresentationRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function findThumbnailBackgroundColor(ProductPublicId $productPublicId): ?string
    {
        return $this->store->thumbnailBackgroundColorForProduct($productPublicId);
    }

    public function updateThumbnailBackgroundColor(
        ProductPublicId $productPublicId,
        ?ThumbnailBackgroundColor $color,
        UserId $actorUserId,
    ): void {
        unset($actorUserId);
        $this->store->setThumbnailBackgroundColor($productPublicId, $color?->toString());
    }
}
