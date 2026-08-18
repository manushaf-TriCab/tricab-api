<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Application\DTO\LegacyProductMappingDetails;

/**
 * Future Azure SQL: migration.LegacyProductMappings
 * Migration processing is deferred; this is the persistence contract only.
 */
interface LegacyProductMappingRepository
{
    public function findBySource(string $sourceSystemCode, string $sourceProductKey): ?LegacyProductMappingDetails;

    /**
     * @param array{
     *   sourceSystemCode: string,
     *   sourceProductKey: string,
     *   productPublicId?: ?string,
     *   productVariantPublicId?: ?string,
     *   migrationStatusCode: string,
     *   migrationNotes?: ?string
     * } $data
     */
    public function upsert(array $data): LegacyProductMappingDetails;
}
