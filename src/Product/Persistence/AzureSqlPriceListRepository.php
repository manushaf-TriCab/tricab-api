<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\PriceListLookupItem;
use TriCab\Core\Product\Application\DTO\PriceListRecord;
use TriCab\Core\Product\Application\DTO\PriceListUsage;
use TriCab\Core\Product\Application\Exception\PriceListConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\PriceListNotFound;
use TriCab\Core\Product\Contract\PriceListRepository;
use TriCab\Core\Product\Domain\ValueObject\PriceListLegacyContext;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class AzureSqlPriceListRepository implements PriceListRepository
{
    private const SELECT_BASE = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), pl.[PublicId]) AS [PublicId],
    pl.[PriceListCode] AS [Code],
    pl.[PriceListName] AS [Name],
    cur.[CurrencyCode] AS [CurrencyCode],
    CONVERT(NVARCHAR(36), cur.[PublicId]) AS [CurrencyPublicId],
    CONVERT(NVARCHAR(36), reg.[PublicId]) AS [RegionPublicId],
    reg.[RegionCode] AS [RegionCode],
    reg.[RegionName] AS [RegionName],
    CONVERT(NVARCHAR(36), mkt.[PublicId]) AS [MarketPublicId],
    mkt.[MarketCode] AS [MarketCode],
    mkt.[MarketName] AS [MarketName],
    es.[SystemCode] AS [LegacySourceCode],
    eem.[EntityTypeCode] AS [LegacyContextType],
    eem.[ExternalEntityKey] AS [LegacyContextCode],
    CONVERT(NVARCHAR(30), pl.[EffectiveFromUtc], 127) AS [EffectiveFromUtc],
    CONVERT(NVARCHAR(30), pl.[EffectiveToUtc], 127) AS [EffectiveToUtc],
    pl.[IsActive] AS [IsActive],
    CASE WHEN pl.[DeletedAtUtc] IS NULL THEN 0 ELSE 1 END AS [IsDeleted],
    pl.[RowVersion] AS [RowVersion],
    CONVERT(NVARCHAR(30), pl.[CreatedAtUtc], 127) AS [CreatedAtUtc],
    CONVERT(NVARCHAR(30), pl.[UpdatedAtUtc], 127) AS [UpdatedAtUtc]
FROM [product].[PriceLists] pl
INNER JOIN [product].[Currencies] cur ON cur.[CurrencyId] = pl.[CurrencyId]
LEFT JOIN [product].[Regions] reg ON reg.[RegionId] = pl.[RegionId]
LEFT JOIN [product].[Markets] mkt ON mkt.[MarketId] = pl.[MarketId]
LEFT JOIN [product].[ExternalEntityMappings] eem
    ON eem.[InternalEntityId] = pl.[PriceListId]
   AND eem.[EntityTypeCode] = :entityTypeCode
   AND eem.[DeletedAtUtc] IS NULL
   AND eem.[IsActive] = 1
LEFT JOIN [product].[ExternalSystems] es
    ON es.[ExternalSystemId] = eem.[ExternalSystemId]
   AND es.[DeletedAtUtc] IS NULL
