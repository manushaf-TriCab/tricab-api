<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\ProductStyleAssignment;
use TriCab\Core\Product\Application\DTO\ProductStyleInheritanceConfig;
use TriCab\Core\Product\Application\DTO\ProductStyleRecord;
use TriCab\Core\Product\Application\DTO\ProductStyleUsage;
use TriCab\Core\Product\Application\Exception\InvalidProductStyleHierarchy;
use TriCab\Core\Product\Application\Exception\ProductStyleConcurrencyConflict;
use TriCab\Core\Product\Application\Exception\ProductStyleNotFound;
use TriCab\Core\Product\Contract\ProductStyleRepository;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;
use TriCab\Core\Product\Domain\ValueObject\StyleResourceType;

final class AzureSqlProductStyleRepository implements ProductStyleRepository
{
    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    public function listAll(bool $includeDeleted = false): array
    {
        return $this->hydrateMany($this->connection->fetchAll($this->selectSql($includeDeleted) . ' ORDER BY s.[SortOrder], s.[StyleCode]'));
    }

    public function findByPublicId(string $publicId, bool $includeDeleted = false): ?ProductStyleRecord
    {
        $rows = $this->connection->fetchAll(
            $this->selectSql($includeDeleted) . ' AND s.[PublicId] = :publicId',
            [':publicId' => $publicId],
        );

        return $rows === [] ? null : $this->hydrate($rows[0], true);
    }

    public function findByStyleCode(string $styleCode, bool $includeDeleted = false): ?ProductStyleRecord
    {
        $rows = $this->connection->fetchAll(
            $this->selectSql($includeDeleted) . ' AND s.[StyleCode] = :styleCode',
            [':styleCode' => strtoupper(trim($styleCode))],
        );

        return $rows === [] ? null : $this->hydrate($rows[0], true);
    }

    public function styleCodeExists(string $code, ?string $excludePublicId = null): bool
    {
        $sql = 'SELECT TOP 1 1 AS [ExistsFlag] FROM [product].[ProductStyles] WHERE [StyleCode] = :code';
        $params = [':code' => strtoupper(trim($code))];
        if ($excludePublicId !== null) {
            $sql .= ' AND [PublicId] <> :excludePublicId';
            $params[':excludePublicId'] = $excludePublicId;
        }

        return $this->connection->fetchAll($sql, $params) !== [];
    }

    public function listAncestors(string $publicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
WITH chain AS (
    SELECT s.[ProductStyleId], s.[ParentStyleId], s.[PublicId], 0 AS [Lvl]
    FROM [product].[ProductStyles] s
    WHERE s.[PublicId] = :publicId
    UNION ALL
    SELECT p.[ProductStyleId], p.[ParentStyleId], p.[PublicId], c.[Lvl] + 1
    FROM [product].[ProductStyles] p
    INNER JOIN chain c ON p.[ProductStyleId] = c.[ParentStyleId]
    WHERE c.[Lvl] < 64
)
SELECT CONVERT(NVARCHAR(36), c.[PublicId]) AS [PublicId]
FROM chain c
WHERE c.[Lvl] > 0
ORDER BY c.[Lvl]
SQL,
            [':publicId' => $publicId],
        );
        $out = [];
        foreach ($rows as $row) {
            $id = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
            $record = $this->findLite($id);
            if ($record !== null) {
                $out[] = $record;
            }
        }

