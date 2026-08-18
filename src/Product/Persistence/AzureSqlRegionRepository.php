<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\Pagination;
use TriCab\Core\Product\Application\DTO\RegionListCriteria;
use TriCab\Core\Product\Application\DTO\RegionListResult;
use TriCab\Core\Product\Application\DTO\RegionRecord;
use TriCab\Core\Product\Application\DTO\RegionUsage;
use TriCab\Core\Product\Application\DTO\RegionWriteData;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\RegionNotFound;
use TriCab\Core\Product\Contract\RegionRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class AzureSqlRegionRepository implements RegionRepository
{
    private ?bool $hasCatalogueNodeRegions = null;

    private ?bool $hasProductDocumentRegionId = null;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listPublicActive(): array
    {
        return $this->load(
            'r.[DeletedAtUtc] IS NULL AND r.[IsActive] = 1 AND r.[WebsiteEnabled] = 1 AND r.[ShowInRegionPicker] = 1',
            [],
            'r.[DisplayOrder] ASC, r.[DisplayName] ASC',
        );
    }

    public function listAdmin(RegionListCriteria $criteria): RegionListResult
    {
        $where = $criteria->includeDeleted ? '1 = 1' : 'r.[DeletedAtUtc] IS NULL';
        $params = [];
        if ($criteria->normalizedSearch() !== null) {
            $searchTerm = '%' . $criteria->normalizedSearch() . '%';
            $where .= ' AND (r.[RegionCode] LIKE :searchCode OR r.[RegionName] LIKE :searchName OR r.[DisplayName] LIKE :searchDisplayName)';
            $params[':searchCode'] = $searchTerm;
            $params[':searchName'] = $searchTerm;
            $params[':searchDisplayName'] = $searchTerm;
        }
        foreach ([
            'isActive' => ['r.[IsActive]', $criteria->isActive],
            'isDefault' => ['r.[IsDefault]', $criteria->isDefault],
            'websiteEnabled' => ['r.[WebsiteEnabled]', $criteria->websiteEnabled],
            'showInPicker' => ['r.[ShowInRegionPicker]', $criteria->showInRegionPicker],
        ] as $key => [$column, $value]) {
            if ($value !== null) {
                $where .= ' AND ' . $column . ' = :' . $key;
                $params[':' . $key] = $value ? 1 : 0;
            }
        }
        $countRows = $this->connection->fetchAll(
            'SELECT COUNT_BIG(1) AS [Total] FROM [product].[Regions] r WHERE ' . $where,
            $params,
        );
        $total = AzureSqlValueMapper::intValue($countRows[0]['Total'] ?? 0);
        $sort = [
            'code' => 'r.[RegionCode]',
            'name' => 'r.[RegionName]',
            'displayName' => 'r.[DisplayName]',
            'displayOrder' => 'r.[DisplayOrder]',
            'isDefault' => 'r.[IsDefault]',
            'isActive' => 'r.[IsActive]',
            'updatedAt' => 'r.[UpdatedAtUtc]',
        ][$criteria->sortBy];
        $params[':offset'] = ($criteria->page - 1) * $criteria->pageSize;
        $params[':pageSize'] = $criteria->pageSize;
        $items = $this->load(
            $where,
            $params,
            $sort . ' ' . strtoupper($criteria->normalizedSortDirection()) . ', r.[RegionId] ASC',
            ' OFFSET :offset ROWS FETCH NEXT :pageSize ROWS ONLY',
        );

        return new RegionListResult(
            $items,
            Pagination::fromTotals($criteria->page, $criteria->pageSize, $total),
        );
    }

    public function findByPublicId(string $publicId, bool $includeDeleted = false): ?RegionRecord
    {
        $where = 'r.[PublicId] = :publicId';
        if (!$includeDeleted) {
            $where .= ' AND r.[DeletedAtUtc] IS NULL';
        }
        $items = $this->load($where, [':publicId' => $publicId], 'r.[RegionId]');
        return $items[0] ?? null;
    }

    public function codeExists(string $code, ?string $excludePublicId = null): bool
    {
        $sql = 'SELECT TOP (1) 1 AS [Found] FROM [product].[Regions]
            WHERE UPPER([RegionCode]) = UPPER(:code)';
        $params = [':code' => $code];
        if ($excludePublicId !== null) {
            $sql .= ' AND [PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }
        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function anotherDefaultExists(?string $excludePublicId = null): bool
    {
        return $this->flagExists('IsDefault', $excludePublicId);
    }

    public function anotherDefaultWebsiteRegionExists(?string $excludePublicId = null): bool
    {
        return $this->flagExists('IsDefaultWebsiteRegion', $excludePublicId);
    }

    public function create(RegionWriteData $data, UserId $actorUserId): RegionRecord
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[Regions] (
    [RegionCode], [RegionName], [DisplayName], [Flag], [ThemeColour], [DisplayOrder],
    [IsDefault], [IsActive], [WebsiteEnabled], [ShowInRegionPicker], [IsDefaultWebsiteRegion],
    [CountryCodes], [CurrencyCode], [TimezoneId], [LanguageCode], [Office], [Phone], [Email],
    [SalesContact], [CreatedByUserId], [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    :code, :name, :displayName, :flag, :themeColour, :displayOrder,
    :isDefault, :isActive, :websiteEnabled, :showInPicker, :defaultWebsiteRegion,
    :countryCodes, :currencyCode, :timezoneId, :languageCode, :office, :phone, :email,
    :salesContact, :createdByUserId, :updatedByUserId
)
SQL,
            $this->writeParams($data, $actorUserId),
        );
        $publicId = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
        return $this->require($publicId);
    }

    public function update(
        string $publicId,
        RegionWriteData $data,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): RegionRecord {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [product].[Regions] SET
    [RegionCode] = :code, [RegionName] = :name, [DisplayName] = :displayName,
    [Flag] = :flag, [ThemeColour] = :themeColour, [DisplayOrder] = :displayOrder,
    [IsDefault] = :isDefault, [IsActive] = :isActive, [WebsiteEnabled] = :websiteEnabled,
    [ShowInRegionPicker] = :showInPicker, [IsDefaultWebsiteRegion] = :defaultWebsiteRegion,
    [CountryCodes] = :countryCodes, [CurrencyCode] = :currencyCode, [TimezoneId] = :timezoneId,
    [LanguageCode] = :languageCode, [Office] = :office, [Phone] = :phone, [Email] = :email,
    [SalesContact] = :salesContact, [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :updatedByUserId
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL AND [RowVersion] = :expectedRowVersion
SQL,
            $this->writeParams($data, $actorUserId) + [
                ':publicId' => $publicId,
                ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
            ],
        );
        $this->assertMutation($publicId, $rows);
        return $this->require($publicId);
    }

    public function setActive(
        string $publicId,
        bool $isActive,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): RegionRecord {
        return $this->statusMutation($publicId, $expectedRowVersion, $actorUserId, false, $isActive);
    }

    public function softDelete(string $publicId, string $expectedRowVersion, UserId $actorUserId): RegionRecord
    {
        return $this->statusMutation($publicId, $expectedRowVersion, $actorUserId, true, false);
    }

    public function usage(string $publicId): RegionUsage
    {
        $catalogueExpression = $this->hasCatalogueNodeRegions()
            ? '(SELECT COUNT_BIG(1) FROM [product].[CatalogueNodeRegions] x WHERE x.[RegionId] = r.[RegionId])'
            : '0';
        $documentExpression = $this->hasProductDocumentRegionId()
            ? '(SELECT COUNT_BIG(1) FROM [product].[ProductDocuments] x WHERE x.[RegionId] = r.[RegionId] AND x.[DeletedAtUtc] IS NULL)'
            : '0';
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    (SELECT COUNT_BIG(1) FROM [product].[ProductRegions] x WHERE x.[RegionId] = r.[RegionId] AND x.[DeletedAtUtc] IS NULL) AS [ProductCount],
    (SELECT COUNT_BIG(1) FROM [product].[Markets] x WHERE x.[RegionId] = r.[RegionId] AND x.[DeletedAtUtc] IS NULL) AS [MarketCount],
    {$documentExpression} AS [DocumentCount],
    {$catalogueExpression} AS [CatalogueCount]
FROM [product].[Regions] r
WHERE r.[PublicId] = :publicId
SQL,
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            throw RegionNotFound::forPublicId($publicId);
        }
        return new RegionUsage(
            AzureSqlValueMapper::intValue($rows[0]['ProductCount'] ?? 0),
            AzureSqlValueMapper::intValue($rows[0]['MarketCount'] ?? 0),
            AzureSqlValueMapper::intValue($rows[0]['DocumentCount'] ?? 0),
            AzureSqlValueMapper::intValue($rows[0]['CatalogueCount'] ?? 0),
        );
    }

    /**
     * @param array<string, scalar|\TriCab\Api\Infrastructure\Database\SqlBinary|null> $params
     * @return list<RegionRecord>
     */
    private function load(string $where, array $params, string $order, string $paging = ''): array
    {
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
    CONVERT(NVARCHAR(36), r.[PublicId]) AS [PublicId], r.[RegionCode], r.[RegionName],
    r.[DisplayName], r.[Flag], r.[ThemeColour], r.[DisplayOrder], r.[IsDefault], r.[IsActive],
    r.[WebsiteEnabled], r.[ShowInRegionPicker], r.[IsDefaultWebsiteRegion], r.[CountryCodes],
    r.[CurrencyCode], r.[TimezoneId], r.[LanguageCode], r.[Office], r.[Phone], r.[Email],
    r.[SalesContact], r.[UpdatedAtUtc], r.[RowVersion], r.[DeletedAtUtc],
    (SELECT COUNT_BIG(1) FROM [product].[ProductRegions] pr
        WHERE pr.[RegionId] = r.[RegionId] AND pr.[DeletedAtUtc] IS NULL) AS [ProductUsageCount]
FROM [product].[Regions] r
WHERE {$where}
ORDER BY {$order}{$paging}
SQL,
            $params,
        );
        return array_map(fn (array $row): RegionRecord => $this->hydrate($row), $rows);
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): RegionRecord
    {
        $csv = AzureSqlValueMapper::nullableString($row['CountryCodes'] ?? null);
        $countries = $csv === null
            ? []
            : array_values(array_filter(array_map('trim', explode(',', $csv))));
        $updated = AzureSqlValueMapper::nullableDateTime($row['UpdatedAtUtc'] ?? null);
        return new RegionRecord(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            code: AzureSqlValueMapper::stringValue($row['RegionCode'] ?? ''),
            name: AzureSqlValueMapper::stringValue($row['RegionName'] ?? ''),
            displayName: AzureSqlValueMapper::stringValue($row['DisplayName'] ?? $row['RegionName'] ?? ''),
            flag: AzureSqlValueMapper::nullableString($row['Flag'] ?? null),
            themeColour: AzureSqlValueMapper::nullableString($row['ThemeColour'] ?? null),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            isDefault: AzureSqlValueMapper::boolValue($row['IsDefault'] ?? false),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? false),
            websiteEnabled: AzureSqlValueMapper::boolValue($row['WebsiteEnabled'] ?? false),
            showInRegionPicker: AzureSqlValueMapper::boolValue($row['ShowInRegionPicker'] ?? false),
            defaultWebsiteRegion: AzureSqlValueMapper::boolValue($row['IsDefaultWebsiteRegion'] ?? false),
            countryCodes: $countries,
            countryCount: count($countries),
            productUsageCount: AzureSqlValueMapper::intValue($row['ProductUsageCount'] ?? 0),
            updatedAtUtc: $updated?->format(DATE_ATOM),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? '')->toBase64(),
            isDeleted: ($row['DeletedAtUtc'] ?? null) !== null,
            currencyCode: AzureSqlValueMapper::nullableString($row['CurrencyCode'] ?? null),
            timezoneId: AzureSqlValueMapper::nullableString($row['TimezoneId'] ?? null),
            languageCode: AzureSqlValueMapper::nullableString($row['LanguageCode'] ?? null),
            office: AzureSqlValueMapper::nullableString($row['Office'] ?? null),
            phone: AzureSqlValueMapper::nullableString($row['Phone'] ?? null),
            email: AzureSqlValueMapper::nullableString($row['Email'] ?? null),
            salesContact: AzureSqlValueMapper::nullableString($row['SalesContact'] ?? null),
        );
    }

    /** @return array<string, scalar|null> */
    private function writeParams(RegionWriteData $data, UserId $actor): array
    {
        return [
            ':code' => $data->code, ':name' => $data->name, ':displayName' => $data->displayName,
            ':flag' => $data->flag, ':themeColour' => $data->themeColour, ':displayOrder' => $data->displayOrder,
            ':isDefault' => $data->isDefault ? 1 : 0, ':isActive' => $data->isActive ? 1 : 0,
            ':websiteEnabled' => $data->websiteEnabled ? 1 : 0,
            ':showInPicker' => $data->showInRegionPicker ? 1 : 0,
            ':defaultWebsiteRegion' => $data->defaultWebsiteRegion ? 1 : 0,
            ':countryCodes' => implode(',', $data->countryCodes), ':currencyCode' => $data->currencyCode,
            ':timezoneId' => $data->timezoneId, ':languageCode' => $data->languageCode,
            ':office' => $data->office, ':phone' => $data->phone, ':email' => $data->email,
            ':salesContact' => $data->salesContact,
            ':createdByUserId' => $actor->toInt(),
            ':updatedByUserId' => $actor->toInt(),
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function assertMutation(string $publicId, array $rows): void
    {
        if ($rows !== []) {
            return;
        }
        if ($this->findByPublicId($publicId, true) === null) {
            throw RegionNotFound::forPublicId($publicId);
        }
        throw ProductConcurrencyConflict::forPublicId($publicId);
    }

    private function statusMutation(
        string $publicId,
        string $expectedRowVersion,
        UserId $actor,
        bool $delete,
        bool $active,
    ): RegionRecord {
        $actorUserId = $actor->toInt();
        $deleteSet = $delete
            ? '[DeletedAtUtc] = SYSUTCDATETIME(), [DeletedByUserId] = :deletedByUserId,'
            : '';
        $params = [
            ':publicId' => $publicId,
            ':isActive' => $active ? 1 : 0,
            ':updatedByUserId' => $actorUserId,
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
        ];
        if ($delete) {
            $params[':deletedByUserId'] = $actorUserId;
        }
        $rows = $this->connection->fetchAll(
            "UPDATE [product].[Regions] SET {$deleteSet}
                [IsActive] = :isActive, [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :updatedByUserId
             OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
             WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL AND [RowVersion] = :expectedRowVersion",
            $params,
        );
        $this->assertMutation($publicId, $rows);
        return $this->require($publicId, $delete);
    }

    private function require(string $publicId, bool $includeDeleted = false): RegionRecord
    {
        $record = $this->findByPublicId($publicId, $includeDeleted);
        if ($record === null) {
            throw RegionNotFound::forPublicId($publicId);
        }
        return $record;
    }

    private function flagExists(string $column, ?string $exclude): bool
    {
        $sql = "SELECT TOP (1) 1 AS [Found] FROM [product].[Regions]
            WHERE [{$column}] = 1 AND [DeletedAtUtc] IS NULL";
        $params = [];
        if ($exclude !== null) {
            $sql .= ' AND [PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $exclude;
        }
        return $this->connection->fetchAll($sql, $params) !== [];
    }

    private function hasCatalogueNodeRegions(): bool
    {
        if ($this->hasCatalogueNodeRegions === null) {
            $rows = $this->connection->fetchAll(
                "SELECT CASE WHEN OBJECT_ID(N'[product].[CatalogueNodeRegions]', N'U') IS NULL THEN 0 ELSE 1 END AS [ExistsFlag]",
            );
            $this->hasCatalogueNodeRegions = AzureSqlValueMapper::boolValue($rows[0]['ExistsFlag'] ?? 0);
        }
        return $this->hasCatalogueNodeRegions;
    }

    private function hasProductDocumentRegionId(): bool
    {
        if ($this->hasProductDocumentRegionId === null) {
            $rows = $this->connection->fetchAll(
                "SELECT CASE WHEN COL_LENGTH(N'product.ProductDocuments', N'RegionId') IS NULL THEN 0 ELSE 1 END AS [ExistsFlag]",
            );
            $this->hasProductDocumentRegionId = AzureSqlValueMapper::boolValue($rows[0]['ExistsFlag'] ?? 0);
        }

        return $this->hasProductDocumentRegionId;
    }
}
