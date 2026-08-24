<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Api\Product\Persistence\Support\AzureSqlValueMapper;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\VariantLegacyPricingIdentityMapping;
use TriCab\Api\Product\Persistence\ProductPersistenceException;
use TriCab\Core\Product\Contract\VariantLegacyPricingIdentityRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantId;
use TriCab\Core\Product\Domain\ValueObject\ProductVariantPublicId;
use TriCab\Core\Product\Domain\ValueObject\VariantPricingLegacyContext;

final class AzureSqlVariantLegacyPricingIdentityRepository implements VariantLegacyPricingIdentityRepository
{
    public function __construct(private readonly SqlConnection $connection)
    {
    }

    public function findByVariantPublicId(ProductVariantPublicId $publicId): ?VariantLegacyPricingIdentityMapping
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), eem.[PublicId]) AS [MappingPublicId],
    CONVERT(NVARCHAR(36), pv.[PublicId]) AS [VariantPublicId],
    eem.[ExternalEntityKey] AS [LegacyPriceCode],
    eem.[IsActive] AS [IsActive]
FROM [product].[ProductVariants] pv
INNER JOIN [product].[ExternalEntityMappings] eem
    ON eem.[InternalEntityId] = pv.[ProductVariantId]
   AND eem.[EntityTypeCode] = :entityTypeCode
   AND eem.[DeletedAtUtc] IS NULL
INNER JOIN [product].[ExternalSystems] es
    ON es.[ExternalSystemId] = eem.[ExternalSystemId]
   AND es.[SystemCode] = :systemCode
   AND es.[DeletedAtUtc] IS NULL
WHERE pv.[PublicId] = :variantPublicId
  AND pv.[DeletedAtUtc] IS NULL
SQL,
            [
                ':entityTypeCode' => VariantPricingLegacyContext::ENTITY_TYPE,
                ':systemCode' => VariantPricingLegacyContext::SYSTEM_CODE,
                ':variantPublicId' => $publicId->toString(),
            ],
        );

        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    public function findByVariantId(ProductVariantId $variantId): ?VariantLegacyPricingIdentityMapping
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), eem.[PublicId]) AS [MappingPublicId],
    CONVERT(NVARCHAR(36), pv.[PublicId]) AS [VariantPublicId],
    eem.[ExternalEntityKey] AS [LegacyPriceCode],
    eem.[IsActive] AS [IsActive]
FROM [product].[ProductVariants] pv
INNER JOIN [product].[ExternalEntityMappings] eem
    ON eem.[InternalEntityId] = pv.[ProductVariantId]
   AND eem.[EntityTypeCode] = :entityTypeCode
   AND eem.[DeletedAtUtc] IS NULL
INNER JOIN [product].[ExternalSystems] es
    ON es.[ExternalSystemId] = eem.[ExternalSystemId]
   AND es.[SystemCode] = :systemCode
   AND es.[DeletedAtUtc] IS NULL
WHERE pv.[ProductVariantId] = :variantId
  AND pv.[DeletedAtUtc] IS NULL
