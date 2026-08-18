<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductPricingSummary;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Internal/admin-only. Never surfaced via public product reads.
 */
final class GetProductPricingService
{
    public function __construct(
        private readonly ProductPricingRepository $pricing,
    ) {
    }

    /**
     * @return list<ProductPricingSummary>
     */
    public function execute(string $publicId): array
    {
        return $this->pricing->listByProduct(ProductPublicId::fromString($publicId));
    }
}
