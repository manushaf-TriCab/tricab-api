<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class UpsertPriceListCommand
{
    public function __construct(
        public string $name,
        public string $code,
        public string $currencyPublicId,
        public ?string $regionPublicId,
        public ?string $marketPublicId,
        public ?string $legacySourceCode,
        public ?string $legacyContextType,
        public ?string $legacyContextCode,
        public ?string $effectiveFromUtc,
        public ?string $effectiveToUtc,
        public bool $isActive,
        public ?string $expectedRowVersion = null,
        public ?string $priceListPublicId = null,
    ) {
    }
}