SQL,
            [
                ':entityTypeCode' => VariantPricingLegacyContext::ENTITY_TYPE,
                ':systemCode' => VariantPricingLegacyContext::SYSTEM_CODE,
                ':variantId' => $variantId->toInt(),
            ],
        );

        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    public function findByVariantIds(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $placeholders = [];
        $params = [
            ':entityTypeCode' => VariantPricingLegacyContext::ENTITY_TYPE,
            ':systemCode' => VariantPricingLegacyContext::SYSTEM_CODE,
        ];
        foreach ($variantIds as $index => $variantId) {
            $key = ':variantId' . $index;
            $placeholders[] = $key;
            $params[$key] = $variantId;
        }

        $sql = <<<'SQL'
SELECT
    pv.[ProductVariantId] AS [VariantId],
    CONVERT(NVARCHAR(36), eem.[PublicId]) AS [MappingPublicId],
    CONVERT(NVARCHAR(36), pv.[PublicId]) AS [VariantPublicId],
    eem.[ExternalEntityKey] AS [LegacyPriceCode],
    eem.[IsActive] AS [IsActive]
FROM [product].[ProductVariants] pv
INNER JOIN [product].[ExternalEntityMappings] eem
    ON eem.[InternalEntityId] = pv.[ProductVariantId]
   AND eem.[EntityTypeCode] = :entityTypeCode
   AND eem.[DeletedAtUtc] IS NULL
INNER JOIN [product].[ExternalSystems] es
    ON es.[ExternalSystemId] = eem.[ExternalSystemId]
   AND es.[SystemCode] = :systemCode
   AND es.[DeletedAtUtc] IS NULL
WHERE pv.[ProductVariantId] IN (
SQL;
        $sql .= implode(', ', $placeholders) . ') AND pv.[DeletedAtUtc] IS NULL';

        $rows = $this->connection->fetchAll($sql, $params);
        $out = [];
        foreach ($rows as $row) {
            $mapping = $this->hydrate($row);
            $out[AzureSqlValueMapper::intValue($row['VariantId'] ?? 0)] = $mapping;
        }

        return $out;
    }

    public function upsert(
        ProductVariantId $variantId,
        ProductVariantPublicId $variantPublicId,
        string $legacyPriceCode,
        UserId $actorUserId,
    ): VariantLegacyPricingIdentityMapping {
        $systemId = $this->requireExternalSystemId(VariantPricingLegacyContext::SYSTEM_CODE);
        $existing = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP (1) [ExternalEntityMappingId]
FROM [product].[ExternalEntityMappings]
WHERE [ExternalSystemId] = :systemId
  AND [EntityTypeCode] = :entityTypeCode
  AND [InternalEntityId] = :variantId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':systemId' => $systemId,
                ':entityTypeCode' => VariantPricingLegacyContext::ENTITY_TYPE,
                ':variantId' => $variantId->toInt(),
            ],
        );

        $normalizedCode = strtoupper(trim($legacyPriceCode));
        if ($existing !== []) {
            $mappingId = AzureSqlValueMapper::intValue($existing[0]['ExternalEntityMappingId'] ?? 0);
            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[ExternalEntityMappings]
SET [ExternalEntityKey] = :externalKey,
    [IsActive] = 1,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actorUserId
WHERE [ExternalEntityMappingId] = :mappingId
SQL,
                [
                    ':externalKey' => $normalizedCode,
                    ':actorUserId' => $actorUserId->toInt(),
                    ':mappingId' => $mappingId,
                ],
            );
        } else {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [product].[ExternalEntityMappings] (
    [ExternalSystemId],
    [EntityTypeCode],
    [InternalEntityId],
    [ExternalEntityKey],
    [CreatedByUserId],
    [UpdatedByUserId]
)
VALUES (
    :systemId,
    :entityTypeCode,
    :variantId,
    :externalKey,
    :actorUserId,
    :actorUserId
)
SQL,
                [
                    ':systemId' => $systemId,
                    ':entityTypeCode' => VariantPricingLegacyContext::ENTITY_TYPE,
                    ':variantId' => $variantId->toInt(),
                    ':externalKey' => $normalizedCode,
                    ':actorUserId' => $actorUserId->toInt(),
                ],
            );
        }

        return $this->findByVariantPublicId($variantPublicId)
            ?? throw new \RuntimeException('Failed to load saved variant pricing identity mapping.');
    }

    public function deactivate(ProductVariantId $variantId, UserId $actorUserId): void
    {
        $this->connection->execute(
            <<<'SQL'
UPDATE eem
SET eem.[DeletedAtUtc] = SYSUTCDATETIME(),
    eem.[DeletedByUserId] = :actorUserId,
    eem.[IsActive] = 0,
    eem.[UpdatedAtUtc] = SYSUTCDATETIME(),
    eem.[UpdatedByUserId] = :actorUserId
FROM [product].[ExternalEntityMappings] eem
INNER JOIN [product].[ExternalSystems] es
    ON es.[ExternalSystemId] = eem.[ExternalSystemId]
   AND es.[SystemCode] = :systemCode
WHERE eem.[InternalEntityId] = :variantId
  AND eem.[EntityTypeCode] = :entityTypeCode
  AND eem.[DeletedAtUtc] IS NULL
SQL,
            [
                ':actorUserId' => $actorUserId->toInt(),
                ':systemCode' => VariantPricingLegacyContext::SYSTEM_CODE,
                ':variantId' => $variantId->toInt(),
                ':entityTypeCode' => VariantPricingLegacyContext::ENTITY_TYPE,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): VariantLegacyPricingIdentityMapping
    {
        return new VariantLegacyPricingIdentityMapping(
            publicId: (string) ($row['MappingPublicId'] ?? ''),
            variantPublicId: (string) ($row['VariantPublicId'] ?? ''),
            legacyPriceCode: (string) ($row['LegacyPriceCode'] ?? ''),
            isActive: (bool) ($row['IsActive'] ?? true),
        );
    }

    private function requireExternalSystemId(string $systemCode): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ExternalSystemId] FROM [product].[ExternalSystems] WHERE [SystemCode] = :code AND [DeletedAtUtc] IS NULL',
            [':code' => $systemCode],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('External system', $systemCode);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ExternalSystemId'] ?? 0);
    }
}
