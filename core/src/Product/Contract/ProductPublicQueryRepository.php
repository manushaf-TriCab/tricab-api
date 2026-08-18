<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\ProductPublicDetail;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\ProductSlug;

/**
 * Query repository for public/customer product reads.
 *
 * Always excludes soft-deleted and archived products, and unpublished /
 * discontinued records outside the publish window. Never includes pricing.
 */
interface ProductPublicQueryRepository
{
    public function findBySlug(ProductSlug $slug, ?string $regionCode = null): ?ProductPublicDetail;

    public function findByPublicId(ProductPublicId $publicId, ?string $regionCode = null): ?ProductPublicDetail;
}
