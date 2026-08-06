<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Contract\ProductIntegrationRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

/**
 * Azure SQL integration status reads for products.
 * Tables: product.ProductIntegrationStatuses, product.ExternalSystems.
 */
final class AzureSqlProductIntegrationRepository implements ProductIntegrationRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listStatuses(ProductPublicId $productPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    es.[SystemCode],
    pis.[SyncStatusCode],
    CONVERT(VARCHAR(33), pis.[LastSyncedAtUtc], 127) AS [LastSyncedAtUtc],
    pis.[LastErrorMessage]
FROM [product].[ProductIntegrationStatuses] pis
INNER JOIN [product].[Products] p ON p.[ProductId] = pis.[ProductId]
INNER JOIN [product].[ExternalSystems] es ON es.[ExternalSystemId] = pis.[ExternalSystemId]
WHERE p.[PublicId] = :productPublicId
  AND pis.[DeletedAtUtc] IS NULL
  AND es.[DeletedAtUtc] IS NULL
ORDER BY es.[SystemCode]
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        return array_map(
            static fn (array $row): array => [
                'systemCode' => AzureSqlValueMapper::stringValue($row['SystemCode'] ?? ''),
                'syncStatusCode' => AzureSqlValueMapper::stringValue($row['SyncStatusCode'] ?? ''),
                'lastSyncedAtUtc' => AzureSqlValueMapper::nullableString($row['LastSyncedAtUtc'] ?? null),
                'lastErrorMessage' => AzureSqlValueMapper::nullableString($row['LastErrorMessage'] ?? null),
            ],
            $rows,
        );
    }
}
