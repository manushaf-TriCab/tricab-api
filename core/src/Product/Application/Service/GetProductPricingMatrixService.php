<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetProductPricingMatrixService
{
    public function __construct(
        private readonly ProductPricingRepository $pricing,
    ) {
    }

    /**
     * @return list<\TriCab\Core\Product\Application\DTO\ProductPricingMatrixRow>
     */
    public function execute(string $publicId): array
    {
        return $this->pricing->listMatrixByProduct(ProductPublicId::fromString($publicId));
    }
}
