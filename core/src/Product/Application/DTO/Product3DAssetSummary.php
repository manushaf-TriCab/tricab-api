<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * SOURCE or OPTIMISED GLB asset linked to a Product3DModel.
 *
 * @phpstan-type AnalysisPayload array<string, mixed>
 * @phpstan-type OptimisationMetadata array<string, mixed>
 */
final readonly class Product3DAssetSummary
{
    /**
     * @param AnalysisPayload|null $analysis
     * @param OptimisationMetadata|null $optimisationMetadata
     */
    public function __construct(
        public string $publicId,
        public string $modelPublicId,
        public string $assetTypeCode,
        public ?string $storageContainer,
        public ?string $storagePath,
        public ?string $publicUrl,
        public ?string $mimeType,
        public ?int $fileSizeBytes,
        public ?string $fileExtension,
        public ?string $originalFileName,
        public ?string $sha256,
        public string $statusCode,
        public string $scanStatusCode,
        public ?string $optimisationProfile,
        public ?array $analysis,
        public ?array $optimisationMetadata,
        public string $rowVersion,
        public string $createdAtUtc,
        public string $updatedAtUtc,
    ) {
    }
}