SQL;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listAdmin(array $filters = []): array
    {
        $sql = self::SELECT_BASE . ' WHERE 1 = 1';
        $params = [':entityTypeCode' => PriceListLegacyContext::CONTEXT_TYPE];

        if (($filters['includeDeleted'] ?? false) !== true) {
            $sql .= ' AND pl.[DeletedAtUtc] IS NULL';
        }
        if (array_key_exists('activeOnly', $filters) && $filters['activeOnly'] !== null) {
            $sql .= ' AND pl.[IsActive] = :isActive';
            $params[':isActive'] = $filters['activeOnly'] ? 1 : 0;
        }
        if (isset($filters['search']) && is_string($filters['search']) && trim($filters['search']) !== '') {
            $sql .= ' AND (pl.[PriceListName] LIKE :search OR pl.[PriceListCode] LIKE :search OR eem.[ExternalEntityKey] LIKE :search)';
            $params[':search'] = '%' . trim($filters['search']) . '%';
        }
        $sql .= ' ORDER BY pl.[PriceListName]';

        return array_map(
            fn (array $row): PriceListRecord => $this->hydrate($row),
            $this->connection->fetchAll($sql, $params),
        );
    }

    public function getByPublicId(string $publicId, bool $includeDeleted = false): ?PriceListRecord
    {
        $sql = self::SELECT_BASE . ' WHERE pl.[PublicId] = :publicId';
        if (!$includeDeleted) {
            $sql .= ' AND pl.[DeletedAtUtc] IS NULL';
        }

        $rows = $this->connection->fetchAll($sql, [
            ':publicId' => $publicId,
            ':entityTypeCode' => PriceListLegacyContext::CONTEXT_TYPE,
        ]);

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    public function resolveByLegacyContext(string $sourceCode, string $contextType, string $contextCode): ?PriceListRecord
    {
        $sql = self::SELECT_BASE . <<<'SQL'
 WHERE pl.[DeletedAtUtc] IS NULL
   AND pl.[IsActive] = 1
   AND es.[SystemCode] = :sourceCode
   AND eem.[EntityTypeCode] = :contextType
   AND eem.[ExternalEntityKey] = :contextCode
   AND eem.[IsActive] = 1
SQL;

        $rows = $this->connection->fetchAll($sql, [
            ':entityTypeCode' => PriceListLegacyContext::CONTEXT_TYPE,
            ':sourceCode' => $sourceCode,
            ':contextType' => $contextType,
            ':contextCode' => $contextCode,
        ]);

        if ($rows === []) {
            return null;
        }

        return $this->hydrate($rows[0]);
    }

    public function listActiveLookups(): array
    {
        $sql = self::SELECT_BASE . <<<'SQL'
 WHERE pl.[DeletedAtUtc] IS NULL
   AND pl.[IsActive] = 1
 ORDER BY pl.[PriceListName]
SQL;

        return array_map(
            fn (array $row): PriceListLookupItem => new PriceListLookupItem(
                publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
                name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
                currencyCode: AzureSqlValueMapper::stringValue($row['CurrencyCode'] ?? ''),
                regionName: AzureSqlValueMapper::nullableString($row['RegionName'] ?? null),
                legacySourceCode: AzureSqlValueMapper::nullableString($row['LegacySourceCode'] ?? null),
                legacyContextCode: AzureSqlValueMapper::nullableString($row['LegacyContextCode'] ?? null),
                isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            ),
            $this->connection->fetchAll($sql, [':entityTypeCode' => PriceListLegacyContext::CONTEXT_TYPE]),
        );
    }

    public function getUsage(string $publicId): PriceListUsage
    {
        $priceListId = $this->requirePriceListId($publicId);
        $productPrices = $this->countWhere('[product].[ProductPrices]', 'PriceListId', $priceListId);
        $variantPrices = $this->countWhere('[product].[ProductVariantPrices]', 'PriceListId', $priceListId);
        $priceListCustomers = $this->countWhere('[product].[PriceListCustomers]', 'PriceListId', $priceListId);
        $total = $productPrices + $variantPrices + $priceListCustomers;

        return new PriceListUsage(
            productPriceCount: $productPrices,
            variantPriceCount: $variantPrices,
            customerPriceCount: 0,
            priceListCustomerCount: $priceListCustomers,
            summary: sprintf(
                '%d product, %d variant, %d customer assignment(s)',
                $productPrices,
                $variantPrices,
                $priceListCustomers,
            ),
        );
    }

    public function codeExists(string $code, ?string $excludePublicId = null): bool
    {
        $sql = 'SELECT TOP (1) 1 AS [Hit] FROM [product].[PriceLists] WHERE [PriceListCode] = :code AND [DeletedAtUtc] IS NULL';
        $params = [':code' => $code];
        if ($excludePublicId !== null) {
            $sql .= ' AND [PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function legacyContextExists(
        string $sourceCode,
        string $contextType,
        string $contextCode,
        ?string $excludePublicId = null,
    ): bool {
        $sql = <<<'SQL'
SELECT TOP (1) 1 AS [Hit]
FROM [product].[ExternalEntityMappings] eem
INNER JOIN [product].[ExternalSystems] es ON es.[ExternalSystemId] = eem.[ExternalSystemId]
INNER JOIN [product].[PriceLists] pl ON pl.[PriceListId] = eem.[InternalEntityId]
WHERE eem.[DeletedAtUtc] IS NULL
  AND eem.[IsActive] = 1
  AND es.[SystemCode] = :sourceCode
  AND eem.[EntityTypeCode] = :contextType
  AND eem.[ExternalEntityKey] = :contextCode
SQL;
        $params = [
            ':sourceCode' => $sourceCode,
            ':contextType' => $contextType,
            ':contextCode' => $contextCode,
        ];
        if ($excludePublicId !== null) {
            $sql .= ' AND pl.[PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function create(array $data, UserId $actorUserId): PriceListRecord
    {
        $currencyId = $this->requireCurrencyIdByPublicId((string) $data['currencyPublicId']);
        $regionId = isset($data['regionPublicId']) && $data['regionPublicId'] !== null
            ? $this->requireRegionIdByPublicId((string) $data['regionPublicId'])
            : null;
        $marketId = isset($data['marketPublicId']) && $data['marketPublicId'] !== null
            ? $this->requireMarketIdByPublicId((string) $data['marketPublicId'])
            : null;

        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[PriceLists] (
    [PriceListCode],
    [PriceListName],
    [CurrencyId],
    [RegionId],
    [MarketId],
    [EffectiveFromUtc],
    [EffectiveToUtc],
    [IsActive],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :code,
    :name,
    :currencyId,
    :regionId,
    :marketId,
    :effectiveFromUtc,
    :effectiveToUtc,
    :isActive,
    :createdByUserId,
    :updatedByUserId
)
SQL,
            [
                ':code' => $data['code'],
                ':name' => $data['name'],
                ':currencyId' => $currencyId,
                ':regionId' => $regionId,
                ':marketId' => $marketId,
                ':effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
                ':effectiveToUtc' => $data['effectiveToUtc'] ?? null,
                ':isActive' => ($data['isActive'] ?? true) ? 1 : 0,
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );

        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('PriceList');
        }

        $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
        $priceListId = $this->requirePriceListId($publicId);
        $this->syncLegacyMapping($priceListId, $data, $actorUserId);

        $record = $this->getByPublicId($publicId);
        if ($record === null) {
            throw ProductPersistenceException::insertFailed('PriceList');
        }

        return $record;
    }

    public function update(string $publicId, array $data, UserId $actorUserId): PriceListRecord
    {
        $priceListId = $this->requirePriceListId($publicId);
        $currencyId = $this->requireCurrencyIdByPublicId((string) $data['currencyPublicId']);
        $regionId = isset($data['regionPublicId']) && $data['regionPublicId'] !== null
            ? $this->requireRegionIdByPublicId((string) $data['regionPublicId'])
            : null;
        $marketId = isset($data['marketPublicId']) && $data['marketPublicId'] !== null
            ? $this->requireMarketIdByPublicId((string) $data['marketPublicId'])
            : null;

        $expected = isset($data['expectedRowVersion']) && is_string($data['expectedRowVersion'])
            ? RowVersionToken::fromBase64($data['expectedRowVersion'])
            : null;
        if ($expected === null) {
            throw new \InvalidArgumentException('expectedRowVersion is required for update.');
        }

        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [product].[PriceLists]
SET
    [PriceListCode] = :code,
    [PriceListName] = :name,
    [CurrencyId] = :currencyId,
    [RegionId] = :regionId,
    [MarketId] = :marketId,
    [EffectiveFromUtc] = :effectiveFromUtc,
    [EffectiveToUtc] = :effectiveToUtc,
    [IsActive] = :isActive,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId
  AND [DeletedAtUtc] IS NULL
  AND [RowVersion] = :expectedRowVersion
SQL,
            [
                ':code' => $data['code'],
                ':name' => $data['name'],
                ':currencyId' => $currencyId,
                ':regionId' => $regionId,
                ':marketId' => $marketId,
                ':effectiveFromUtc' => $data['effectiveFromUtc'] ?? null,
                ':effectiveToUtc' => $data['effectiveToUtc'] ?? null,
                ':isActive' => ($data['isActive'] ?? true) ? 1 : 0,
                ':updatedByUserId' => $actorUserId->toInt(),
                ':publicId' => $publicId,
                ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expected),
            ],
        );

        if ($rows === []) {
            if ($this->getByPublicId($publicId, true) === null) {
                throw PriceListNotFound::forPublicId($publicId);
            }
            throw PriceListConcurrencyConflict::forPublicId($publicId);
        }

        $this->syncLegacyMapping($priceListId, $data, $actorUserId);
        $record = $this->getByPublicId($publicId);
        if ($record === null) {
            throw PriceListNotFound::forPublicId($publicId);
        }

        return $record;
    }

    public function setActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): PriceListRecord
    {
        return $this->mutateStatus($publicId, $expectedRowVersion, $actorUserId, [
            '[IsActive] = :isActive',
        ], [':isActive' => $isActive ? 1 : 0]);
    }

    public function archive(string $publicId, string $expectedRowVersion, UserId $actorUserId): PriceListRecord
    {
        return $this->mutateStatus($publicId, $expectedRowVersion, $actorUserId, [
            '[DeletedAtUtc] = SYSUTCDATETIME()',
            '[DeletedByUserId] = :updatedByUserId',
            '[IsActive] = 0',
        ]);
    }

    public function restore(string $publicId, string $expectedRowVersion, UserId $actorUserId): PriceListRecord
    {
        return $this->mutateStatus($publicId, $expectedRowVersion, $actorUserId, [
            '[DeletedAtUtc] = NULL',
            '[DeletedByUserId] = NULL',
            '[IsActive] = 1',
        ]);
    }

    /**
     * @param list<string> $sets
     * @param array<string, mixed> $extraParams
     */
    private function mutateStatus(
        string $publicId,
        string $expectedRowVersion,
        UserId $actorUserId,
        array $sets,
        array $extraParams = [],
    ): PriceListRecord {
        $expected = RowVersionToken::fromBase64($expectedRowVersion);
        $params = array_merge($extraParams, [
            ':publicId' => $publicId,
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary($expected),
        ]);

        $sql = sprintf(
            'UPDATE [product].[PriceLists] SET %s, [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :updatedByUserId
             OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
             WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion',
            implode(', ', $sets),
        );

        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            if ($this->getByPublicId($publicId, true) === null) {
                throw PriceListNotFound::forPublicId($publicId);
            }
            throw PriceListConcurrencyConflict::forPublicId($publicId);
        }

        $record = $this->getByPublicId($publicId, true);
        if ($record === null) {
            throw PriceListNotFound::forPublicId($publicId);
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function syncLegacyMapping(int $priceListId, array $data, UserId $actorUserId): void
    {
        $source = $data['legacySourceCode'] ?? null;
        $type = $data['legacyContextType'] ?? null;
        $code = $data['legacyContextCode'] ?? null;

        if ($source === null || $type === null || $code === null) {
            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[ExternalEntityMappings]
SET [DeletedAtUtc] = SYSUTCDATETIME(),
    [DeletedByUserId] = :actorUserId,
    [IsActive] = 0,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actorUserId
WHERE [InternalEntityId] = :priceListId
  AND [EntityTypeCode] = :entityTypeCode
  AND [DeletedAtUtc] IS NULL
SQL,
                [
                    ':actorUserId' => $actorUserId->toInt(),
                    ':priceListId' => $priceListId,
                    ':entityTypeCode' => PriceListLegacyContext::CONTEXT_TYPE,
                ],
            );

            return;
        }

        $systemId = $this->requireExternalSystemId((string) $source);
        $existing = $this->connection->fetchAll(
            <<<'SQL'
SELECT TOP (1) [ExternalEntityMappingId]
FROM [product].[ExternalEntityMappings]
WHERE [ExternalSystemId] = :systemId
  AND [EntityTypeCode] = :entityTypeCode
  AND [InternalEntityId] = :priceListId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':systemId' => $systemId,
                ':entityTypeCode' => (string) $type,
                ':priceListId' => $priceListId,
            ],
        );

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
                    ':externalKey' => (string) $code,
                    ':actorUserId' => $actorUserId->toInt(),
                    ':mappingId' => $mappingId,
                ],
            );

            return;
        }

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
    :priceListId,
    :externalKey,
    :actorUserId,
    :actorUserId
)
SQL,
            [
                ':systemId' => $systemId,
                ':entityTypeCode' => (string) $type,
                ':priceListId' => $priceListId,
                ':externalKey' => (string) $code,
                ':actorUserId' => $actorUserId->toInt(),
            ],
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

    private function requirePriceListId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [PriceListId] FROM [product].[PriceLists] WHERE [PublicId] = :publicId',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw PriceListNotFound::forPublicId($publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['PriceListId'] ?? 0);
    }

    private function requireCurrencyIdByPublicId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [CurrencyId] FROM [product].[Currencies] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Currency', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['CurrencyId'] ?? 0);
    }

    private function requireRegionIdByPublicId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [RegionId] FROM [product].[Regions] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Region', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['RegionId'] ?? 0);
    }

    private function requireMarketIdByPublicId(string $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [MarketId] FROM [product].[Markets] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::referenceNotFound('Market', $publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['MarketId'] ?? 0);
    }

    private function countWhere(string $table, string $column, int $priceListId): int
    {
        $sql = sprintf(
            'SELECT COUNT(*) AS [Cnt] FROM %s WHERE [%s] = :priceListId AND [DeletedAtUtc] IS NULL',
            $table,
            $column,
        );
        $rows = $this->connection->fetchAll($sql, [':priceListId' => $priceListId]);

        return AzureSqlValueMapper::intValue($rows[0]['Cnt'] ?? 0);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PriceListRecord
    {
        return new PriceListRecord(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            code: AzureSqlValueMapper::stringValue($row['Code'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            currencyCode: AzureSqlValueMapper::stringValue($row['CurrencyCode'] ?? ''),
            currencyPublicId: AzureSqlValueMapper::nullableString($row['CurrencyPublicId'] ?? null),
            regionPublicId: AzureSqlValueMapper::nullableString($row['RegionPublicId'] ?? null),
            regionCode: AzureSqlValueMapper::nullableString($row['RegionCode'] ?? null),
            regionName: AzureSqlValueMapper::nullableString($row['RegionName'] ?? null),
            marketPublicId: AzureSqlValueMapper::nullableString($row['MarketPublicId'] ?? null),
            marketCode: AzureSqlValueMapper::nullableString($row['MarketCode'] ?? null),
            marketName: AzureSqlValueMapper::nullableString($row['MarketName'] ?? null),
            legacySourceCode: AzureSqlValueMapper::nullableString($row['LegacySourceCode'] ?? null),
            legacyContextType: AzureSqlValueMapper::nullableString($row['LegacyContextType'] ?? null),
            legacyContextCode: AzureSqlValueMapper::nullableString($row['LegacyContextCode'] ?? null),
            effectiveFromUtc: AzureSqlValueMapper::nullableString($row['EffectiveFromUtc'] ?? null),
            effectiveToUtc: AzureSqlValueMapper::nullableString($row['EffectiveToUtc'] ?? null),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            isDeleted: AzureSqlValueMapper::boolValue($row['IsDeleted'] ?? false),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? '')->toBase64(),
            createdAtUtc: AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null),
        );
    }
}
