<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Future Azure SQL: ExternalSystems / ExternalEntityMappings /
 * ProductIntegrationStatuses / ProductSyncLogs
 *
 * @phpstan-type IntegrationStatus array{
 *   systemCode: string,
 *   syncStatusCode: string,
 *   lastSyncedAtUtc: ?string,
 *   lastErrorMessage: ?string
 * }
 */
interface ProductIntegrationRepository
{
    /**
     * @return list<IntegrationStatus>
     */
    public function listStatuses(ProductPublicId $productPublicId): array;
}
