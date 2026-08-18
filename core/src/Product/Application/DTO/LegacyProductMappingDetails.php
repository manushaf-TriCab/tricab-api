<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class LegacyProductMappingDetails
{
    public function __construct(
        public int $legacyProductMappingId,
        public string $sourceSystemCode,
        public string $sourceProductKey,
        public ?string $productPublicId,
        public ?string $productVariantPublicId,
        public string $migrationStatusCode,
        public ?string $migrationNotes,
        public string $createdAtUtc,
        public string $updatedAtUtc,
        public ?string $migratedAtUtc,
    ) {
    }
}
