<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/** Admin dropdown item for pricing forms. */
final readonly class PriceListLookupItem
{
    public function __construct(
        public string $publicId,
        public string $code,
        public string $name,
        public string $currencyCode,
        public ?string $regionName,
        public ?string $legacySourceCode,
        public ?string $legacyContextCode,
        public bool $isActive,
    ) {
    }
}
