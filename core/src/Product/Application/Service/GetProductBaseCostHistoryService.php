<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Service;

use TriCab\Core\Product\Contract\ProductPricingRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class GetProductBaseCostHistoryService
{
    public function __construct(
        private readonly ProductPricingRepository $pricing,
    ) {
    }

    /**
     * @return list<\TriCab\Core\Product\Application\DTO\ProductBaseCostHistoryEntry>
     */
    public function execute(
        string $publicId,
        ?string $variantPublicId = null,
        ?string $priceListPublicId = null,
    ): array {
        return $this->pricing->listHistory(
            ProductPublicId::fromString($publicId),
            $variantPublicId,
            $priceListPublicId,
        );
    }
}
