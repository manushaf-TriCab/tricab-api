<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Application\DTO\ProductPricingOverview;
use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetProductPricingOverviewService
{
    public function __construct(
        private readonly ProductPricingRepository $pricing,
    ) {
    }

    public function execute(string $publicId): ProductPricingOverview
    {
        return $this->pricing->getOverview(ProductPublicId::fromString($publicId));
    }
}
