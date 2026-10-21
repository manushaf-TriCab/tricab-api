<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\Product3DModelSummary;
use TriCab\Core\Product\Application\DTO\ProductPublic3DModel;
use TriCab\Core\Product\Domain\Service\Product3DPublicRepresentationProjector;
use TriCab\Core\Product\Application\DTO\ProductPublic3DPayload;
use TriCab\Core\Product\Application\Exception\Product3DModelNotFound;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Domain\Service\Product3DViewerConfigurationValidator;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class AzureSqlProduct3DModelRepository implements Product3DModelRepository
{
    use AzureSqlTransaction;

    private const SELECT_COLUMNS_BASE = <<<'SQL'
    CONVERT(NVARCHAR(36), m.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    CONVERT(NVARCHAR(36), v.[PublicId]) AS [ProductVariantPublicId],
    m.[Name],
    m.[ModelTypeCode],
    m.[SourceTypeCode],
    m.[StatusCode],
    m.[UploadStatusCode],
    m.[ScanStatusCode],
    m.[PublicUrl],
    m.[PreviewImagePublicUrl],
    m.[PreviewImageStoragePath],
    m.[StoragePath],
    m.[StorageContainer],
    m.[MimeType],
    m.[FileExtension],
    m.[FileSizeBytes],
    m.[OriginalFileName],
    m.[ViewerConfigurationJson],
    m.[GenerationMetadataJson],
    m.[MaterialOverridesJson],
    m.[IsPrimary],
    m.[IsActive],
    m.[DisplayOrder],
    CONVERT(NVARCHAR(33), m.[PublishedAtUtc], 126) AS [PublishedAtUtc],
    CONVERT(VARBINARY(8), m.[RowVersion]) AS [RowVersion],
    CONVERT(NVARCHAR(33), m.[CreatedAtUtc], 126) AS [CreatedAtUtc],
    CONVERT(NVARCHAR(33), m.[UpdatedAtUtc], 126) AS [UpdatedAtUtc]
SQL;

    private const SELECT_COLUMNS_PHASE2D = <<<'SQL'
    ,
    m.[AnalysisJson],
    m.[LatestOptimisationMetadataJson],
    CONVERT(NVARCHAR(36), aa.[PublicId]) AS [ActiveAssetPublicId]
SQL;

    /** @var bool|null Cached Phase 2D schema presence for this process. */
    private static ?bool $phase2dSchemaReady = null;

    public function __construct(
        private readonly SqlConnection $connection,
    ) {
    }

    private function phase2dSchemaReady(): bool
    {
        if (self::$phase2dSchemaReady !== null) {
            return self::$phase2dSchemaReady;
        }

        try {
            $rows = $this->connection->fetchAll(
                <<<'SQL'
SELECT CASE
    WHEN COL_LENGTH(N'product.Product3DModels', N'AnalysisJson') IS NOT NULL
     AND OBJECT_ID(N'product.Product3DModelAssets', N'U') IS NOT NULL
    THEN 1 ELSE 0 END AS [Ready]
SQL
            );
            self::$phase2dSchemaReady = ((int) ($rows[0]['Ready'] ?? 0)) === 1;
        } catch (\Throwable) {
            self::$phase2dSchemaReady = false;
        }

        return self::$phase2dSchemaReady;
    }

    /**
     * @return array{0: string, 1: string} [selectColumns, assetJoinSql]
     */
    private function selectSqlParts(): array
    {
        if ($this->phase2dSchemaReady()) {
            return [
                self::SELECT_COLUMNS_BASE . self::SELECT_COLUMNS_PHASE2D,
                'LEFT JOIN [product].[Product3DModelAssets] aa ON aa.[Product3DModelAssetId] = m.[ActiveAssetId]',
            ];
        }

        return [
            self::SELECT_COLUMNS_BASE . <<<'SQL'
    ,
    CAST(NULL AS NVARCHAR(MAX)) AS [AnalysisJson],
    CAST(NULL AS NVARCHAR(MAX)) AS [LatestOptimisationMetadataJson],
    CAST(NULL AS NVARCHAR(36)) AS [ActiveAssetPublicId]
SQL,
            '',
        ];
    }

    public function findByPublicId(string $modelPublicId): ?Product3DModelSummary
    {
        return $this->findByPublicIdInternal($modelPublicId, false);
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        [$columns, $assetJoin] = $this->selectSqlParts();
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
{$columns}
FROM [product].[Product3DModels] m
INNER JOIN [product].[Products] p ON p.[ProductId] = m.[ProductId]
LEFT JOIN [product].[ProductVariants] v ON v.[ProductVariantId] = m.[ProductVariantId]
{$assetJoin}
WHERE p.[PublicId] = :productPublicId
  AND m.[DeletedAtUtc] IS NULL
ORDER BY m.[DisplayOrder], m.[Name]
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        return array_map(fn (array $row): Product3DModelSummary => $this->hydrate($row), $rows);
    }

    public function listPublicByProduct(ProductPublicId $productPublicId): ProductPublic3DPayload
    {
        [$columns, $assetJoin] = $this->selectSqlParts();
        $rows = $this->connection->fetchAll(
            <<<SQL
/*q:cv.3d*/
SELECT
{$columns}
FROM [product].[Product3DModels] m
INNER JOIN [product].[Products] p ON p.[ProductId] = m.[ProductId]
LEFT JOIN [product].[ProductVariants] v ON v.[ProductVariantId] = m.[ProductVariantId]
{$assetJoin}
WHERE p.[PublicId] = :productPublicId
  AND m.[DeletedAtUtc] IS NULL
  AND m.[IsActive] = 1
  AND m.[StatusCode] = N'published'
  AND m.[UploadStatusCode] = N'active'
  AND m.[PublicUrl] IS NOT NULL
  AND LTRIM(RTRIM(m.[PublicUrl])) <> N''
  AND m.[ScanStatusCode] IN (N'clean', N'not_required', N'skipped')
ORDER BY m.[IsPrimary] DESC, m.[DisplayOrder], m.[Name]
SQL,
            [':productPublicId' => $productPublicId->toString()],
        );

        $models = [];
        foreach ($rows as $row) {
            $summary = $this->hydrate($row);
            if ($summary->publicUrl === null || $summary->publicUrl === '') {
                continue;
            }
            $models[] = new ProductPublic3DModel(
                publicId: $summary->publicId,
                name: $summary->name,
                modelType: $summary->modelTypeCode,
                url: $summary->publicUrl,
                previewImageUrl: $summary->previewImagePublicUrl,
                viewerConfiguration: $summary->viewerConfiguration,
                isPrimary: $summary->isPrimary,
                productVariantPublicId: $summary->productVariantPublicId,
                storageContainer: $summary->storageContainer,
                storagePath: $summary->storagePath,
                previewImageStoragePath: $summary->previewImageStoragePath,
                representation: (new Product3DPublicRepresentationProjector())->project($summary->generationMetadata, $summary->productVariantPublicId === null),
            );
        }

        if ($models === []) {
            return ProductPublic3DPayload::empty();
        }

        // Default public primary: product-level published primary, then any product-level, then first.
        $primary = null;
        foreach ($models as $model) {
            if ($model->productVariantPublicId === null && $model->isPrimary) {
                $primary = $model;
                break;
            }
        }
        if ($primary === null) {
            foreach ($models as $model) {
                if ($model->productVariantPublicId === null) {
                    $primary = $model;
                    break;
                }
            }
        }
        $primary ??= $models[0];

        return new ProductPublic3DPayload(available: true, primary: $primary, models: $models);
    }

    public function create(ProductPublicId $productPublicId, array $data, UserId $actorUserId): Product3DModelSummary
    {
        return $this->runInTransaction(
            $this->connection,
            function () use ($productPublicId, $data, $actorUserId): Product3DModelSummary {
                $productId = $this->requireProductId($productPublicId);
                $variantId = $this->resolveVariantId($data['productVariantPublicId'] ?? null);
                $publicId = $data['publicId'] ?? null;
                $isPrimary = (bool) ($data['isPrimary'] ?? false);
                if ($isPrimary) {
                    $this->clearPrimary($productId, $variantId, is_string($publicId) ? $publicId : null);
                }

                $validator = new Product3DViewerConfigurationValidator();
                $viewer = $validator->validate($data['viewerConfigurationJson'] ?? '{}');

                $rows = $this->connection->fetchAll(
                    <<<'SQL'
INSERT INTO [product].[Product3DModels] (
    [PublicId],
    [ProductId],
    [ProductVariantId],
    [Name],
    [ModelTypeCode],
    [SourceTypeCode],
    [StorageContainer],
    [StoragePath],
    [MimeType],
    [FileExtension],
    [FileSizeBytes],
    [OriginalFileName],
    [FileName],
    [ViewerConfigurationJson],
    [GenerationMetadataJson],
    [StatusCode],
    [UploadStatusCode],
    [ScanStatusCode],
    [IsPrimary],
    [DisplayOrder],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    CONVERT(UNIQUEIDENTIFIER, :publicId),
    :productId,
    :productVariantId,
    :name,
    :modelTypeCode,
    :sourceTypeCode,
    :storageContainer,
    :storagePath,
    :mimeType,
    :fileExtension,
    :fileSizeBytes,
    :originalFileName,
    :fileName,
    :viewerConfigurationJson,
    :generationMetadataJson,
    :statusCode,
    :uploadStatusCode,
    :scanStatusCode,
    :isPrimary,
    :displayOrder,
    :createdByUserId,
    :updatedByUserId
)
SQL,
                    [
                        ':publicId' => $publicId,
                        ':productId' => $productId,
                        ':productVariantId' => $variantId,
                        ':name' => $data['name'],
                        ':modelTypeCode' => $data['modelTypeCode'],
                        ':sourceTypeCode' => $data['sourceTypeCode'],
                        ':storageContainer' => $data['storageContainer'] ?? null,
                        ':storagePath' => $data['storagePath'] ?? null,
                        ':mimeType' => $data['mimeType'] ?? null,
                        ':fileExtension' => $data['fileExtension'] ?? null,
                        ':fileSizeBytes' => $data['fileSizeBytes'] ?? null,
                        ':originalFileName' => $data['originalFileName'] ?? null,
                        ':fileName' => $data['fileName'] ?? ($data['originalFileName'] ?? null),
                        ':viewerConfigurationJson' => $viewer['json'],
                        ':generationMetadataJson' => $data['generationMetadataJson'] ?? null,
                        ':statusCode' => $data['statusCode'],
                        ':uploadStatusCode' => $data['uploadStatusCode'],
                        ':scanStatusCode' => $data['scanStatusCode'],
                        ':isPrimary' => $isPrimary ? 1 : 0,
                        ':displayOrder' => (int) ($data['displayOrder'] ?? 0),
                        ':createdByUserId' => $actorUserId->toInt(),
                        ':updatedByUserId' => $actorUserId->toInt(),
                    ],
                );

                if ($rows === []) {
                    throw ProductPersistenceException::insertFailed('Product3DModel');
                }

                $loaded = $this->findByPublicId(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
                if ($loaded === null) {
                    throw ProductPersistenceException::insertFailed('Product3DModel');
                }

                return $loaded;
            },
        );
    }

    public function update(
        string $modelPublicId,
        array $data,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        $sets = [
            '[UpdatedAtUtc] = SYSUTCDATETIME()',
            '[UpdatedByUserId] = :updatedByUserId',
        ];
        $params = [
            ':modelPublicId' => $modelPublicId,
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                RowVersionToken::fromBase64($expectedRowVersion),
            ),
        ];

        if (isset($data['name'])) {
            $sets[] = '[Name] = :name';
            $params[':name'] = $data['name'];
        }
        if (isset($data['viewerConfigurationJson'])) {
            $validator = new Product3DViewerConfigurationValidator();
            $viewer = $validator->validate($data['viewerConfigurationJson']);
            $sets[] = '[ViewerConfigurationJson] = :viewerConfigurationJson';
            $params[':viewerConfigurationJson'] = $viewer['json'];
        }
        if (array_key_exists('materialOverridesJson', $data)) {
            $sets[] = '[MaterialOverridesJson] = :materialOverridesJson';
            $params[':materialOverridesJson'] = $data['materialOverridesJson'];
        }
        if (array_key_exists('previewImagePublicUrl', $data)) {
            $sets[] = '[PreviewImagePublicUrl] = :previewImagePublicUrl';
            $params[':previewImagePublicUrl'] = $data['previewImagePublicUrl'];
        }
        if (array_key_exists('previewImageStoragePath', $data)) {
            $sets[] = '[PreviewImageStoragePath] = :previewImageStoragePath';
            $params[':previewImageStoragePath'] = $data['previewImageStoragePath'];
        }
        if (isset($data['displayOrder'])) {
            $sets[] = '[DisplayOrder] = :displayOrder';
            $params[':displayOrder'] = (int) $data['displayOrder'];
        }
        if (array_key_exists('productVariantPublicId', $data)) {
            $sets[] = '[ProductVariantId] = :productVariantId';
            $params[':productVariantId'] = $this->resolveVariantId($data['productVariantPublicId']);
        }

        return $this->mutate($modelPublicId, $sets, $params);
    }

    public function markUploadCompleted(
        string $modelPublicId,
        array $blobMeta,
        UserId $actorUserId,
    ): Product3DModelSummary {
        $this->connection->execute(
            <<<'SQL'
UPDATE [product].[Product3DModels]
SET
    [UploadStatusCode] = :uploadStatusCode,
    [ScanStatusCode] = :scanStatusCode,
    [StatusCode] = :statusCode,
    [PublicUrl] = COALESCE(:publicUrl, [PublicUrl]),
    [MimeType] = COALESCE(:mimeType, [MimeType]),
    [FileSizeBytes] = COALESCE(:fileSizeBytes, [FileSizeBytes]),
    [UploadCompletedAtUtc] = SYSUTCDATETIME(),
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [PublicId] = :modelPublicId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':uploadStatusCode' => $blobMeta['uploadStatusCode'],
                ':scanStatusCode' => $blobMeta['scanStatusCode'],
                ':statusCode' => $blobMeta['statusCode'],
                ':publicUrl' => $blobMeta['publicUrl'] ?? null,
                ':mimeType' => $blobMeta['mimeType'] ?? null,
                ':fileSizeBytes' => $blobMeta['fileSizeBytes'] ?? null,
                ':updatedByUserId' => $actorUserId->toInt(),
                ':modelPublicId' => $modelPublicId,
            ],
        );

        $loaded = $this->findByPublicId($modelPublicId);
        if ($loaded === null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }

        return $loaded;
    }

    public function markUploadFailed(
        string $modelPublicId,
        UserId $actorUserId,
    ): Product3DModelSummary {
        $this->connection->execute(
            <<<'SQL'
UPDATE [product].[Product3DModels]
SET
    [UploadStatusCode] = N'failed',
    [StatusCode] = N'failed',
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [PublicId] = :modelPublicId
  AND [DeletedAtUtc] IS NULL
SQL,
            [
                ':updatedByUserId' => $actorUserId->toInt(),
                ':modelPublicId' => $modelPublicId,
            ],
        );

        $loaded = $this->findByPublicId($modelPublicId);
        if ($loaded === null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }

        return $loaded;
    }

    public function publish(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        return $this->mutate($modelPublicId, [
            '[StatusCode] = N\'published\'',
            '[PublishedAtUtc] = SYSUTCDATETIME()',
            '[PublishedByUserId] = :publishedByUserId',
            '[UpdatedAtUtc] = SYSUTCDATETIME()',
            '[UpdatedByUserId] = :updatedByUserId',
        ], [
            ':modelPublicId' => $modelPublicId,
            ':publishedByUserId' => $actorUserId->toInt(),
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                RowVersionToken::fromBase64($expectedRowVersion),
            ),
        ]);
    }

    public function unpublish(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        return $this->mutate($modelPublicId, [
            '[StatusCode] = N\'ready\'',
            '[PublishedAtUtc] = NULL',
            '[PublishedByUserId] = NULL',
            '[UpdatedAtUtc] = SYSUTCDATETIME()',
            '[UpdatedByUserId] = :updatedByUserId',
        ], [
            ':modelPublicId' => $modelPublicId,
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                RowVersionToken::fromBase64($expectedRowVersion),
            ),
        ]);
    }

    public function setPrimary(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        return $this->runInTransaction(
            $this->connection,
            function () use ($modelPublicId, $expectedRowVersion, $actorUserId): Product3DModelSummary {
                $existing = $this->findByPublicId($modelPublicId);
                if ($existing === null) {
                    throw Product3DModelNotFound::forPublicId($modelPublicId);
                }
                $productId = $this->requireProductId(ProductPublicId::fromString($existing->productPublicId));
                $variantId = $this->resolveVariantId($existing->productVariantPublicId);
                $this->clearPrimary($productId, $variantId, $modelPublicId);

                return $this->mutate($modelPublicId, [
                    '[IsPrimary] = 1',
                    '[UpdatedAtUtc] = SYSUTCDATETIME()',
                    '[UpdatedByUserId] = :updatedByUserId',
                ], [
                    ':modelPublicId' => $modelPublicId,
                    ':updatedByUserId' => $actorUserId->toInt(),
                    ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                        RowVersionToken::fromBase64($expectedRowVersion),
                    ),
                ]);
            },
        );
    }

    public function softDelete(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        $params = [
            ':modelPublicId' => $modelPublicId,
            ':deletedByUserId' => $actorUserId->toInt(),
            ':updatedByUserId' => $actorUserId->toInt(),
            ':expectedRowVersion' => ProductRowVersionMapper::toSqlBinary(
                RowVersionToken::fromBase64($expectedRowVersion),
            ),
        ];
        $rows = $this->connection->fetchAll(
            <<<'SQL'
UPDATE [product].[Product3DModels]
SET
    [DeletedAtUtc] = SYSUTCDATETIME(),
    [DeletedByUserId] = :deletedByUserId,
    [IsActive] = 0,
    [IsPrimary] = 0,
    [StatusCode] = N'archived',
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
WHERE [PublicId] = :modelPublicId
  AND [DeletedAtUtc] IS NULL
  AND [RowVersion] = :expectedRowVersion
SQL,
            $params,
        );
        if ($rows === []) {
            $this->assertExistsForMutation($modelPublicId);
            throw ProductConcurrencyConflict::forPublicId($modelPublicId);
        }

        $loaded = $this->findByPublicIdInternal($modelPublicId, true);
        if ($loaded === null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }

        return $loaded;
    }

    /**
     * @param list<string> $sets
     * @param array<string, mixed> $params
     */
    private function mutate(string $modelPublicId, array $sets, array $params): Product3DModelSummary
    {
        $sql = 'UPDATE [product].[Product3DModels] SET ' . implode(', ', $sets)
            . ' OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]'
            . ' WHERE [PublicId] = :modelPublicId AND [DeletedAtUtc] IS NULL AND [RowVersion] = :expectedRowVersion';
        $rows = $this->connection->fetchAll($sql, $params);
        if ($rows === []) {
            $this->assertExistsForMutation($modelPublicId);
            throw ProductConcurrencyConflict::forPublicId($modelPublicId);
        }

        $loaded = $this->findByPublicId($modelPublicId);
        if ($loaded === null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }

        return $loaded;
    }

    private function clearPrimary(int $productId, ?int $variantId, ?string $exceptModelPublicId = null): void
    {
        $exceptClause = '';
        $params = [':productId' => $productId];
        if ($exceptModelPublicId !== null && $exceptModelPublicId !== '') {
            $exceptClause = ' AND [PublicId] <> CONVERT(UNIQUEIDENTIFIER, :exceptPublicId)';
            $params[':exceptPublicId'] = $exceptModelPublicId;
        }

        if ($variantId === null) {
            $this->connection->execute(
                <<<SQL
UPDATE [product].[Product3DModels]
SET [IsPrimary] = 0,
    [UpdatedAtUtc] = SYSUTCDATETIME()
WHERE [ProductId] = :productId
  AND [ProductVariantId] IS NULL
  AND [IsPrimary] = 1
  AND [DeletedAtUtc] IS NULL
{$exceptClause}
SQL,
                $params,
            );

            return;
        }

        $params[':productVariantId'] = $variantId;
        $this->connection->execute(
            <<<SQL
UPDATE [product].[Product3DModels]
SET [IsPrimary] = 0,
    [UpdatedAtUtc] = SYSUTCDATETIME()
WHERE [ProductId] = :productId
  AND [ProductVariantId] = :productVariantId
  AND [IsPrimary] = 1
  AND [DeletedAtUtc] IS NULL
{$exceptClause}
SQL,
            $params,
        );
    }

    private function findByPublicIdInternal(string $modelPublicId, bool $includeDeleted): ?Product3DModelSummary
    {
        [$columns, $assetJoin] = $this->selectSqlParts();
        $deletedClause = $includeDeleted ? '' : ' AND m.[DeletedAtUtc] IS NULL';
        $rows = $this->connection->fetchAll(
            <<<SQL
SELECT
{$columns}
FROM [product].[Product3DModels] m
INNER JOIN [product].[Products] p ON p.[ProductId] = m.[ProductId]
LEFT JOIN [product].[ProductVariants] v ON v.[ProductVariantId] = m.[ProductVariantId]
{$assetJoin}
WHERE m.[PublicId] = :modelPublicId
{$deletedClause}
SQL,
            [':modelPublicId' => $modelPublicId],
        );

        return $rows === [] ? null : $this->hydrate($rows[0]);
    }

    private function assertExistsForMutation(string $modelPublicId): void
    {
        if ($this->findByPublicIdInternal($modelPublicId, true) === null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Product3DModelSummary
    {
        $viewerRaw = AzureSqlValueMapper::nullableString($row['ViewerConfigurationJson'] ?? null) ?? '{}';
        $validator = new Product3DViewerConfigurationValidator();
        $viewer = $validator->validate($viewerRaw);

        $generation = $this->decodeJsonObject($row['GenerationMetadataJson'] ?? null);
        $materials = $this->decodeJsonList($row['MaterialOverridesJson'] ?? null);
        $analysis = $this->decodeJsonObject($row['AnalysisJson'] ?? null);
        $optimisation = $this->decodeJsonObject($row['LatestOptimisationMetadataJson'] ?? null);

        return new Product3DModelSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            productPublicId: AzureSqlValueMapper::stringValue($row['ProductPublicId'] ?? ''),
            productVariantPublicId: AzureSqlValueMapper::nullableString($row['ProductVariantPublicId'] ?? null),
            name: AzureSqlValueMapper::stringValue($row['Name'] ?? ''),
            modelTypeCode: AzureSqlValueMapper::stringValue($row['ModelTypeCode'] ?? ''),
            sourceTypeCode: AzureSqlValueMapper::stringValue($row['SourceTypeCode'] ?? ''),
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? 'draft'),
            uploadStatusCode: AzureSqlValueMapper::stringValue($row['UploadStatusCode'] ?? 'pending_upload'),
            scanStatusCode: AzureSqlValueMapper::stringValue($row['ScanStatusCode'] ?? 'pending_upload'),
            publicUrl: AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
            previewImagePublicUrl: AzureSqlValueMapper::nullableString($row['PreviewImagePublicUrl'] ?? null),
            previewImageStoragePath: AzureSqlValueMapper::nullableString($row['PreviewImageStoragePath'] ?? null),
            storagePath: AzureSqlValueMapper::nullableString($row['StoragePath'] ?? null),
            storageContainer: AzureSqlValueMapper::nullableString($row['StorageContainer'] ?? null),
            mimeType: AzureSqlValueMapper::nullableString($row['MimeType'] ?? null),
            fileExtension: AzureSqlValueMapper::nullableString($row['FileExtension'] ?? null),
            fileSizeBytes: AzureSqlValueMapper::nullableInt($row['FileSizeBytes'] ?? null),
            originalFileName: AzureSqlValueMapper::nullableString($row['OriginalFileName'] ?? null),
            viewerConfiguration: $viewer['data'],
            generationMetadata: $generation,
            materialOverrides: $materials,
            isPrimary: AzureSqlValueMapper::boolValue($row['IsPrimary'] ?? false),
            isActive: AzureSqlValueMapper::boolValue($row['IsActive'] ?? true),
            displayOrder: AzureSqlValueMapper::intValue($row['DisplayOrder'] ?? 0),
            publishedAtUtc: AzureSqlValueMapper::nullableString($row['PublishedAtUtc'] ?? null),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            createdAtUtc: AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
            updatedAtUtc: AzureSqlValueMapper::stringValue($row['UpdatedAtUtc'] ?? ''),
            analysis: $analysis,
            optimisation: $optimisation,
            activeAsset: null,
            assets: null,
            activeAssetPublicId: AzureSqlValueMapper::nullableString($row['ActiveAssetPublicId'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJsonObject(mixed $value): ?array
    {
        $raw = AzureSqlValueMapper::nullableString($value);
        if ($raw === null) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function decodeJsonList(mixed $value): ?array
    {
        $decoded = $this->decodeJsonObject($value);
        if ($decoded === null) {
            return null;
        }
        $out = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            // JSON object keys are strings; PHP turns numeric ones into ints.
            $row = [];
            foreach ($item as $key => $field) {
                $row[(string) $key] = $field;
            }
            $out[] = $row;
        }

        return $out;
    }

    private function requireProductId(ProductPublicId $publicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [ProductId] FROM [product].[Products] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $publicId->toString()],
        );
        if ($rows === []) {
            throw ProductPersistenceException::productNotFound($publicId->toString());
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductId'] ?? 0);
    }

    private function resolveVariantId(mixed $variantPublicId): ?int
    {
        if (!is_string($variantPublicId) || trim($variantPublicId) === '') {
            return null;
        }
        $rows = $this->connection->fetchAll(
            'SELECT [ProductVariantId] FROM [product].[ProductVariants] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $variantPublicId],
        );
        if ($rows === []) {
            throw ProductPersistenceException::variantNotFound($variantPublicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['ProductVariantId'] ?? 0);
    }
}
