<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AuditLegacyVariantPricingIdentityCommand
{
    public function __construct(
        public ?string $productPublicId = null,
        public ?string $priceListPublicId = null,
        public int $offset = 0,
        public int $limit = 100,
    ) {
    }
}