        return $out;
    }

    public function listChildren(string $parentPublicId, bool $includeDeleted = false): array
    {
        return $this->hydrateMany($this->connection->fetchAll(
            $this->selectSql($includeDeleted) . ' AND parent.[PublicId] = :parentPublicId ORDER BY s.[SortOrder], s.[StyleCode]',
            [':parentPublicId' => $parentPublicId],
        ));
    }

    public function create(array $data, UserId $actorUserId): ProductStyleRecord
    {
        $parentId = $this->resolveStyleId($data['parentPublicId'] ?? null);
        $modes = $data['inheritance'];
        $publicId = self::newPublicId();
        $this->connection->execute(
            <<<'SQL'
INSERT INTO [product].[ProductStyles] (
    [PublicId], [StyleCode], [DisplayName], [ParentStyleId], [SortOrder],
    [SpecificationsInheritanceMode], [ImagesInheritanceMode], [DocumentsInheritanceMode],
    [Spin360InheritanceMode], [Models3DInheritanceMode], [ArAssetsInheritanceMode],
    [IsActive], [CreatedByUserId], [UpdatedByUserId]
)
VALUES (
    CONVERT(UNIQUEIDENTIFIER, :publicId), :styleCode, :displayName, :parentStyleId, :sortOrder,
    :specMode, :imagesMode, :docsMode, :spinMode, :modelsMode, :arMode,
    :isActive, :createdByUserId, :updatedByUserId
)
SQL,
            [
                ':publicId' => $publicId,
                ':styleCode' => $data['styleCode'],
                ':displayName' => $data['displayName'],
                ':parentStyleId' => $parentId,
                ':sortOrder' => $data['sortOrder'] ?? 0,
                ':specMode' => $modes['SPECIFICATIONS'] ?? 'LOCAL',
                ':imagesMode' => $modes['IMAGES'] ?? 'LOCAL',
                ':docsMode' => $modes['DOCUMENTS'] ?? 'LOCAL',
                ':spinMode' => $modes['SPIN_360'] ?? 'LOCAL',
                ':modelsMode' => $modes['MODELS_3D'] ?? 'LOCAL',
                ':arMode' => $modes['AR_ASSETS'] ?? 'LOCAL',
                ':isActive' => ($data['isActive'] ?? true) ? 1 : 0,
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );

        return $this->require($publicId, true);
    }

    public function update(string $publicId, array $data, string $expectedRowVersion, UserId $actorUserId): ProductStyleRecord
    {
        $sets = [
            '[UpdatedAtUtc] = SYSUTCDATETIME()',
            '[UpdatedByUserId] = :actorUserId',
        ];
        $params = [
            ':publicId' => $publicId,
            ':actorUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
        ];
        if (isset($data['styleCode'])) {
            $sets[] = '[StyleCode] = :styleCode';
            $params[':styleCode'] = $data['styleCode'];
        }
        if (isset($data['displayName'])) {
            $sets[] = '[DisplayName] = :displayName';
            $params[':displayName'] = $data['displayName'];
        }
        if (isset($data['sortOrder'])) {
            $sets[] = '[SortOrder] = :sortOrder';
            $params[':sortOrder'] = $data['sortOrder'];
        }
        $this->executeConcurrencyUpdate(
            sprintf('UPDATE [product].[ProductStyles] SET %s WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion', implode(', ', $sets)),
            $params,
            $publicId,
        );

        return $this->require($publicId, true);
    }

    public function setParent(string $publicId, ?string $parentPublicId, string $expectedRowVersion, UserId $actorUserId): ProductStyleRecord
    {
        if ($parentPublicId !== null && $this->resolveStyleId($parentPublicId) === null) {
            throw InvalidProductStyleHierarchy::parentNotFound($parentPublicId);
        }
        $this->executeConcurrencyUpdate(
            <<<'SQL'
UPDATE [product].[ProductStyles]
SET [ParentStyleId] = :parentStyleId, [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actorUserId
WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion
SQL,
            [
                ':parentStyleId' => $this->resolveStyleId($parentPublicId),
                ':actorUserId' => $actorUserId->toInt(),
                ':publicId' => $publicId,
                ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
            ],
            $publicId,
        );

        return $this->require($publicId, true);
    }

    public function setActive(string $publicId, bool $isActive, string $expectedRowVersion, UserId $actorUserId): ProductStyleRecord
    {
        $this->executeConcurrencyUpdate(
            <<<'SQL'
UPDATE [product].[ProductStyles]
SET [IsActive] = :isActive, [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actorUserId
WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':isActive' => $isActive ? 1 : 0,
                ':actorUserId' => $actorUserId->toInt(),
                ':publicId' => $publicId,
                ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
            ],
            $publicId,
        );

        return $this->require($publicId, false);
    }

    public function softDelete(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductStyleRecord
    {
        $this->executeConcurrencyUpdate(
            <<<'SQL'
UPDATE [product].[ProductStyles]
SET [DeletedAtUtc] = SYSUTCDATETIME(), [DeletedByUserId] = :deletedByUserId, [IsActive] = 0,
    [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :updatedByUserId
WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':deletedByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
                ':publicId' => $publicId,
                ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
            ],
            $publicId,
        );

        return $this->require($publicId, true);
    }

    public function restore(string $publicId, string $expectedRowVersion, UserId $actorUserId): ProductStyleRecord
    {
        $this->executeConcurrencyUpdate(
            <<<'SQL'
UPDATE [product].[ProductStyles]
SET [DeletedAtUtc] = NULL, [DeletedByUserId] = NULL, [IsActive] = 1,
    [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actorUserId
WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion
SQL,
            [
                ':actorUserId' => $actorUserId->toInt(),
                ':publicId' => $publicId,
                ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
            ],
            $publicId,
        );

        return $this->require($publicId, false);
    }

    public function reorder(?string $parentPublicId, array $orderedPublicIds, UserId $actorUserId): void
    {
        $order = 10;
        foreach ($orderedPublicIds as $publicId) {
            $current = $this->findByPublicId($publicId, false);
            if ($current === null) {
                continue;
            }
            if ($current->parentPublicId !== $parentPublicId) {
                continue;
            }
            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[ProductStyles]
SET [SortOrder] = :sortOrder, [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :actorUserId
WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL
SQL,
                [
                    ':sortOrder' => $order,
                    ':actorUserId' => $actorUserId->toInt(),
                    ':publicId' => $publicId,
                ],
            );
            $order += 10;
        }
    }

    public function updateInheritance(string $publicId, array $modes, string $expectedRowVersion, UserId $actorUserId): ProductStyleRecord
    {
        $this->executeConcurrencyUpdate(
            <<<'SQL'
UPDATE [product].[ProductStyles]
SET [SpecificationsInheritanceMode] = :specMode,
    [ImagesInheritanceMode] = :imagesMode,
    [DocumentsInheritanceMode] = :docsMode,
    [Spin360InheritanceMode] = :spinMode,
    [Models3DInheritanceMode] = :modelsMode,
    [ArAssetsInheritanceMode] = :arMode,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :actorUserId
WHERE [PublicId] = :publicId AND [RowVersion] = :expectedRowVersion
SQL,
            [
                ':specMode' => $modes['SPECIFICATIONS'] ?? 'LOCAL',
                ':imagesMode' => $modes['IMAGES'] ?? 'LOCAL',
                ':docsMode' => $modes['DOCUMENTS'] ?? 'LOCAL',
                ':spinMode' => $modes['SPIN_360'] ?? 'LOCAL',
                ':modelsMode' => $modes['MODELS_3D'] ?? 'LOCAL',
                ':arMode' => $modes['AR_ASSETS'] ?? 'LOCAL',
                ':actorUserId' => $actorUserId->toInt(),
                ':publicId' => $publicId,
                ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(RowVersionToken::fromBase64($expectedRowVersion)),
            ],
            $publicId,
        );

        return $this->require($publicId, true);
    }

    public function getUsage(string $publicId): ProductStyleUsage
    {
        $styleId = $this->requireStyleId($publicId, true);
        $childRows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    SUM(CASE WHEN [DeletedAtUtc] IS NULL THEN 1 ELSE 0 END) AS [ChildCount],
    SUM(CASE WHEN [DeletedAtUtc] IS NULL AND [IsActive] = 1 THEN 1 ELSE 0 END) AS [ActiveChildCount],
    SUM(CASE WHEN [DeletedAtUtc] IS NOT NULL THEN 1 ELSE 0 END) AS [DeletedChildCount]
FROM [product].[ProductStyles]
WHERE [ParentStyleId] = :styleId
SQL,
            [':styleId' => $styleId],
        );
        $productRows = $this->connection->fetchAll(
            'SELECT COUNT(1) AS [Cnt] FROM [product].[ProductStyleAssignments] WHERE [ProductStyleId] = :styleId',
            [':styleId' => $styleId],
        );
        $childCount = AzureSqlValueMapper::intValue($childRows[0]['ChildCount'] ?? 0);
        $activeChildCount = AzureSqlValueMapper::intValue($childRows[0]['ActiveChildCount'] ?? 0);
        $deletedChildCount = AzureSqlValueMapper::intValue($childRows[0]['DeletedChildCount'] ?? 0);
        $productCount = AzureSqlValueMapper::intValue($productRows[0]['Cnt'] ?? 0);
        $parts = [];
        if ($productCount > 0) {
            $parts[] = sprintf('%d product(s)', $productCount);
        }
        if ($childCount > 0) {
            $parts[] = sprintf('%d child style(s)', $childCount);
        }
        if ($deletedChildCount > 0) {
            $parts[] = sprintf('%d archived child style(s)', $deletedChildCount);
        }

        return new ProductStyleUsage(
            publicId: $publicId,
            productCount: $productCount,
            childCount: $childCount,
            activeChildCount: $activeChildCount,
            deletedChildCount: $deletedChildCount,
            summary: $parts === [] ? 'No references.' : implode(', ', $parts) . '.',
        );
    }

    public function listStyleSpecificationValues(string $stylePublicId): array
    {
        return $this->hydrateSpecRows($this->connection->fetchAll(
            $this->specSelectSql() . ' AND st.[PublicId] = :stylePublicId',
            [':stylePublicId' => $stylePublicId],
        ));
    }

    public function listSpecificationValuesForStyles(array $stylePublicIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $stylePublicIds,
            static fn (string $id): bool => $id !== '',
        )));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
        }
        if ($ids === []) {
            return $out;
        }

        $in = $this->publicIdInClause($ids, 'styleId');
        $rows = $this->connection->fetchAll(
            $this->specSelectSql() . ' AND ' . $in['sql'] . ' ORDER BY st.[PublicId], d.[SpecificationDefinitionId]',
            $in['params'],
        );
        foreach ($rows as $row) {
            $stylePublicId = AzureSqlValueMapper::stringValue($row['StylePublicId'] ?? '');
            if ($stylePublicId === '') {
                continue;
            }
            $out[$stylePublicId][] = $this->hydrateSpecRows([$row])[0];
        }

        return $out;
    }

    public function replaceStyleSpecificationValues(string $stylePublicId, array $values, UserId $actorUserId): void
    {
        $styleId = $this->requireStyleId($stylePublicId, false);
        $this->connection->execute(
            'UPDATE [product].[ProductStyleSpecificationValues] SET [DeletedAtUtc] = SYSUTCDATETIME(), [DeletedByUserId] = :actorUserId WHERE [ProductStyleId] = :styleId AND [DeletedAtUtc] IS NULL',
            [':actorUserId' => $actorUserId->toInt(), ':styleId' => $styleId],
        );
        foreach ($values as $value) {
            $definitionId = $this->resolveDefinitionId((string) $value['definitionPublicId']);
            $optionId = isset($value['optionPublicId']) && is_string($value['optionPublicId']) && $value['optionPublicId'] !== ''
                ? $this->resolveOptionId($value['optionPublicId'])
                : null;
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [product].[ProductStyleSpecificationValues] (
    [ProductStyleId], [SpecificationDefinitionId],
    [ValueText], [ValueLongText], [ValueInteger], [ValueDecimal], [ValueBoolean], [ValueDate], [ValueUrl],
    [ValueMinDecimal], [ValueMaxDecimal], [SpecificationOptionId], [CreatedByUserId], [UpdatedByUserId]
) VALUES (
    :styleId, :definitionId, :valueText, :valueLongText, :valueInteger, :valueDecimal, :valueBoolean, :valueDate, :valueUrl,
    :valueMinDecimal, :valueMaxDecimal, :optionId, :createdByUserId, :updatedByUserId
)
SQL,
                [
                    ':styleId' => $styleId,
                    ':definitionId' => $definitionId,
                    ':valueText' => $value['valueText'] ?? null,
                    ':valueLongText' => $value['valueLongText'] ?? null,
                    ':valueInteger' => $value['valueInteger'] ?? null,
                    ':valueDecimal' => $value['valueDecimal'] ?? null,
                    ':valueBoolean' => isset($value['valueBoolean']) ? ($value['valueBoolean'] ? 1 : 0) : null,
                    ':valueDate' => $value['valueDate'] ?? null,
                    ':valueUrl' => $value['valueUrl'] ?? null,
                    ':valueMinDecimal' => $value['valueMinDecimal'] ?? null,
                    ':valueMaxDecimal' => $value['valueMaxDecimal'] ?? null,
                    ':optionId' => $optionId,
                    ':createdByUserId' => $actorUserId->toInt(),
                    ':updatedByUserId' => $actorUserId->toInt(),
                ],
            );
        }
    }

    public function listStyleAssets(string $stylePublicId, StyleResourceType $type): array
    {
        return $this->hydrateAssetRows($this->connection->fetchAll(
            $this->assetSelectSql() . ' AND st.[PublicId] = :stylePublicId AND a.[ResourceTypeCode] = :resourceType AND a.[IsActive] = 1 AND a.[UploadStatusCode] = N\'active\' ORDER BY a.[DisplayOrder], a.[Title]',
            [':stylePublicId' => $stylePublicId, ':resourceType' => $type->toString()],
        ));
    }

    public function listAssetsForStyles(array $stylePublicIds, StyleResourceType $type): array
    {
        $ids = array_values(array_unique(array_filter(
            $stylePublicIds,
            static fn (string $id): bool => $id !== '',
        )));
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [];
        }
        if ($ids === []) {
            return $out;
        }

        $in = $this->publicIdInClause($ids, 'styleId');
        $rows = $this->connection->fetchAll(
            $this->assetSelectSql()
            . ' AND ' . $in['sql']
            . ' AND a.[ResourceTypeCode] = :resourceType AND a.[IsActive] = 1 AND a.[UploadStatusCode] = N\'active\' ORDER BY st.[PublicId], a.[DisplayOrder], a.[Title]',
            $in['params'] + [':resourceType' => $type->toString()],
        );
        foreach ($rows as $row) {
            $stylePublicId = AzureSqlValueMapper::stringValue($row['StylePublicId'] ?? '');
            if ($stylePublicId === '') {
                continue;
            }
            $out[$stylePublicId][] = $this->hydrateAssetRows([$row])[0];
        }

        return $out;
    }

    public function replaceStyleAssets(string $stylePublicId, StyleResourceType $type, array $assets, UserId $actorUserId): void
    {
        $styleId = $this->requireStyleId($stylePublicId, false);
        $this->connection->execute(
            'UPDATE [product].[ProductStyleMediaAssets] SET [DeletedAtUtc] = SYSUTCDATETIME(), [DeletedByUserId] = :actorUserId WHERE [ProductStyleId] = :styleId AND [ResourceTypeCode] = :resourceType AND [DeletedAtUtc] IS NULL',
            [':actorUserId' => $actorUserId->toInt(), ':styleId' => $styleId, ':resourceType' => $type->toString()],
        );
        foreach ($assets as $asset) {
            $this->connection->execute(
                <<<'SQL'
INSERT INTO [product].[ProductStyleMediaAssets] (
    [ProductStyleId], [ResourceTypeCode], [Title], [RoleCode], [AltText],
    [StorageProviderCode], [StorageContainer], [StoragePath], [PublicUrl],
    [ContentType], [ChecksumSha256], [DisplayOrder], [IsPrimary],
    [UploadStatusCode], [ByteSize], [OriginalFileName], [IsActive],
    [CreatedByUserId], [UpdatedByUserId]
) VALUES (
    :styleId, :resourceType, :title, :roleCode, :altText,
    :storageProvider, :storageContainer, :storagePath, :publicUrl,
    :contentType, :checksum, :displayOrder, :isPrimary,
    N'active', :byteSize, :originalFileName, 1,
    :createdByUserId, :updatedByUserId
)
SQL,
                [
                    ':styleId' => $styleId,
                    ':resourceType' => $type->toString(),
                    ':title' => $asset['title'] ?? 'Asset',
                    ':roleCode' => $asset['roleCode'] ?? null,
                    ':altText' => $asset['altText'] ?? null,
                    ':storageProvider' => $asset['storageProviderCode'] ?? 'azure-blob',
                    ':storageContainer' => $asset['storageContainer'] ?? null,
                    ':storagePath' => $asset['storagePath'] ?? null,
                    ':publicUrl' => $asset['publicUrl'] ?? null,
                    ':contentType' => $asset['contentType'] ?? null,
                    ':checksum' => $asset['checksumSha256'] ?? null,
                    ':displayOrder' => $asset['displayOrder'] ?? 0,
                    ':isPrimary' => !empty($asset['isPrimary']) ? 1 : 0,
                    ':byteSize' => $asset['byteSize'] ?? null,
                    ':originalFileName' => $asset['originalFileName'] ?? null,
                    ':createdByUserId' => $actorUserId->toInt(),
                    ':updatedByUserId' => $actorUserId->toInt(),
                ],
            );
        }
    }

    public function findStyleAssetByPublicId(string $stylePublicId, string $assetPublicId): ?array
    {
        $rows = $this->hydrateAssetRows($this->connection->fetchAll(
            $this->assetSelectSql() . ' AND st.[PublicId] = :stylePublicId AND a.[PublicId] = :assetPublicId',
            [':stylePublicId' => $stylePublicId, ':assetPublicId' => $assetPublicId],
        ));

        return $rows[0] ?? null;
    }

    public function createStyleAssetUploadSession(string $stylePublicId, array $asset, UserId $actorUserId): array
    {
        $styleId = $this->requireStyleId($stylePublicId, false);
        $publicId = is_string($asset['publicId'] ?? null) && $asset['publicId'] !== ''
            ? $asset['publicId']
            : null;
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[ProductStyleMediaAssets] (
    [PublicId], [ProductStyleId], [ResourceTypeCode], [Title], [RoleCode], [AltText],
    [StorageProviderCode], [StorageContainer], [StoragePath], [PublicUrl],
    [ContentType], [ChecksumSha256], [DisplayOrder], [IsPrimary],
    [UploadStatusCode], [ByteSize], [OriginalFileName], [IsActive],
    [CreatedByUserId], [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    CONVERT(UNIQUEIDENTIFIER, :publicId), :styleId, :resourceType, :title, :roleCode, :altText,
    :storageProvider, :storageContainer, :storagePath, :publicUrl,
    :contentType, :checksum, :displayOrder, :isPrimary,
    :uploadStatus, :byteSize, :originalFileName, 0,
    :createdByUserId, :updatedByUserId
)
SQL,
            [
                ':publicId' => $publicId,
                ':styleId' => $styleId,
                ':resourceType' => $asset['resourceType'],
                ':title' => $asset['title'] ?? 'Asset',
                ':roleCode' => $asset['roleCode'] ?? null,
                ':altText' => $asset['altText'] ?? null,
                ':storageProvider' => $asset['storageProviderCode'] ?? 'azure-blob',
                ':storageContainer' => $asset['storageContainer'] ?? null,
                ':storagePath' => $asset['storagePath'] ?? null,
                ':publicUrl' => $asset['publicUrl'] ?? null,
                ':contentType' => $asset['contentType'] ?? null,
                ':checksum' => $asset['checksumSha256'] ?? null,
                ':displayOrder' => $asset['displayOrder'] ?? 0,
                ':isPrimary' => !empty($asset['isPrimary']) ? 1 : 0,
                ':uploadStatus' => $asset['uploadStatusCode'] ?? 'pending_upload',
                ':byteSize' => $asset['byteSize'] ?? null,
                ':originalFileName' => $asset['originalFileName'] ?? null,
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
        $inserted = AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? '');
        $found = $this->findStyleAssetByPublicId($stylePublicId, $inserted);
        if ($found === null) {
            throw ProductStyleNotFound::forPublicId($inserted);
        }

        return $found;
    }

    public function markStyleAssetUploadCompleted(string $stylePublicId, string $assetPublicId, array $data, UserId $actorUserId): array
    {
        $this->connection->execute(
            <<<'SQL'
UPDATE a
SET
    a.[UploadStatusCode] = :uploadStatus,
    a.[IsActive] = 1,
    a.[PublicUrl] = :publicUrl,
    a.[ContentType] = :contentType,
    a.[ByteSize] = :byteSize,
    a.[StoragePath] = :storagePath,
    a.[StorageContainer] = :storageContainer,
    a.[UpdatedAtUtc] = SYSUTCDATETIME(),
    a.[UpdatedByUserId] = :actorUserId
FROM [product].[ProductStyleMediaAssets] a
INNER JOIN [product].[ProductStyles] st ON st.[ProductStyleId] = a.[ProductStyleId]
WHERE st.[PublicId] = :stylePublicId AND a.[PublicId] = :assetPublicId AND a.[DeletedAtUtc] IS NULL
SQL,
            [
                ':uploadStatus' => $data['uploadStatusCode'] ?? 'active',
                ':publicUrl' => $data['publicUrl'] ?? null,
                ':contentType' => $data['contentType'] ?? null,
                ':byteSize' => $data['byteSize'] ?? null,
                ':storagePath' => $data['storagePath'] ?? null,
                ':storageContainer' => $data['storageContainer'] ?? null,
                ':actorUserId' => $actorUserId->toInt(),
                ':stylePublicId' => $stylePublicId,
                ':assetPublicId' => $assetPublicId,
            ],
        );
        $found = $this->findStyleAssetByPublicId($stylePublicId, $assetPublicId);
        if ($found === null) {
            throw ProductStyleNotFound::forPublicId($assetPublicId);
        }

        return $found;
    }

    public function softDeleteStyleAsset(string $stylePublicId, string $assetPublicId, UserId $actorUserId): void
    {
        $this->connection->execute(
            <<<'SQL'
UPDATE a
SET
    a.[DeletedAtUtc] = SYSUTCDATETIME(),
    a.[DeletedByUserId] = :deletedByUserId,
    a.[IsActive] = 0,
    a.[UploadStatusCode] = N'deleted',
    a.[UpdatedAtUtc] = SYSUTCDATETIME(),
    a.[UpdatedByUserId] = :updatedByUserId
FROM [product].[ProductStyleMediaAssets] a
INNER JOIN [product].[ProductStyles] st ON st.[ProductStyleId] = a.[ProductStyleId]
WHERE st.[PublicId] = :stylePublicId AND a.[PublicId] = :assetPublicId AND a.[DeletedAtUtc] IS NULL
SQL,
            [
                ':deletedByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
                ':stylePublicId' => $stylePublicId,
                ':assetPublicId' => $assetPublicId,
            ],
        );
    }

    public function countActiveStoragePathReferences(string $storagePath, ?string $storageContainer = null): int
    {
        $tables = [
            '[product].[ProductStyleMediaAssets]',
            '[product].[ProductImages]',
            '[product].[ProductDocuments]',
            '[product].[Product360Frames]',
            '[product].[ProductARModels]',
            '[product].[Product3DModels]',
        ];
        $total = 0;
        foreach ($tables as $table) {
            $sql = sprintf(
                'SELECT COUNT(*) AS [ReferenceCount] FROM %s WHERE [StoragePath] = :storagePath AND [DeletedAtUtc] IS NULL',
                $table,
            );
            $params = [':storagePath' => $storagePath];
            if ($storageContainer !== null && $storageContainer !== '') {
                $sql .= ' AND [StorageContainer] = :storageContainer';
                $params[':storageContainer'] = $storageContainer;
            }
            try {
                $rows = $this->connection->fetchAll($sql, $params);
                $total += AzureSqlValueMapper::intValue($rows[0]['ReferenceCount'] ?? 0);
            } catch (\Throwable) {
                continue;
            }
        }

        return $total;
    }

    public function findAssignmentByProductPublicId(string $productPublicId): ?ProductStyleAssignment
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), s.[PublicId]) AS [StylePublicId]
FROM [product].[ProductStyleAssignments] a
INNER JOIN [product].[Products] p ON p.[ProductId] = a.[ProductId]
INNER JOIN [product].[ProductStyles] s ON s.[ProductStyleId] = a.[ProductStyleId]
WHERE p.[PublicId] = :productPublicId
SQL,
            [':productPublicId' => $productPublicId],
        );
        if ($rows === []) {
            return null;
        }
        $style = $this->findByPublicId(AzureSqlValueMapper::stringValue($rows[0]['StylePublicId'] ?? ''), true);
        if ($style === null) {
            return null;
        }

        return new ProductStyleAssignment(
            productPublicId: $productPublicId,
            stylePublicId: $style->publicId,
            styleCode: $style->styleCode,
            displayName: $style->displayName,
            parentPublicId: $style->parentPublicId,
            parentStyleCode: $style->parentStyleCode,
            ancestors: $style->ancestors,
        );
    }

    public function assignProduct(string $productPublicId, string $stylePublicId, UserId $actorUserId): ProductStyleAssignment
    {
        $productId = $this->resolveProductId($productPublicId);
        $styleId = $this->requireStyleId($stylePublicId, false);
        $this->connection->execute(
            <<<'SQL'
MERGE [product].[ProductStyleAssignments] AS t
USING (SELECT :productId AS [ProductId], :styleId AS [ProductStyleId]) AS s
ON t.[ProductId] = s.[ProductId]
WHEN MATCHED THEN UPDATE SET [ProductStyleId] = s.[ProductStyleId], [UpdatedAtUtc] = SYSUTCDATETIME(), [UpdatedByUserId] = :matchedActorUserId
WHEN NOT MATCHED THEN INSERT ([ProductId], [ProductStyleId], [CreatedByUserId], [UpdatedByUserId])
VALUES (s.[ProductId], s.[ProductStyleId], :createdByUserId, :updatedByUserId);
SQL,
            [
                ':productId' => $productId,
                ':styleId' => $styleId,
                ':matchedActorUserId' => $actorUserId->toInt(),
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
        $assignment = $this->findAssignmentByProductPublicId($productPublicId);
        if ($assignment === null) {
            throw ProductStyleNotFound::forPublicId($stylePublicId);
        }

        return $assignment;
    }

    public function unassignProduct(string $productPublicId, UserId $actorUserId): void
    {
        unset($actorUserId);
        $this->connection->execute(
            <<<'SQL'
DELETE a FROM [product].[ProductStyleAssignments] a
INNER JOIN [product].[Products] p ON p.[ProductId] = a.[ProductId]
WHERE p.[PublicId] = :productPublicId
SQL,
            [':productPublicId' => $productPublicId],
        );
    }

    public function listAssignedProductPublicIds(string $stylePublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId]
FROM [product].[ProductStyleAssignments] a
INNER JOIN [product].[ProductStyles] s ON s.[ProductStyleId] = a.[ProductStyleId]
INNER JOIN [product].[Products] p ON p.[ProductId] = a.[ProductId]
WHERE s.[PublicId] = :stylePublicId
SQL,
            [':stylePublicId' => $stylePublicId],
        );
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        }

        return $ids;
    }

    public function listAssignedProductPublicIdsByStyle(): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [StylePublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [PublicId]
FROM [product].[ProductStyleAssignments] a
INNER JOIN [product].[ProductStyles] s ON s.[ProductStyleId] = a.[ProductStyleId]
INNER JOIN [product].[Products] p ON p.[ProductId] = a.[ProductId]
SQL,
        );
        $out = [];
        foreach ($rows as $row) {
            $out[AzureSqlValueMapper::stringValue($row['StylePublicId'] ?? '')][] = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        }

        return $out;
    }

    private function selectSql(bool $includeDeleted): string
    {
        $sql = <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), s.[PublicId]) AS [PublicId],
    s.[StyleCode],
    s.[DisplayName],
    CONVERT(NVARCHAR(36), parent.[PublicId]) AS [ParentPublicId],
    parent.[StyleCode] AS [ParentStyleCode],
    parent.[DisplayName] AS [ParentDisplayName],
    s.[SortOrder],
    s.[IsActive],
    CASE WHEN s.[DeletedAtUtc] IS NULL THEN 0 ELSE 1 END AS [IsDeleted],
    CONVERT(VARBINARY(8), s.[RowVersion]) AS [RowVersion],
    CONVERT(NVARCHAR(33), s.[CreatedAtUtc], 126) AS [CreatedAtUtc],
    CONVERT(NVARCHAR(33), s.[UpdatedAtUtc], 126) AS [UpdatedAtUtc],
    s.[SpecificationsInheritanceMode],
    s.[ImagesInheritanceMode],
    s.[DocumentsInheritanceMode],
    s.[Spin360InheritanceMode],
    s.[Models3DInheritanceMode],
    s.[ArAssetsInheritanceMode]
