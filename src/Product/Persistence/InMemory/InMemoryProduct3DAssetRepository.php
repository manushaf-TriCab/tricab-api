<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

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

final class InMemoryProduct3DAssetRepository implements Product3DAssetRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
        private readonly Product3DModelRepository $models,
    ) {
    }

    public function listAssetsByModel(string $modelPublicId): array
    {
        $out = [];
        foreach ($this->store->threeDAssetsMap() as $row) {
            if (($row['modelPublicId'] ?? '') === $modelPublicId && ($row['deletedAtUtc'] ?? null) === null) {
                $out[] = self::toAssetSummary($row);
            }
        }

        return $out;
    }

    public function findAssetByPublicId(string $assetPublicId): ?Product3DAssetSummary
    {
        $row = $this->store->threeDAssetsMap()[$assetPublicId] ?? null;
        if ($row === null || ($row['deletedAtUtc'] ?? null) !== null) {
            return null;
        }

        return self::toAssetSummary($row);
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
        unset($actorUserId);
        if ($this->models->findByPublicId($modelPublicId) === null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
        $publicId = $data['publicId'] ?? $this->store->newPublicId();
        $now = $this->store->nowUtc();
        $row = [
            'publicId' => $publicId,
            'modelPublicId' => $modelPublicId,
            'assetTypeCode' => $data['assetTypeCode'],
            'storageContainer' => $data['storageContainer'] ?? null,
            'storagePath' => $data['storagePath'] ?? null,
            'publicUrl' => $data['publicUrl'] ?? null,
            'mimeType' => $data['mimeType'] ?? null,
            'fileSizeBytes' => $data['fileSizeBytes'] ?? null,
            'fileExtension' => $data['fileExtension'] ?? null,
            'originalFileName' => $data['originalFileName'] ?? null,
            'sha256' => $data['sha256'] ?? null,
            'statusCode' => $data['statusCode'],
            'scanStatusCode' => $data['scanStatusCode'],
            'optimisationProfile' => $data['optimisationProfile'] ?? null,
            'analysis' => isset($data['analysisJson']) && is_string($data['analysisJson']) && $data['analysisJson'] !== ''
                ? json_decode($data['analysisJson'], true)
                : null,
            'optimisationMetadata' => isset($data['optimisationMetadataJson']) && is_string($data['optimisationMetadataJson']) && $data['optimisationMetadataJson'] !== ''
                ? json_decode($data['optimisationMetadataJson'], true)
                : null,
            'rowVersion' => $this->store->nextRowVersion()->toBase64(),
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
            'deletedAtUtc' => null,
        ];
        $map = $this->store->threeDAssetsMap();
        $map[$publicId] = $row;
        $this->store->setThreeDAssetsMap($map);

        return self::toAssetSummary($row);
    }

    public function saveModelAnalysis(
        string $modelPublicId,
        array $analysis,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        unset($actorUserId);
        $map = $this->store->threeDModelsMap();
        $row = $map[$modelPublicId] ?? null;
        if ($row === null || ($row['deletedAtUtc'] ?? null) !== null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
        if ((string) $row['rowVersion'] !== $expectedRowVersion) {
            throw ProductConcurrencyConflict::forPublicId($modelPublicId);
        }
        $row['analysis'] = $analysis;
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $map[$modelPublicId] = $row;
        $this->store->setThreeDModelsMap($map);

        return $this->models->findByPublicId($modelPublicId)
            ?? throw Product3DModelNotFound::forPublicId($modelPublicId);
    }

    public function saveModelOptimisationMetadata(
        string $modelPublicId,
        ?array $optimisationMetadata,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        unset($actorUserId);
        $map = $this->store->threeDModelsMap();
        $row = $map[$modelPublicId] ?? null;
        if ($row === null || ($row['deletedAtUtc'] ?? null) !== null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
        if ((string) $row['rowVersion'] !== $expectedRowVersion) {
            throw ProductConcurrencyConflict::forPublicId($modelPublicId);
        }
        $row['optimisation'] = $optimisationMetadata;
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $map[$modelPublicId] = $row;
        $this->store->setThreeDModelsMap($map);

        return $this->models->findByPublicId($modelPublicId)
            ?? throw Product3DModelNotFound::forPublicId($modelPublicId);
    }

    public function activateAsset(
        string $modelPublicId,
        string $assetPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        unset($actorUserId);
        $map = $this->store->threeDModelsMap();
        $row = $map[$modelPublicId] ?? null;
        if ($row === null || ($row['deletedAtUtc'] ?? null) !== null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
        if ((string) $row['rowVersion'] !== $expectedRowVersion) {
            throw ProductConcurrencyConflict::forPublicId($modelPublicId);
        }
        $asset = $this->findAssetByPublicId($assetPublicId);
        if ($asset === null || $asset->modelPublicId !== $modelPublicId) {
            throw Product3DAssetNotFound::forPublicId($assetPublicId);
        }

        $assetsMap = $this->store->threeDAssetsMap();
        foreach ($assetsMap as $id => $assetRow) {
            if (($assetRow['modelPublicId'] ?? '') !== $modelPublicId) {
                continue;
            }
            if (($assetRow['statusCode'] ?? '') === 'active') {
                $assetRow['statusCode'] = $assetRow['assetTypeCode'] === 'source' ? 'ready' : 'superseded';
                $assetRow['rowVersion'] = $this->store->nextRowVersion()->toBase64();
                $assetRow['updatedAtUtc'] = $this->store->nowUtc();
                $assetsMap[$id] = $assetRow;
            }
        }
        $activeRow = $assetsMap[$assetPublicId];
        $activeRow['statusCode'] = 'active';
        $activeRow['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $activeRow['updatedAtUtc'] = $this->store->nowUtc();
        $assetsMap[$assetPublicId] = $activeRow;
        $this->store->setThreeDAssetsMap($assetsMap);

        $row['activeAssetPublicId'] = $assetPublicId;
        $row['storagePath'] = $asset->storagePath;
        $row['storageContainer'] = $asset->storageContainer;
        $row['publicUrl'] = $asset->publicUrl;
        $row['fileSizeBytes'] = $asset->fileSizeBytes;
        $row['mimeType'] = $asset->mimeType ?? $row['mimeType'];
        $row['fileExtension'] = $asset->fileExtension ?? $row['fileExtension'];
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $map[$modelPublicId] = $row;
        $this->store->setThreeDModelsMap($map);

        return $this->models->findByPublicId($modelPublicId)
            ?? throw Product3DModelNotFound::forPublicId($modelPublicId);
    }

    public function revertToSource(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary {
        $source = $this->findSourceAsset($modelPublicId);
        if ($source === null) {
            $model = $this->models->findByPublicId($modelPublicId);
            if ($model === null) {
                throw Product3DModelNotFound::forPublicId($modelPublicId);
            }
            $source = $this->ensureSourceAsset($model, $actorUserId);
        }

        return $this->activateAsset($modelPublicId, $source->publicId, $expectedRowVersion, $actorUserId);
    }

    public function createJob(array $data, UserId $actorUserId): Product3DAssetProcessingJobSummary
    {
        unset($actorUserId);
        $publicId = $data['publicId'] ?? $this->store->newPublicId();
        $now = $this->store->nowUtc();
        $row = [
            'publicId' => $publicId,
            'productPublicId' => $data['productPublicId'],
            'modelPublicId' => $data['modelPublicId'],
            'assetPublicId' => $data['assetPublicId'] ?? null,
            'operationTypeCode' => $data['operationTypeCode'],
            'profileCode' => $data['profileCode'] ?? null,
            'statusCode' => $data['statusCode'],
            'input' => json_decode((string) $data['inputJson'], true) ?: [],
            'output' => null,
            'errorCode' => null,
            'errorMessage' => null,
            'startedAtUtc' => null,
            'completedAtUtc' => null,
            'rowVersion' => $this->store->nextRowVersion()->toBase64(),
            'createdAtUtc' => $now,
        ];
        $map = $this->store->threeDAssetJobsMap();
        $map[$publicId] = $row;
        $this->store->setThreeDAssetJobsMap($map);

        return self::toJobSummary($row);
    }

    public function updateJob(string $jobPublicId, array $data): Product3DAssetProcessingJobSummary
    {
        $map = $this->store->threeDAssetJobsMap();
        $row = $map[$jobPublicId] ?? null;
        if ($row === null) {
            throw Product3DAssetNotFound::forPublicId($jobPublicId);
        }
        if (isset($data['statusCode'])) {
            $row['statusCode'] = $data['statusCode'];
        }
        if (array_key_exists('outputJson', $data)) {
            $row['output'] = is_string($data['outputJson']) && $data['outputJson'] !== ''
                ? json_decode($data['outputJson'], true)
                : null;
        }
        if (array_key_exists('errorCode', $data)) {
            $row['errorCode'] = $data['errorCode'];
        }
        if (array_key_exists('errorMessage', $data)) {
            $row['errorMessage'] = $data['errorMessage'];
        }
        if (array_key_exists('startedAtUtc', $data)) {
            $row['startedAtUtc'] = $data['startedAtUtc'];
        }
        if (array_key_exists('completedAtUtc', $data)) {
            $row['completedAtUtc'] = $data['completedAtUtc'];
        }
        if (array_key_exists('assetPublicId', $data)) {
            $row['assetPublicId'] = $data['assetPublicId'];
        }
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $map[$jobPublicId] = $row;
        $this->store->setThreeDAssetJobsMap($map);

        return self::toJobSummary($row);
    }

    public function findJobByPublicId(string $jobPublicId): ?Product3DAssetProcessingJobSummary
    {
        $row = $this->store->threeDAssetJobsMap()[$jobPublicId] ?? null;

        return $row === null ? null : self::toJobSummary($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toAssetSummary(array $row): Product3DAssetSummary
    {
        return new Product3DAssetSummary(
            publicId: (string) $row['publicId'],
            modelPublicId: (string) $row['modelPublicId'],
            assetTypeCode: (string) $row['assetTypeCode'],
            storageContainer: isset($row['storageContainer']) && is_string($row['storageContainer']) ? $row['storageContainer'] : null,
            storagePath: isset($row['storagePath']) && is_string($row['storagePath']) ? $row['storagePath'] : null,
            publicUrl: isset($row['publicUrl']) && is_string($row['publicUrl']) ? $row['publicUrl'] : null,
            mimeType: isset($row['mimeType']) && is_string($row['mimeType']) ? $row['mimeType'] : null,
            fileSizeBytes: isset($row['fileSizeBytes']) ? (int) $row['fileSizeBytes'] : null,
            fileExtension: isset($row['fileExtension']) && is_string($row['fileExtension']) ? $row['fileExtension'] : null,
            originalFileName: isset($row['originalFileName']) && is_string($row['originalFileName']) ? $row['originalFileName'] : null,
            sha256: isset($row['sha256']) && is_string($row['sha256']) ? $row['sha256'] : null,
            statusCode: (string) $row['statusCode'],
            scanStatusCode: (string) $row['scanStatusCode'],
            optimisationProfile: isset($row['optimisationProfile']) && is_string($row['optimisationProfile']) ? $row['optimisationProfile'] : null,
            analysis: is_array($row['analysis'] ?? null) ? $row['analysis'] : null,
            optimisationMetadata: is_array($row['optimisationMetadata'] ?? null) ? $row['optimisationMetadata'] : null,
            rowVersion: (string) $row['rowVersion'],
            createdAtUtc: (string) $row['createdAtUtc'],
            updatedAtUtc: (string) $row['updatedAtUtc'],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toJobSummary(array $row): Product3DAssetProcessingJobSummary
    {
        return new Product3DAssetProcessingJobSummary(
            publicId: (string) $row['publicId'],
            productPublicId: (string) $row['productPublicId'],
            modelPublicId: (string) $row['modelPublicId'],
            assetPublicId: isset($row['assetPublicId']) && is_string($row['assetPublicId']) ? $row['assetPublicId'] : null,
            operationTypeCode: (string) $row['operationTypeCode'],
            profileCode: isset($row['profileCode']) && is_string($row['profileCode']) ? $row['profileCode'] : null,
            statusCode: (string) $row['statusCode'],
            input: is_array($row['input'] ?? null) ? $row['input'] : [],
            output: is_array($row['output'] ?? null) ? $row['output'] : null,
            errorCode: isset($row['errorCode']) && is_string($row['errorCode']) ? $row['errorCode'] : null,
            errorMessage: isset($row['errorMessage']) && is_string($row['errorMessage']) ? $row['errorMessage'] : null,
            startedAtUtc: isset($row['startedAtUtc']) && is_string($row['startedAtUtc']) ? $row['startedAtUtc'] : null,
            completedAtUtc: isset($row['completedAtUtc']) && is_string($row['completedAtUtc']) ? $row['completedAtUtc'] : null,
            rowVersion: (string) $row['rowVersion'],
            createdAtUtc: (string) $row['createdAtUtc'],
        );
    }
}
