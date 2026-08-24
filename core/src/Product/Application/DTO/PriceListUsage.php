<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class PriceListUsage
{
    public function __construct(
        public int $productPriceCount,
        public int $variantPriceCount,
        public int $customerPriceCount,
        public int $priceListCustomerCount,
        public string $summary,
    ) {
    }
}