FROM [product].[ProductStyles] s
LEFT JOIN [product].[ProductStyles] parent ON parent.[ProductStyleId] = s.[ParentStyleId]
WHERE 1 = 1
SQL;
        if (!$includeDeleted) {
            $sql .= ' AND s.[DeletedAtUtc] IS NULL';
        }

        return $sql;
    }

    private function specSelectSql(): string
    {
        return <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), st.[PublicId]) AS [StylePublicId],
    CONVERT(NVARCHAR(36), d.[PublicId]) AS [DefinitionPublicId],
    v.[ValueText], v.[ValueLongText], v.[ValueInteger],
    CONVERT(NVARCHAR(50), v.[ValueDecimal]) AS [ValueDecimal],
    v.[ValueBoolean],
    CONVERT(VARCHAR(33), v.[ValueDate], 127) AS [ValueDate],
    v.[ValueUrl],
    CONVERT(NVARCHAR(50), v.[ValueMinDecimal]) AS [ValueMinDecimal],
    CONVERT(NVARCHAR(50), v.[ValueMaxDecimal]) AS [ValueMaxDecimal],
    CONVERT(NVARCHAR(36), so.[PublicId]) AS [OptionPublicId]
FROM [product].[ProductStyleSpecificationValues] v
INNER JOIN [product].[ProductStyles] st ON st.[ProductStyleId] = v.[ProductStyleId]
INNER JOIN [product].[SpecificationDefinitions] d ON d.[SpecificationDefinitionId] = v.[SpecificationDefinitionId]
LEFT JOIN [product].[SpecificationOptions] so ON so.[SpecificationOptionId] = v.[SpecificationOptionId]
WHERE v.[DeletedAtUtc] IS NULL
SQL;
    }

    private function assetSelectSql(): string
    {
        return <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), st.[PublicId]) AS [StylePublicId],
    CONVERT(NVARCHAR(36), a.[PublicId]) AS [PublicId],
    a.[ResourceTypeCode], a.[Title], a.[RoleCode], a.[AltText],
    a.[StorageProviderCode], a.[StorageContainer], a.[StoragePath], a.[PublicUrl],
    a.[ContentType], a.[ChecksumSha256], a.[DisplayOrder], a.[IsPrimary],
    a.[UploadStatusCode], a.[ByteSize], a.[OriginalFileName]
