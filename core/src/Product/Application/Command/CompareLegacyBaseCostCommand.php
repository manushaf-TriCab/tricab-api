<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class CompareLegacyBaseCostCommand
{
    public function __construct(
        public string $productPublicId,
        public string $variantPublicId,
        public int $legacyDebtorCompanyCode,
        public ?string $priceListPublicId = null,
    ) {
    }
}
