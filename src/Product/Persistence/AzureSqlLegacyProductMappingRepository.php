<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Product\Application\DTO\LegacyProductMappingDetails;
use TriCab\Core\Product\Contract\LegacyProductMappingRepository;

/**
 * Azure SQL repository for legacy product migration mappings.
 * Table: migration.LegacyProductMappings.
 */
final class AzureSqlLegacyProductMappingRepository implements LegacyProductMappingRepository
{
    private const SELECT_SQL = <<<'SQL'
SELECT
    m.[LegacyProductMappingId],
    m.[SourceSystemCode],
    m.[SourceProductKey],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [ProductVariantPublicId],
    m.[MigrationStatusCode],
    m.[MigrationNotes],
    CONVERT(VARCHAR(33), m.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(VARCHAR(33), m.[UpdatedAtUtc], 127) AS [UpdatedAtUtc],
    CONVERT(VARCHAR(33), m.[MigratedAtUtc], 127) AS [MigratedAtUtc]
FROM [migration].[LegacyProductMappings] m
LEFT JOIN [product].[Products] p ON p.[ProductId] = m.[ProductId]
LEFT JOIN [product].[ProductVariants] v ON v.[ProductVariantId] = m.[ProductVariantId]
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function findBySource(string $sourceSystemCode, string $sourceProductKey): ?LegacyProductMappingDetails
    {
        $rows = $this->connection->fetchAll(
            self::SELECT_SQL . ' WHERE m.[SourceSystemCode] = :sourceSystemCode AND m.[SourceProductKey] = :sourceProductKey',
            [
                ':sourceSystemCode' => $sourceSystemCode,
                ':sourceProductKey' => $sourceProductKey,
            ],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function upsert(array $data): LegacyProductMappingDetails
    {
        $productId = null;
        if (($data['productPublicId'] ?? null) !== null) {
            $productId = $this->requireProductId($data['productPublicId']);
        }

        $variantId = null;
        if (($data['productVariantPublicId'] ?? null) !== null) {
            $variantId = $this->requireVariantId($data['productVariantPublicId']);
        }

        $existing = $this->findBySource($data['sourceSystemCode'], $data['sourceProductKey']);

        if ($existing === null) {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [migration].[LegacyProductMappings] (
    [SourceSystemCode],
    [SourceProductKey],
    [ProductId],
    [ProductVariantId],
    [MigrationStatusCode],
    [MigrationNotes],
    [UpdatedAtUtc]
)
VALUES (
    :sourceSystemCode,
    :sourceProductKey,
    :productId,
    :productVariantId,
    :migrationStatusCode,
    :migrationNotes,
    SYSUTCDATETIME()
)
SQL,
                [
                    ':sourceSystemCode' => $data['sourceSystemCode'],
                    ':sourceProductKey' => $data['sourceProductKey'],
                    ':productId' => $productId,
                    ':productVariantId' => $variantId,
                    ':migrationStatusCode' => $data['migrationStatusCode'],
                    ':migrationNotes' => $data['migrationNotes'] ?? null,
                ],
            );
        } else {
            $this->connection->execute(
                <<<'SQL'
UPDATE [migration].[LegacyProductMappings]
SET
    [ProductId] = :productId,
    [ProductVariantId] = :productVariantId,
    [MigrationStatusCode] = :migrationStatusCode,
    [MigrationNotes] = :migrationNotes,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [MigratedAtUtc] = CASE
        WHEN :migrationStatusCode IN (N'imported', N'updated') THEN SYSUTCDATETIME()
        ELSE [MigratedAtUtc]
    END
WHERE [SourceSystemCode] = :sourceSystemCode
  AND [SourceProductKey] = :sourceProductKey
SQL,
                [
                    ':sourceSystemCode' => $data['sourceSystemCode'],
                    ':sourceProductKey' => $data['sourceProductKey'],
                    ':productId' => $productId,
                    ':productVariantId' => $variantId,
                    ':migrationStatusCode' => $data['migrationStatusCode'],
                    ':migrationNotes' => $data['migrationNotes'] ?? null,
                ],
            );
        }

        $loaded = $this->findBySource($data['sourceSystemCode'], $data['sourceProductKey']);
        if ($loaded === null) {
            throw ProductPersistenceException::operationFailed('Legacy product mapping upsert did not persist.');
        }

        return $loaded;
    }

    private function requireProductId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Product', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductId'] ?? 0);
    }

    private function requireVariantId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductVariantId] FROM [product].[ProductVariants] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Product variant', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductVariantId'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): LegacyProductMappingDetails
    {
        return new LegacyProductMappingDetails(
            legacyProductMappingId: AzureSqlValueMapper::intValue($row['LegacyProductMappingId'] ?? 0),
            sourceSystemCode: AzureSqlValueMapper::stringValue($row['SourceSystemCode'] ?? ''),
            sourceProductKey: AzureSqlValueMapper::stringValue($row['SourceProductKey'] ?? ''),
            productPublicId: AzureSqlValueMapper::nullableString($row['ProductPublicId'] ?? null),
            productVariantPublicId: AzureSqlValueMapper::nullableString($row['ProductVariantPublicId'] ?? null),
            migrationStatusCode: AzureSqlValueMapper::stringValue($row['MigrationStatusCode'] ?? ''),
            migrationNotes: AzureSqlValueMapper::nullableString($row['MigrationNotes'] ?? null),
            createdAtUtc: AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
            updatedAtUtc: AzureSqlValueMapper::stringValue($row['UpdatedAtUtc'] ?? ''),
            migratedAtUtc: AzureSqlValueMapper::nullableString($row['MigratedAtUtc'] ?? null),
        );
    }
}
