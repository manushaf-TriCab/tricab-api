<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence;

use TriCab\Api\Identity\Persistence\AzureSqlTransaction;
use TriCab\Api\Identity\Persistence\AzureSqlValueMapper;
use TriCab\Api\Infrastructure\Database\SqlConnection;
use TriCab\Core\Identity\Domain\Service\UuidV4Generator;
use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\Product3DAssetProcessingJobSummary;
use TriCab\Core\Product\Application\DTO\Product3DAssetSummary;
use TriCab\Core\Product\Application\DTO\Product3DModelSummary;
use TriCab\Core\Product\Application\Exception\Product3DAssetNotFound;
use TriCab\Core\Product\Application\Exception\Product3DAssetProcessingException;
use TriCab\Core\Product\Application\Exception\Product3DModelNotFound;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Contract\Product3DAssetRepository;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;
use TriCab\Core\Product\Domain\ValueObject\RowVersionToken;

final class AzureSqlProduct3DAssetRepository implements Product3DAssetRepository
{
    use AzureSqlTransaction;

    public function __construct(
        private readonly SqlConnection $connection,
        private readonly Product3DModelRepository $models,
    ) {
    }

    public function listAssetsByModel(string $modelPublicId): array
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), a.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), m.[PublicId]) AS [ModelPublicId],
    a.[AssetTypeCode],
    a.[StorageContainer],
    a.[StoragePath],
    a.[PublicUrl],
    a.[MimeType],
    a.[FileSizeBytes],
    a.[FileExtension],
    a.[OriginalFileName],
    a.[Sha256],
    a.[StatusCode],
    a.[ScanStatusCode],
    a.[OptimisationProfile],
    a.[AnalysisJson],
    a.[OptimisationMetadataJson],
    CONVERT(VARBINARY(8), a.[RowVersion]) AS [RowVersion],
    CONVERT(NVARCHAR(33), a.[CreatedAtUtc], 126) AS [CreatedAtUtc],
    CONVERT(NVARCHAR(33), a.[UpdatedAtUtc], 126) AS [UpdatedAtUtc]
FROM [product].[Product3DModelAssets] a
INNER JOIN [product].[Product3DModels] m ON m.[Product3DModelId] = a.[Product3DModelId]
WHERE m.[PublicId] = :modelPublicId
  AND a.[DeletedAtUtc] IS NULL
ORDER BY a.[CreatedAtUtc]
SQL,
            [':modelPublicId' => $modelPublicId],
        );

        return array_map(fn (array $row): Product3DAssetSummary => $this->hydrateAsset($row), $rows);
    }

    public function findAssetByPublicId(string $assetPublicId): ?Product3DAssetSummary
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), a.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), m.[PublicId]) AS [ModelPublicId],
    a.[AssetTypeCode],
    a.[StorageContainer],
    a.[StoragePath],
    a.[PublicUrl],
    a.[MimeType],
    a.[FileSizeBytes],
    a.[FileExtension],
    a.[OriginalFileName],
    a.[Sha256],
    a.[StatusCode],
    a.[ScanStatusCode],
    a.[OptimisationProfile],
    a.[AnalysisJson],
    a.[OptimisationMetadataJson],
    CONVERT(VARBINARY(8), a.[RowVersion]) AS [RowVersion],
    CONVERT(NVARCHAR(33), a.[CreatedAtUtc], 126) AS [CreatedAtUtc],
    CONVERT(NVARCHAR(33), a.[UpdatedAtUtc], 126) AS [UpdatedAtUtc]
FROM [product].[Product3DModelAssets] a
INNER JOIN [product].[Product3DModels] m ON m.[Product3DModelId] = a.[Product3DModelId]
WHERE a.[PublicId] = :assetPublicId
  AND a.[DeletedAtUtc] IS NULL
