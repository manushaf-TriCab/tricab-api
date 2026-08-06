<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Domain\Entity;

use TriCab\Core\Product\Domain\ValueObject\ProductId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;
use TriCab\Core\Product\Domain\ValueObject\RegionId;

final class ProductSlugAssignment
{
    public function __construct(
        private readonly ProductId $productId,
        private readonly ProductSlug $slug,
        private readonly bool $isPrimary = true,
        private readonly ?RegionId $regionId = null,
    ) {
    }

    public function productId(): ProductId
    {
        return $this->productId;
    }

    public function slug(): ProductSlug
    {
        return $this->slug;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function regionId(): ?RegionId
    {
        return $this->regionId;
    }
}
