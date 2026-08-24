<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class PriceListRecord
{
    public function __construct(
        public string $publicId,
        public string $code,
        public string $name,
        public string $currencyCode,
        public ?string $currencyPublicId,
        public ?string $regionPublicId,
        public ?string $regionCode,
        public ?string $regionName,
        public ?string $marketPublicId,
        public ?string $marketCode,
        public ?string $marketName,
        public ?string $legacySourceCode,
        public ?string $legacyContextType,
        public ?string $legacyContextCode,
        public ?string $effectiveFromUtc,
        public ?string $effectiveToUtc,
        public bool $isActive,
        public bool $isDeleted,
        public string $rowVersion,
        public ?string $createdAtUtc = null,
        public ?string $updatedAtUtc = null,
    ) {
    }
}
