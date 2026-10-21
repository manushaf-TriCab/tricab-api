<?php

declare(strict_types=1);

namespace TriCab\Api\Product\Persistence\InMemory;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\Product3DAssetSummary;
use TriCab\Core\Product\Application\DTO\Product3DModelSummary;
use TriCab\Core\Product\Application\DTO\ProductPublic3DModel;
use TriCab\Core\Product\Application\DTO\ProductPublic3DPayload;
use TriCab\Core\Product\Application\Exception\Product3DModelNotFound;
use TriCab\Core\Product\Application\Exception\ProductConcurrencyConflict;
use TriCab\Core\Product\Contract\Product3DModelRepository;
use TriCab\Core\Product\Domain\Service\Product3DViewerConfigurationValidator;
use TriCab\Core\Product\Domain\ValueObject\ProductPublicId;

final class InMemoryProduct3DModelRepository implements Product3DModelRepository
{
    public function __construct(
        private readonly InMemoryProductStore $store,
    ) {
    }

    public function findByPublicId(string $modelPublicId): ?Product3DModelSummary
    {
        $row = $this->store->threeDModelsMap()[$modelPublicId] ?? null;
        if ($row === null || ($row['deletedAtUtc'] ?? null) !== null) {
            return null;
        }

        return self::toSummary($row, $this->store);
    }

    public function listByProduct(ProductPublicId $productPublicId): array
    {
        $out = [];
        foreach ($this->store->threeDModelsMap() as $row) {
            if (($row['productPublicId'] ?? '') === $productPublicId->toString() && ($row['deletedAtUtc'] ?? null) === null) {
                $out[] = self::toSummary($row, $this->store);
            }
        }
        usort($out, static fn (Product3DModelSummary $a, Product3DModelSummary $b): int => $a->displayOrder <=> $b->displayOrder);

        return $out;
    }

