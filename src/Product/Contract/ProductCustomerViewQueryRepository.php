<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\ProductCustomerViewDetail;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Admin customer-view aggregate: customer-safe datasheet preview for any
 * non-deleted product (including draft / unpublished / archived).
 */
interface ProductCustomerViewQueryRepository
{
    public function findByPublicId(ProductPublicId $publicId, ?string $regionCode = null): ?ProductCustomerViewDetail;
}
