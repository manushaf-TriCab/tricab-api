<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Core\Product\Contract\ProductIntegrationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class InMemoryProductIntegrationRepository implements ProductIntegrationRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function listStatuses(ProductPublicId $productPublicId): array
    {
        return array_map(
            static fn (array $row): array => [
                'systemCode' => AzureSqlValueMapper::stringValue($row['systemCode'] ?? ''),
                'syncStatusCode' => AzureSqlValueMapper::stringValue($row['syncStatusCode'] ?? ''),
                'lastSyncedAtUtc' => AzureSqlValueMapper::nullableString($row['lastSyncedAtUtc'] ?? null),
                'lastErrorMessage' => AzureSqlValueMapper::nullableString($row['lastErrorMessage'] ?? null),
            ],
            $this->store->integrationStatuses($productPublicId->toString()),
        );
    }
}