    public function listPublicByProduct(ProductPublicId $productPublicId): ProductPublic3DPayload
    {
        $models = [];
        foreach ($this->listByProduct($productPublicId) as $item) {
            if (
                $item->statusCode !== 'published'
                || $item->uploadStatusCode !== 'active'
                || !$item->isActive
                || $item->publicUrl === null
                || $item->publicUrl === ''
            ) {
                continue;
            }
            if (!in_array($item->scanStatusCode, ['clean', 'not_required', 'skipped'], true)) {
                continue;
            }
            $models[] = new ProductPublic3DModel(
                publicId: $item->publicId,
                name: $item->name,
                modelType: $item->modelTypeCode,
                url: $item->publicUrl,
                previewImageUrl: $item->previewImagePublicUrl,
                viewerConfiguration: $item->viewerConfiguration,
                isPrimary: $item->isPrimary,
                productVariantPublicId: $item->productVariantPublicId,
                storageContainer: $item->storageContainer,
                storagePath: $item->storagePath,
                previewImageStoragePath: $item->previewImageStoragePath,
            );
        }
        if ($models === []) {
            return ProductPublic3DPayload::empty();
        }
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
        if ($primary === null) {
            foreach ($models as $model) {
                if ($model->isPrimary) {
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
        unset($actorUserId);
        $publicId = $data['publicId'] ?? $this->store->newPublicId();
        $validator = new Product3DViewerConfigurationValidator();
        $viewer = $validator->validate($data['viewerConfigurationJson'] ?? '{}');
        $now = $this->store->nowUtc();
        $row = [
            'publicId' => $publicId,
            'productPublicId' => $productPublicId->toString(),
            'productVariantPublicId' => $data['productVariantPublicId'] ?? null,
            'name' => $data['name'],
            'modelTypeCode' => $data['modelTypeCode'],
            'sourceTypeCode' => $data['sourceTypeCode'],
            'statusCode' => $data['statusCode'],
            'uploadStatusCode' => $data['uploadStatusCode'],
            'scanStatusCode' => $data['scanStatusCode'],
            'publicUrl' => null,
            'previewImagePublicUrl' => null,
            'previewImageStoragePath' => null,
            'storagePath' => $data['storagePath'] ?? null,
            'storageContainer' => $data['storageContainer'] ?? null,
            'mimeType' => $data['mimeType'] ?? null,
            'fileExtension' => $data['fileExtension'] ?? null,
            'fileSizeBytes' => $data['fileSizeBytes'] ?? null,
            'originalFileName' => $data['originalFileName'] ?? null,
            'fileName' => $data['fileName'] ?? null,
            'viewerConfiguration' => $viewer['data'],
            'viewerConfigurationJson' => $viewer['json'],
            'generationMetadata' => isset($data['generationMetadataJson']) && is_string($data['generationMetadataJson']) && $data['generationMetadataJson'] !== ''
                ? json_decode($data['generationMetadataJson'], true)
                : null,
            'materialOverrides' => null,
            'analysis' => null,
            'optimisation' => null,
            'activeAssetPublicId' => null,
            'isPrimary' => (bool) ($data['isPrimary'] ?? false),
            'isActive' => true,
            'displayOrder' => (int) ($data['displayOrder'] ?? 0),
            'publishedAtUtc' => null,
            'rowVersion' => $this->store->nextRowVersion()->toBase64(),
            'createdAtUtc' => $now,
            'updatedAtUtc' => $now,
            'deletedAtUtc' => null,
        ];
        if ($row['isPrimary']) {
            $this->clearPrimary($productPublicId->toString(), null, $publicId);
        }
        $map = $this->store->threeDModelsMap();
        $map[$publicId] = $row;
        $this->store->setThreeDModelsMap($map);

        return self::toSummary($row, $this->store);
    }

    public function update(string $modelPublicId, array $data, string $expectedRowVersion, UserId $actorUserId): Product3DModelSummary
    {
        unset($actorUserId);
        $map = $this->store->threeDModelsMap();
        $row = $map[$modelPublicId] ?? null;
        if ($row === null || ($row['deletedAtUtc'] ?? null) !== null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
        if ((string) $row['rowVersion'] !== $expectedRowVersion) {
            throw ProductConcurrencyConflict::forPublicId($modelPublicId);
        }
        if (isset($data['name'])) {
            $row['name'] = $data['name'];
        }
        if (isset($data['viewerConfigurationJson'])) {
            $validator = new Product3DViewerConfigurationValidator();
            $viewer = $validator->validate($data['viewerConfigurationJson']);
            $row['viewerConfiguration'] = $viewer['data'];
            $row['viewerConfigurationJson'] = $viewer['json'];
        }
        if (array_key_exists('materialOverridesJson', $data)) {
            $row['materialOverrides'] = $data['materialOverridesJson'] === null
                ? null
                : json_decode((string) $data['materialOverridesJson'], true);
        }
        if (array_key_exists('previewImagePublicUrl', $data)) {
            $row['previewImagePublicUrl'] = $data['previewImagePublicUrl'];
        }
        if (array_key_exists('previewImageStoragePath', $data)) {
            $row['previewImageStoragePath'] = $data['previewImageStoragePath'];
        }
        if (isset($data['displayOrder'])) {
            $row['displayOrder'] = (int) $data['displayOrder'];
        }
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $map[$modelPublicId] = $row;
        $this->store->setThreeDModelsMap($map);

        return self::toSummary($row, $this->store);
    }

    public function markUploadCompleted(string $modelPublicId, array $blobMeta, UserId $actorUserId): Product3DModelSummary
    {
        unset($actorUserId);
        $map = $this->store->threeDModelsMap();
        $row = $map[$modelPublicId] ?? null;
        if ($row === null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
        $row['uploadStatusCode'] = $blobMeta['uploadStatusCode'];
        $row['scanStatusCode'] = $blobMeta['scanStatusCode'];
        $row['statusCode'] = $blobMeta['statusCode'];
        $row['publicUrl'] = $blobMeta['publicUrl'] ?? $row['publicUrl'];
        $row['mimeType'] = $blobMeta['mimeType'] ?? $row['mimeType'];
        $row['fileSizeBytes'] = $blobMeta['fileSizeBytes'] ?? $row['fileSizeBytes'];
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $map[$modelPublicId] = $row;
        $this->store->setThreeDModelsMap($map);

        return self::toSummary($row, $this->store);
    }

    public function markUploadFailed(string $modelPublicId, UserId $actorUserId): Product3DModelSummary
    {
        unset($actorUserId);
        $map = $this->store->threeDModelsMap();
        $row = $map[$modelPublicId] ?? null;
        if ($row === null || ($row['deletedAtUtc'] ?? null) !== null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
        $row['uploadStatusCode'] = 'failed';
        $row['statusCode'] = 'failed';
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $map[$modelPublicId] = $row;
        $this->store->setThreeDModelsMap($map);

        return self::toSummary($row, $this->store);
    }

    public function publish(string $modelPublicId, string $expectedRowVersion, UserId $actorUserId): Product3DModelSummary
    {
        return $this->transition($modelPublicId, $expectedRowVersion, $actorUserId, 'published', true);
    }

    public function unpublish(string $modelPublicId, string $expectedRowVersion, UserId $actorUserId): Product3DModelSummary
    {
        return $this->transition($modelPublicId, $expectedRowVersion, $actorUserId, 'ready', false);
    }

    public function setPrimary(string $modelPublicId, string $expectedRowVersion, UserId $actorUserId): Product3DModelSummary
    {
        unset($actorUserId);
        $map = $this->store->threeDModelsMap();
        $row = $map[$modelPublicId] ?? null;
        if ($row === null || ($row['deletedAtUtc'] ?? null) !== null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
        if ((string) $row['rowVersion'] !== $expectedRowVersion) {
            throw ProductConcurrencyConflict::forPublicId($modelPublicId);
        }
        $this->clearPrimary((string) $row['productPublicId'], $row['productVariantPublicId'] ?? null, $modelPublicId);
        $map = $this->store->threeDModelsMap();
        $row = $map[$modelPublicId];
        $row['isPrimary'] = true;
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $map[$modelPublicId] = $row;
        $this->store->setThreeDModelsMap($map);

        return self::toSummary($row, $this->store);
    }

    public function softDelete(string $modelPublicId, string $expectedRowVersion, UserId $actorUserId): Product3DModelSummary
    {
        unset($actorUserId);
        $map = $this->store->threeDModelsMap();
        $row = $map[$modelPublicId] ?? null;
        if ($row === null || ($row['deletedAtUtc'] ?? null) !== null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
        if ((string) $row['rowVersion'] !== $expectedRowVersion) {
            throw ProductConcurrencyConflict::forPublicId($modelPublicId);
        }
        $row['deletedAtUtc'] = $this->store->nowUtc();
        $row['isActive'] = false;
        $row['isPrimary'] = false;
        $row['statusCode'] = 'archived';
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $map[$modelPublicId] = $row;
        $this->store->setThreeDModelsMap($map);

        return self::toSummary($row, $this->store);
    }

    private function transition(string $modelPublicId, string $expectedRowVersion, UserId $actorUserId, string $status, bool $published): Product3DModelSummary
    {
        unset($actorUserId);
        $map = $this->store->threeDModelsMap();
        $row = $map[$modelPublicId] ?? null;
        if ($row === null || ($row['deletedAtUtc'] ?? null) !== null) {
            throw Product3DModelNotFound::forPublicId($modelPublicId);
        }
        if ((string) $row['rowVersion'] !== $expectedRowVersion) {
            throw ProductConcurrencyConflict::forPublicId($modelPublicId);
        }
        $row['statusCode'] = $status;
        $row['publishedAtUtc'] = $published ? $this->store->nowUtc() : null;
        $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
        $row['updatedAtUtc'] = $this->store->nowUtc();
        $map[$modelPublicId] = $row;
        $this->store->setThreeDModelsMap($map);

        return self::toSummary($row, $this->store);
    }

    private function clearPrimary(string $productPublicId, mixed $variantPublicId, ?string $exceptModelPublicId = null): void
    {
        $map = $this->store->threeDModelsMap();
        foreach ($map as $id => $row) {
            if ($exceptModelPublicId !== null && $id === $exceptModelPublicId) {
                continue;
            }
            if (($row['productPublicId'] ?? '') !== $productPublicId) {
                continue;
            }
            if (($row['productVariantPublicId'] ?? null) !== $variantPublicId) {
                continue;
            }
            if (!($row['isPrimary'] ?? false)) {
                continue;
            }
            $row['isPrimary'] = false;
            $row['rowVersion'] = $this->store->nextRowVersion()->toBase64();
            $map[$id] = $row;
        }
        $this->store->setThreeDModelsMap($map);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function toSummary(array $row, InMemoryProductStore $store): Product3DModelSummary
    {
        $viewer = $row['viewerConfiguration'] ?? [];
        if (!is_array($viewer)) {
            $viewer = [];
        }

        $activeAsset = null;
        $activeAssetPublicId = isset($row['activeAssetPublicId']) && is_string($row['activeAssetPublicId'])
            ? $row['activeAssetPublicId']
            : null;
        if ($activeAssetPublicId !== null) {
            $assetRow = $store->threeDAssetsMap()[$activeAssetPublicId] ?? null;
            if (is_array($assetRow) && ($assetRow['deletedAtUtc'] ?? null) === null) {
                $activeAsset = new Product3DAssetSummary(
                    publicId: (string) $assetRow['publicId'],
                    modelPublicId: (string) $assetRow['modelPublicId'],
                    assetTypeCode: (string) $assetRow['assetTypeCode'],
                    storageContainer: isset($assetRow['storageContainer']) && is_string($assetRow['storageContainer']) ? $assetRow['storageContainer'] : null,
                    storagePath: isset($assetRow['storagePath']) && is_string($assetRow['storagePath']) ? $assetRow['storagePath'] : null,
                    publicUrl: isset($assetRow['publicUrl']) && is_string($assetRow['publicUrl']) ? $assetRow['publicUrl'] : null,
                    mimeType: isset($assetRow['mimeType']) && is_string($assetRow['mimeType']) ? $assetRow['mimeType'] : null,
                    fileSizeBytes: isset($assetRow['fileSizeBytes']) ? (int) $assetRow['fileSizeBytes'] : null,
                    fileExtension: isset($assetRow['fileExtension']) && is_string($assetRow['fileExtension']) ? $assetRow['fileExtension'] : null,
                    originalFileName: isset($assetRow['originalFileName']) && is_string($assetRow['originalFileName']) ? $assetRow['originalFileName'] : null,
                    sha256: isset($assetRow['sha256']) && is_string($assetRow['sha256']) ? $assetRow['sha256'] : null,
                    statusCode: (string) $assetRow['statusCode'],
                    scanStatusCode: (string) $assetRow['scanStatusCode'],
                    optimisationProfile: isset($assetRow['optimisationProfile']) && is_string($assetRow['optimisationProfile']) ? $assetRow['optimisationProfile'] : null,
                    analysis: is_array($assetRow['analysis'] ?? null) ? $assetRow['analysis'] : null,
                    optimisationMetadata: is_array($assetRow['optimisationMetadata'] ?? null) ? $assetRow['optimisationMetadata'] : null,
                    rowVersion: (string) $assetRow['rowVersion'],
                    createdAtUtc: (string) $assetRow['createdAtUtc'],
                    updatedAtUtc: (string) $assetRow['updatedAtUtc'],
                );
            }
        }

        return new Product3DModelSummary(
            publicId: (string) $row['publicId'],
            productPublicId: (string) $row['productPublicId'],
            productVariantPublicId: isset($row['productVariantPublicId']) && is_string($row['productVariantPublicId']) ? $row['productVariantPublicId'] : null,
            name: (string) $row['name'],
            modelTypeCode: (string) $row['modelTypeCode'],
            sourceTypeCode: (string) $row['sourceTypeCode'],
            statusCode: (string) $row['statusCode'],
            uploadStatusCode: (string) $row['uploadStatusCode'],
            scanStatusCode: (string) $row['scanStatusCode'],
            publicUrl: isset($row['publicUrl']) && is_string($row['publicUrl']) ? $row['publicUrl'] : null,
            previewImagePublicUrl: isset($row['previewImagePublicUrl']) && is_string($row['previewImagePublicUrl']) ? $row['previewImagePublicUrl'] : null,
            previewImageStoragePath: isset($row['previewImageStoragePath']) && is_string($row['previewImageStoragePath']) ? $row['previewImageStoragePath'] : null,
            storagePath: isset($row['storagePath']) && is_string($row['storagePath']) ? $row['storagePath'] : null,
            storageContainer: isset($row['storageContainer']) && is_string($row['storageContainer']) ? $row['storageContainer'] : null,
            mimeType: isset($row['mimeType']) && is_string($row['mimeType']) ? $row['mimeType'] : null,
            fileExtension: isset($row['fileExtension']) && is_string($row['fileExtension']) ? $row['fileExtension'] : null,
            fileSizeBytes: isset($row['fileSizeBytes']) ? (int) $row['fileSizeBytes'] : null,
            originalFileName: isset($row['originalFileName']) && is_string($row['originalFileName']) ? $row['originalFileName'] : null,
            viewerConfiguration: $viewer,
            generationMetadata: is_array($row['generationMetadata'] ?? null) ? $row['generationMetadata'] : null,
            materialOverrides: is_array($row['materialOverrides'] ?? null) ? $row['materialOverrides'] : null,
            isPrimary: (bool) ($row['isPrimary'] ?? false),
            isActive: (bool) ($row['isActive'] ?? true),
            displayOrder: (int) ($row['displayOrder'] ?? 0),
            publishedAtUtc: isset($row['publishedAtUtc']) && is_string($row['publishedAtUtc']) ? $row['publishedAtUtc'] : null,
            rowVersion: (string) $row['rowVersion'],
            createdAtUtc: (string) $row['createdAtUtc'],
            updatedAtUtc: (string) $row['updatedAtUtc'],
            analysis: is_array($row['analysis'] ?? null) ? $row['analysis'] : null,
            optimisation: is_array($row['optimisation'] ?? null) ? $row['optimisation'] : null,
            activeAsset: $activeAsset,
            assets: null,
            activeAssetPublicId: $activeAssetPublicId,
        );
    }
}