FROM [product].[ProductStyleMediaAssets] a
INNER JOIN [product].[ProductStyles] st ON st.[ProductStyleId] = a.[ProductStyleId]
WHERE a.[DeletedAtUtc] IS NULL
SQL;
    }

    /**
     * Parameterised IN list. Never concatenates user values into SQL.
     *
     * @param list<string> $ids
     * @return array{sql: string, params: array<string, string>}
     */
    private function publicIdInClause(array $ids, string $prefix): array
    {
        $placeholders = [];
        $params = [];
        foreach (array_values($ids) as $index => $id) {
            $key = ':' . $prefix . $index;
            $placeholders[] = $key;
            $params[$key] = $id;
        }

        return [
            'sql' => 'st.[PublicId] IN (' . implode(', ', $placeholders) . ')',
            'params' => $params,
        ];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function executeConcurrencyUpdate(string $sql, array $params, string $publicId): void
    {
        $wrapped = <<<SQL
SET NOCOUNT ON;
{$sql};
SELECT @@ROWCOUNT AS [Affected];
SQL;
        $rows = $this->connection->fetchAll($wrapped, $params);
        $affected = AzureSqlValueMapper::intValue($rows[0]['Affected'] ?? 0);
        if ($affected < 1) {
            if ($this->findByPublicId($publicId, true) === null) {
                throw ProductStyleNotFound::forPublicId($publicId);
            }
            throw ProductStyleConcurrencyConflict::forPublicId($publicId);
        }
    }

    private function require(string $publicId, bool $includeDeleted): ProductStyleRecord
    {
        $record = $this->findByPublicId($publicId, $includeDeleted);
        if ($record === null) {
            throw ProductStyleNotFound::forPublicId($publicId);
        }

        return $record;
    }

    private function resolveStyleId(?string $publicId): ?int
    {
        if ($publicId === null || trim($publicId) === '') {
            return null;
        }
        $rows = $this->connection->fetchAll(
            'SELECT [ProductStyleId] FROM [product].[ProductStyles] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId],
        );
        if ($rows === []) {
            return null;
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductStyleId'] ?? 0);
    }

    private function requireStyleId(string $publicId, bool $includeDeleted): int
    {
        $sql = 'SELECT [ProductStyleId] FROM [product].[ProductStyles] WHERE [PublicId] = :publicId';
        if (!$includeDeleted) {
            $sql .= ' AND [DeletedAtUtc] IS NULL';
        }
        $rows = $this->connection->fetchAll($sql, [':publicId' => $publicId]);
        if ($rows === []) {
            throw ProductStyleNotFound::forPublicId($publicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductStyleId'] ?? 0);
    }

    private function resolveProductId(string $productPublicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $productPublicId],
        );
        if ($rows === []) {
            throw \TriCab\Core\Product\Application\Exception\ProductNotFound::forPublicId($productPublicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductId'] ?? 0);
    }

    private function resolveDefinitionId(string $definitionPublicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [SpecificationDefinitionId] FROM [product].[SpecificationDefinitions] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $definitionPublicId],
        );
        if ($rows === []) {
            throw ProductStyleNotFound::forPublicId($definitionPublicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['SpecificationDefinitionId'] ?? 0);
    }

    private function resolveOptionId(string $optionPublicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [SpecificationOptionId] FROM [product].[SpecificationOptions] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $optionPublicId],
        );

        return AzureSqlValueMapper::intValue($rows[0]['SpecificationOptionId'] ?? 0);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<ProductStyleRecord>
     */
    private function hydrateMany(array $rows): array
    {
        return array_map(fn (array $row): ProductStyleRecord => $this->hydrate($row, false), $rows);
    }

    private function findLite(string $publicId): ?ProductStyleRecord
    {
        $rows = $this->connection->fetchAll(
            $this->selectSql(true) . ' AND s.[PublicId] = :publicId',
            [':publicId' => $publicId],
        );

        return $rows === [] ? null : $this->hydrate($rows[0], false);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row, bool $withGraph): ProductStyleRecord
    {
        $publicId = AzureSqlValueMapper::stringValue($row['PublicId'] ?? '');
        $children = [];
        $ancestors = [];
        $productCount = 0;
        $childCount = 0;
        $activeChildCount = 0;
        if ($withGraph) {
            $usage = $this->getUsage($publicId);
            $productCount = $usage->productCount;
            $childCount = $usage->childCount;
            $activeChildCount = $usage->activeChildCount;
            foreach ($this->connection->fetchAll(
                <<<'SQL'
SELECT CONVERT(NVARCHAR(36), [PublicId]) AS [PublicId], [StyleCode], [DisplayName], [IsActive]
FROM [product].[ProductStyles]
WHERE [ParentStyleId] = (SELECT [ProductStyleId] FROM [product].[ProductStyles] WHERE [PublicId] = :publicId)
  AND [DeletedAtUtc] IS NULL
ORDER BY [SortOrder], [StyleCode]
SQL,
                [':publicId' => $publicId],
            ) as $child) {
                $children[] = [
                    'publicId' => AzureSqlValueMapper::stringValue($child['PublicId'] ?? ''),
                    'styleCode' => AzureSqlValueMapper::stringValue($child['StyleCode'] ?? ''),
                    'displayName' => AzureSqlValueMapper::stringValue($child['DisplayName'] ?? ''),
                    'isActive' => AzureSqlValueMapper::boolValue($child['IsActive'] ?? true),
                ];
            }
            foreach ($this->listAncestors($publicId) as $ancestor) {
                $ancestors[] = [
                    'publicId' => $ancestor->publicId,
                    'styleCode' => $ancestor->styleCode,
                    'displayName' => $ancestor->displayName,
                ];
            }
        }

        return new ProductStyleRecord(
            publicId: $publicId,
            styleCode: AzureSqlValueMapper::stringValue($row['StyleCode'] ?? ''),
            displayName: AzureSqlValueMapper::stringValue($row['DisplayName'] ?? ''),
            parentPublicId: AzureSqlValueMapper::nullableString($row['ParentPublicId'] ?? null),
            parentStyleCode: AzureSqlValueMapper::nullableString($row['ParentStyleCode'] ?? null),
            parentDisplayName: AzureSqlValueMapper::nullableString($row['ParentDisplayName'] ?? null),
            sortOrder: AzureSqlValueMapper::intValue($row['SortOrder'] ?? 0),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            isDeleted: AzureSqlValueMapper::boolValue($row['IsDeleted'] ?? false),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            inheritance: new ProductStyleInheritanceConfig(
                AzureSqlValueMapper::stringValue($row['SpecificationsInheritanceMode'] ?? 'LOCAL'),
                AzureSqlValueMapper::stringValue($row['ImagesInheritanceMode'] ?? 'LOCAL'),
                AzureSqlValueMapper::stringValue($row['DocumentsInheritanceMode'] ?? 'LOCAL'),
                AzureSqlValueMapper::stringValue($row['Spin360InheritanceMode'] ?? 'LOCAL'),
                AzureSqlValueMapper::stringValue($row['Models3DInheritanceMode'] ?? 'LOCAL'),
                AzureSqlValueMapper::stringValue($row['ArAssetsInheritanceMode'] ?? 'LOCAL'),
            ),
            createdAtUtc: AzureSqlValueMapper::nullableString($row['CreatedAtUtc'] ?? null),
            updatedAtUtc: AzureSqlValueMapper::nullableString($row['UpdatedAtUtc'] ?? null),
            productCount: $productCount,
            childCount: $childCount,
            activeChildCount: $activeChildCount,
            ancestors: $ancestors,
            children: $children,
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateSpecRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'definitionPublicId' => AzureSqlValueMapper::stringValue($row['DefinitionPublicId'] ?? ''),
                'valueText' => AzureSqlValueMapper::nullableString($row['ValueText'] ?? null),
                'valueLongText' => AzureSqlValueMapper::nullableString($row['ValueLongText'] ?? null),
                'valueInteger' => isset($row['ValueInteger']) && $row['ValueInteger'] !== null ? AzureSqlValueMapper::intValue($row['ValueInteger']) : null,
                'valueDecimal' => AzureSqlValueMapper::nullableString($row['ValueDecimal'] ?? null),
                'valueBoolean' => isset($row['ValueBoolean']) && $row['ValueBoolean'] !== null ? AzureSqlValueMapper::boolValue($row['ValueBoolean']) : null,
                'valueDate' => AzureSqlValueMapper::nullableString($row['ValueDate'] ?? null),
                'valueUrl' => AzureSqlValueMapper::nullableString($row['ValueUrl'] ?? null),
                'valueMinDecimal' => AzureSqlValueMapper::nullableString($row['ValueMinDecimal'] ?? null),
                'valueMaxDecimal' => AzureSqlValueMapper::nullableString($row['ValueMaxDecimal'] ?? null),
                'optionPublicId' => AzureSqlValueMapper::nullableString($row['OptionPublicId'] ?? null),
                'optionPublicIds' => [],
            ];
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function hydrateAssetRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'publicId' => AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
                'resourceType' => AzureSqlValueMapper::stringValue($row['ResourceTypeCode'] ?? ''),
                'title' => AzureSqlValueMapper::stringValue($row['Title'] ?? 'Asset'),
                'roleCode' => AzureSqlValueMapper::nullableString($row['RoleCode'] ?? null),
                'altText' => AzureSqlValueMapper::nullableString($row['AltText'] ?? null),
                'storageProviderCode' => AzureSqlValueMapper::nullableString($row['StorageProviderCode'] ?? null),
                'storageContainer' => AzureSqlValueMapper::nullableString($row['StorageContainer'] ?? null),
                'storagePath' => AzureSqlValueMapper::nullableString($row['StoragePath'] ?? null),
                'publicUrl' => AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
                'contentType' => AzureSqlValueMapper::nullableString($row['ContentType'] ?? null),
                'checksumSha256' => AzureSqlValueMapper::nullableString($row['ChecksumSha256'] ?? null),
                'displayOrder' => AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
                'isPrimary' => AzureSqlValueMapper::boolValue($row['IsPrimary'] ?? false),
                'uploadStatusCode' => AzureSqlValueMapper::stringValue($row['UploadStatusCode'] ?? 'active'),
                'byteSize' => isset($row['ByteSize']) && $row['ByteSize'] !== null ? AzureSqlValueMapper::intValue($row['ByteSize']) : null,
                'originalFileName' => AzureSqlValueMapper::nullableString($row['OriginalFileName'] ?? null),
                'metadata' => [],
            ];
        }

        return $out;
    }

    private static function newPublicId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
