<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Contract;

use TriCab\Core\Identity\Domain\ValueObject\UserId;
use TriCab\Core\Product\Application\DTO\Product3DAssetProcessingJobSummary;
use TriCab\Core\Product\Application\DTO\Product3DAssetSummary;
use TriCab\Core\Product\Application\DTO\Product3DModelSummary;

interface Product3DAssetRepository
{
    /**
     * @return list<Product3DAssetSummary>
     */
    public function listAssetsByModel(string $modelPublicId): array;

    public function findAssetByPublicId(string $assetPublicId): ?Product3DAssetSummary;

    public function findSourceAsset(string $modelPublicId): ?Product3DAssetSummary;

    /**
     * Lazily register a SOURCE asset from the model's current storage fields (no blob move).
     */
    public function ensureSourceAsset(
        Product3DModelSummary $model,
        UserId $actorUserId,
    ): Product3DAssetSummary;

    /**
     * @param array{
     *   publicId?: string,
     *   assetTypeCode: string,
     *   storageContainer: string,
     *   storagePath: string,
     *   publicUrl?: ?string,
     *   mimeType?: ?string,
     *   fileSizeBytes?: ?int,
     *   fileExtension?: ?string,
     *   originalFileName?: ?string,
     *   sha256?: ?string,
     *   statusCode: string,
     *   scanStatusCode: string,
     *   optimisationProfile?: ?string,
     *   analysisJson?: ?string,
     *   optimisationMetadataJson?: ?string
     * } $data
     */
    public function createAsset(
        string $modelPublicId,
        array $data,
        UserId $actorUserId,
    ): Product3DAssetSummary;

    /**
     * @param array<string, mixed> $analysis
     */
    public function saveModelAnalysis(
        string $modelPublicId,
        array $analysis,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary;

    /**
     * @param array<string, mixed>|null $optimisationMetadata
     */
    public function saveModelOptimisationMetadata(
        string $modelPublicId,
        ?array $optimisationMetadata,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary;

    public function activateAsset(
        string $modelPublicId,
        string $assetPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary;

    public function revertToSource(
        string $modelPublicId,
        string $expectedRowVersion,
        UserId $actorUserId,
    ): Product3DModelSummary;

    /**
     * @param array{
     *   publicId?: string,
     *   productPublicId: string,
     *   modelPublicId: string,
     *   assetPublicId?: ?string,
     *   operationTypeCode: string,
     *   profileCode?: ?string,
     *   statusCode: string,
     *   inputJson: string
     * } $data
     */
    public function createJob(array $data, UserId $actorUserId): Product3DAssetProcessingJobSummary;

    /**
     * @param array{
     *   statusCode: string,
     *   outputJson?: ?string,
     *   errorCode?: ?string,
     *   errorMessage?: ?string,
     *   startedAtUtc?: ?string,
     *   completedAtUtc?: ?string,
     *   assetPublicId?: ?string
     * } $data
     */
    public function updateJob(string $jobPublicId, array $data): Product3DAssetProcessingJobSummary;

    public function findJobByPublicId(string $jobPublicId): ?Product3DAssetProcessingJobSummary;
}