SQL,
            [':assetPublicId' => $assetPublicId],
        );

        return $rows === [] ? null : $this->hydrateAsset($rows[0]);
    }

    public function findSourceAsset(string $modelPublicId): ?Product3DAssetSummary
    {
        foreach ($this->listAssetsByModel($modelPublicId) as $asset) {
            if ($asset->assetTypeCode === 'source') {
                return $asset;
            }
        }

        return null;
    }

    public function ensureSourceAsset(Product3DModelSummary $model, UserId $actorUserId): Product3DAssetSummary
    {
        $existing = $this->findSourceAsset($model->publicId);
        if ($existing !== null) {
            return $existing;
        }
        if ($model->storagePath === null || $model->storageContainer === null) {
            throw Product3DAssetProcessingException::assetNotReady('Cannot register SOURCE asset without storage path.');
        }

        return $this->createAsset($model->publicId, [
            'assetTypeCode' => 'source',
            'storageContainer' => $model->storageContainer,
            'storagePath' => $model->storagePath,
            'publicUrl' => $model->publicUrl,
            'mimeType' => $model->mimeType,
            'fileSizeBytes' => $model->fileSizeBytes,
            'fileExtension' => $model->fileExtension,
            'originalFileName' => $model->originalFileName,
            'statusCode' => 'ready',
            'scanStatusCode' => $model->scanStatusCode,
        ], $actorUserId);
    }

    public function createAsset(string $modelPublicId, array $data, UserId $actorUserId): Product3DAssetSummary
    {
        $modelId = $this->requireModelId($modelPublicId);
        $publicId = isset($data['publicId']) && is_string($data['publicId']) && $data['publicId'] !== ''
            ? $data['publicId']
            : (new UuidV4Generator())->generate()->toString();
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[Product3DModelAssets] (
    [PublicId],
    [Product3DModelId],
    [AssetTypeCode],
    [StorageContainer],
    [StoragePath],
    [PublicUrl],
    [MimeType],
    [FileSizeBytes],
    [FileExtension],
    [OriginalFileName],
    [Sha256],
    [StatusCode],
    [ScanStatusCode],
    [OptimisationProfile],
    [AnalysisJson],
    [OptimisationMetadataJson],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    CONVERT(UNIQUEIDENTIFIER, :publicId),
    :modelId,
    :assetTypeCode,
    :storageContainer,
    :storagePath,
    :publicUrl,
    :mimeType,
    :fileSizeBytes,
    :fileExtension,
    :originalFileName,
    :sha256,
    :statusCode,
    :scanStatusCode,
    :optimisationProfile,
    :analysisJson,
    :optimisationMetadataJson,
    :createdByUserId,
    :updatedByUserId
)
SQL,
            [
                ':publicId' => $publicId,
                ':modelId' => $modelId,
                ':assetTypeCode' => $data['assetTypeCode'],
                ':storageContainer' => $data['storageContainer'] ?? null,
                ':storagePath' => $data['storagePath'] ?? null,
                ':publicUrl' => $data['publicUrl'] ?? null,
                ':mimeType' => $data['mimeType'] ?? null,
                ':fileSizeBytes' => $data['fileSizeBytes'] ?? null,
                ':fileExtension' => $data['fileExtension'] ?? null,
                ':originalFileName' => $data['originalFileName'] ?? null,
                ':sha256' => $data['sha256'] ?? null,
                ':statusCode' => $data['statusCode'],
                ':scanStatusCode' => $data['scanStatusCode'],
                ':optimisationProfile' => $data['optimisationProfile'] ?? null,
                ':analysisJson' => $data['analysisJson'] ?? null,
                ':optimisationMetadataJson' => $data['optimisationMetadataJson'] ?? null,
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('Product3DModelAsset');
        }
        $created = $this->findAssetByPublicId(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
        if ($created === null) {
            throw ProductPersistenceException::insertFailed('Product3DModelAsset');
        }

        return $created;
    }

    public function saveModelAnalysis(
        string $modelPublicId,
        array $analysis,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        $this->mutateModel($modelPublicId, $expectedRowVersion, $actorUserId, [
            '[AnalysisJson] = :analysisJson',
        ], [
            ':analysisJson' => json_encode($analysis, JSON_THROW_ON_ERROR),
        ]);

        return $this->requireModel($modelPublicId);
    }

    public function saveModelOptimisationMetadata(
        string $modelPublicId,
        ?array $optimisationMetadata,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        $this->mutateModel($modelPublicId, $expectedRowVersion, $actorUserId, [
            '[LatestOptimisationMetadataJson] = :metaJson',
        ], [
            ':metaJson' => $optimisationMetadata === null
                ? null
                : json_encode($optimisationMetadata, JSON_THROW_ON_ERROR),
        ]);

        return $this->requireModel($modelPublicId);
    }

    public function activateAsset(
        string $modelPublicId,
        string $assetPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        return $this->runInTransaction($this->connection, function () use (
            $modelPublicId,
            $assetPublicId,
            $expectedRowVersion,
            $actorUserId,
        ): Product3DModelSummary {
            $asset = $this->findAssetByPublicId($assetPublicId);
            if ($asset === null || $asset->modelPublicId !== $modelPublicId) {
                throw Product3DAssetNotFound::forPublicId($assetPublicId);
            }
            $modelId = $this->requireModelId($modelPublicId);
            $assetId = $this->requireAssetId($assetPublicId);

            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[Product3DModelAssets]
SET [StatusCode] = CASE WHEN [AssetTypeCode] = N'source' THEN N'ready' ELSE N'superseded' END,
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [Product3DModelId] = :modelId
  AND [StatusCode] = N'active'
  AND [DeletedAtUtc] IS NULL
SQL,
                [':modelId' => $modelId, ':updatedByUserId' => $actorUserId->toInt()],
            );
            $this->connection->execute(
                <<<'SQL'
UPDATE [product].[Product3DModelAssets]
SET [StatusCode] = N'active',
    [UpdatedAtUtc] = SYSUTCDATETIME(),
    [UpdatedByUserId] = :updatedByUserId
WHERE [Product3DModelAssetId] = :assetId
SQL,
                [':assetId' => $assetId, ':updatedByUserId' => $actorUserId->toInt()],
            );

            $this->mutateModel($modelPublicId, $expectedRowVersion, $actorUserId, [
                '[ActiveAssetId] = :activeAssetId',
                '[StoragePath] = :storagePath',
                '[StorageContainer] = :storageContainer',
                '[PublicUrl] = :publicUrl',
                '[FileSizeBytes] = :fileSizeBytes',
                '[MimeType] = COALESCE(:mimeType, [MimeType])',
                '[FileExtension] = COALESCE(:fileExtension, [FileExtension])',
            ], [
                ':activeAssetId' => $assetId,
                ':storagePath' => $asset->storagePath,
                ':storageContainer' => $asset->storageContainer,
                ':publicUrl' => $asset->publicUrl,
                ':fileSizeBytes' => $asset->fileSizeBytes,
                ':mimeType' => $asset->mimeType,
                ':fileExtension' => $asset->fileExtension,
            ]);

            return $this->requireModel($modelPublicId);
        });
    }

    public function revertToSource(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        $source = $this->findSourceAsset($modelPublicId);
        if ($source === null) {
            $model = $this->requireModel($modelPublicId);
            $source = $this->ensureSourceAsset($model, $actorUserId);
        }

        return $this->activateAsset($modelPublicId, $source->publicId, $expectedRowVersion, $actorUserId);
    }

    public function createJob(array $data, UserId $actorUserId): Product3DAssetProcessingJobSummary
    {
        $productId = $this->requireProductId(ProductPublicId::fromString($data['productPublicId']));
        $modelId = $this->requireModelId($data['modelPublicId']);
        $assetId = null;
        if (isset($data['assetPublicId']) && is_string($data['assetPublicId']) && $data['assetPublicId'] !== '') {
            $assetId = $this->requireAssetId($data['assetPublicId']);
        }
        $publicId = isset($data['publicId']) && is_string($data['publicId']) && $data['publicId'] !== ''
            ? $data['publicId']
            : (new UuidV4Generator())->generate()->toString();
        $rows = $this->connection->fetchAll(
            <<<'SQL'
INSERT INTO [product].[Product3DAssetProcessingJobs] (
    [PublicId],
    [ProductId],
    [Product3DModelId],
    [Product3DModelAssetId],
    [OperationTypeCode],
    [ProfileCode],
    [StatusCode],
    [InputJson],
    [CreatedByUserId],
    [UpdatedByUserId]
)
OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]
VALUES (
    CONVERT(UNIQUEIDENTIFIER, :publicId),
    :productId,
    :modelId,
    :assetId,
    :operationTypeCode,
    :profileCode,
    :statusCode,
    :inputJson,
    :createdByUserId,
    :updatedByUserId
)
SQL,
            [
                ':publicId' => $publicId,
                ':productId' => $productId,
                ':modelId' => $modelId,
                ':assetId' => $assetId,
                ':operationTypeCode' => $data['operationTypeCode'],
                ':profileCode' => $data['profileCode'] ?? null,
                ':statusCode' => $data['statusCode'],
                ':inputJson' => $data['inputJson'],
                ':createdByUserId' => $actorUserId->toInt(),
                ':updatedByUserId' => $actorUserId->toInt(),
            ],
        );
        if ($rows === []) {
            throw ProductPersistenceException::insertFailed('Product3DAssetProcessingJob');
        }
        $job = $this->findJobByPublicId(AzureSqlValueMapper::stringValue($rows[0]['PublicId'] ?? ''));
        if ($job === null) {
            throw ProductPersistenceException::insertFailed('Product3DAssetProcessingJob');
        }

        return $job;
    }

    public function updateJob(string $jobPublicId, array $data): Product3DAssetProcessingJobSummary
    {
        $sets = ['[UpdatedAtUtc] = SYSUTCDATETIME()'];
        $params = [':jobPublicId' => $jobPublicId];
        if (isset($data['statusCode'])) {
            $sets[] = '[StatusCode] = :statusCode';
            $params[':statusCode'] = $data['statusCode'];
        }
        if (array_key_exists('outputJson', $data)) {
            $sets[] = '[OutputJson] = :outputJson';
            $params[':outputJson'] = $data['outputJson'];
        }
        if (array_key_exists('errorCode', $data)) {
            $sets[] = '[ErrorCode] = :errorCode';
            $params[':errorCode'] = $data['errorCode'];
        }
        if (array_key_exists('errorMessage', $data)) {
            $sets[] = '[ErrorMessage] = :errorMessage';
            $params[':errorMessage'] = $data['errorMessage'];
        }
        if (array_key_exists('startedAtUtc', $data) && is_string($data['startedAtUtc'])) {
            $sets[] = '[StartedAtUtc] = CONVERT(DATETIME2(7), :startedAtUtc)';
            $params[':startedAtUtc'] = $data['startedAtUtc'];
        }
        if (array_key_exists('completedAtUtc', $data) && is_string($data['completedAtUtc'])) {
            $sets[] = '[CompletedAtUtc] = CONVERT(DATETIME2(7), :completedAtUtc)';
            $params[':completedAtUtc'] = $data['completedAtUtc'];
        }
        if (array_key_exists('assetPublicId', $data) && is_string($data['assetPublicId']) && $data['assetPublicId'] !== '') {
            $sets[] = '[Product3DModelAssetId] = :assetId';
            $params[':assetId'] = $this->requireAssetId($data['assetPublicId']);
        }
        $this->connection->execute(
            'UPDATE [product].[Product3DAssetProcessingJobs] SET ' . implode(', ', $sets)
            . ' WHERE [PublicId] = :jobPublicId',
            $params,
        );
        $job = $this->findJobByPublicId($jobPublicId);
        if ($job === null) {
            throw Product3DAssetNotFound::forPublicId($jobPublicId);
        }

        return $job;
    }

    public function findJobByPublicId(string $jobPublicId): ?Product3DAssetProcessingJobSummary
    {
        $rows = $this->connection->fetchAll(
            <<<'SQL'
SELECT
    CONVERT(NVARCHAR(36), j.[PublicId]) AS [PublicId],
    CONVERT(NVARCHAR(36), p.[PublicId]) AS [ProductPublicId],
    CONVERT(NVARCHAR(36), m.[PublicId]) AS [ModelPublicId],
    CONVERT(NVARCHAR(36), a.[PublicId]) AS [AssetPublicId],
    j.[OperationTypeCode],
    j.[ProfileCode],
    j.[StatusCode],
    j.[InputJson],
    j.[OutputJson],
    j.[ErrorCode],
    j.[ErrorMessage],
    CONVERT(NVARCHAR(33), j.[StartedAtUtc], 126) AS [StartedAtUtc],
    CONVERT(NVARCHAR(33), j.[CompletedAtUtc], 126) AS [CompletedAtUtc],
    CONVERT(VARBINARY(8), j.[RowVersion]) AS [RowVersion],
    CONVERT(NVARCHAR(33), j.[CreatedAtUtc], 126) AS [CreatedAtUtc]
FROM [product].[Product3DAssetProcessingJobs] j
INNER JOIN [product].[Products] p ON p.[ProductId] = j.[ProductId]
INNER JOIN [product].[Product3DModels] m ON m.[Product3DModelId] = j.[Product3DModelId]
LEFT JOIN [product].[Product3DModelAssets] a ON a.[Product3DModelAssetId] = j.[Product3DModelAssetId]
WHERE j.[PublicId] = :jobPublicId
SQL,
            [':jobPublicId' => $jobPublicId],
        );

        return $rows === [] ? null : $this->hydrateJob($rows[0]);
    }

    /**
     * @param list<string> $sets
     * @param array<string, mixed> $params
     */
    private function mutateModel(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
        array $sets,
        array $params,
    ): void {
        $allSets = array_merge($sets, [
            '[UpdatedAtUtc] = SYSUTCDATETIME()',
            '[UpdatedByUserId] = :updatedByUserId',
        ]);
        $params[':modelPublicId'] = $modelPublicId;
        $params[':updatedByUserId'] = $actorUserId->toInt();
        $params[':expectedRowVersion'] = ProductRowVersionMapper::toSqlBinary(
            RowVersionToken::fromBase64($expectedRowVersion),
        );
        $rows = $this->connection->fetchAll(
            'UPDATE [product].[Product3DModels] SET ' . implode(', ', $allSets)
            . ' OUTPUT CONVERT(NVARCHAR(36), INSERTED.[PublicId]) AS [PublicId]'
            . ' WHERE [PublicId] = :modelPublicId AND [DeletedAtUtc] IS NULL AND [RowVersion] = :expectedRowVersion',
            $params,
        );
        if ($rows === []) {
            if ($this->models->findByPublicId($modelPublicId) === null) {
                throw Product3DModelNotFound::forPublicId($modelPublicId);
            }
            throw ProductConcurrencyConflict::forPublicId($modelPublicId);
        }
    }

    private function requireModel(string $modelPublicId): Product3DModelSummary
    {
        $model = $this->models->findByPublicId($modelPublicId);
        if ($model === null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }

        return $model;
    }

    private function requireModelId(string $modelPublicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [Product3DModelId] FROM [product].[Product3DModels] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $modelPublicId],
        );
        if ($rows === []) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['Product3DModelId'] ?? 0);
    }

    private function requireAssetId(string $assetPublicId): int
    {
        $rows = $this->connection->fetchAll(
            'SELECT [Product3DModelAssetId] FROM [product].[Product3DModelAssets] WHERE [PublicId] = :publicId AND [DeletedAtUtc] IS NULL',
            [':publicId' => $assetPublicId],
        );
        if ($rows === []) {
            throw Product3DAssetNotFound::forPublicId($assetPublicId);
        }

        return AzureSqlValueMapper::intValue($rows[0]['Product3DModelAssetId'] ?? 0);
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

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateAsset(array $row): Product3DAssetSummary
    {
        return new Product3DAssetSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            modelPublicId: AzureSqlValueMapper::stringValue($row['ModelPublicId'] ?? ''),
            assetTypeCode: AzureSqlValueMapper::stringValue($row['AssetTypeCode'] ?? ''),
            storageContainer: AzureSqlValueMapper::nullableString($row['StorageContainer'] ?? null),
            storagePath: AzureSqlValueMapper::nullableString($row['StoragePath'] ?? null),
            publicUrl: AzureSqlValueMapper::nullableString($row['PublicUrl'] ?? null),
            mimeType: AzureSqlValueMapper::nullableString($row['MimeType'] ?? null),
            fileSizeBytes: AzureSqlValueMapper::nullableInt($row['FileSizeBytes'] ?? null),
            fileExtension: AzureSqlValueMapper::nullableString($row['FileExtension'] ?? null),
            originalFileName: AzureSqlValueMapper::nullableString($row['OriginalFileName'] ?? null),
            sha256: AzureSqlValueMapper::nullableString($row['Sha256'] ?? null),
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? 'ready'),
            scanStatusCode: AzureSqlValueMapper::stringValue($row['ScanStatusCode'] ?? 'not_required'),
            optimisationProfile: AzureSqlValueMapper::nullableString($row['OptimisationProfile'] ?? null),
            analysis: $this->decodeJson($row['AnalysisJson'] ?? null),
            optimisationMetadata: $this->decodeJson($row['OptimisationMetadataJson'] ?? null),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            createdAtUtc: AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
            updatedAtUtc: AzureSqlValueMapper::stringValue($row['UpdatedAtUtc'] ?? ''),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrateJob(array $row): Product3DAssetProcessingJobSummary
    {
        return new Product3DAssetProcessingJobSummary(
            publicId: AzureSqlValueMapper::stringValue($row['PublicId'] ?? ''),
            productPublicId: AzureSqlValueMapper::stringValue($row['ProductPublicId'] ?? ''),
            modelPublicId: AzureSqlValueMapper::stringValue($row['ModelPublicId'] ?? ''),
            assetPublicId: AzureSqlValueMapper::nullableString($row['AssetPublicId'] ?? null),
            operationTypeCode: AzureSqlValueMapper::stringValue($row['OperationTypeCode'] ?? ''),
            profileCode: AzureSqlValueMapper::nullableString($row['ProfileCode'] ?? null),
            statusCode: AzureSqlValueMapper::stringValue($row['StatusCode'] ?? 'queued'),
            input: $this->decodeJson($row['InputJson'] ?? null) ?? [],
            output: $this->decodeJson($row['OutputJson'] ?? null),
            errorCode: AzureSqlValueMapper::nullableString($row['ErrorCode'] ?? null),
            errorMessage: AzureSqlValueMapper::nullableString($row['ErrorMessage'] ?? null),
            startedAtUtc: AzureSqlValueMapper::nullableString($row['StartedAtUtc'] ?? null),
            completedAtUtc: AzureSqlValueMapper::nullableString($row['CompletedAtUtc'] ?? null),
            rowVersion: ProductRowVersionMapper::toToken($row['RowVersion'] ?? null)->toBase64(),
            createdAtUtc: AzureSqlValueMapper::stringValue($row['CreatedAtUtc'] ?? ''),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(mixed $value): ?array
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
}
