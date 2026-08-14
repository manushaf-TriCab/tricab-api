<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Admin / application summary for a Product 3D Studio model.
 *
 * @phpstan-type ViewerConfig array<string, mixed>
 * @phpstan-type AnalysisPayload array<string, mixed>
 * @phpstan-type OptimisationMetadata array<string, mixed>
 */
final readonly class Product3DModelSummary
{
    /**
     * @param ViewerConfig $viewerConfiguration
     * @param array<string, mixed>|null $generationMetadata
     * @param list<array<string, mixed>>|null $materialOverrides
     * @param AnalysisPayload|null $analysis
     * @param OptimisationMetadata|null $optimisation
     * @param list<Product3DAssetSummary>|null $assets
     */
    public function __construct(
        public string $publicId,
        public string $productPublicId,
        public ?string $productVariantPublicId,
        public string $name,
        public string $modelTypeCode,
        public string $sourceTypeCode,
        public string $statusCode,
        public string $uploadStatusCode,
        public string $scanStatusCode,
        public ?string $publicUrl,
        public ?string $previewImagePublicUrl,
        public ?string $previewImageStoragePath,
        public ?string $storagePath,
        public ?string $storageContainer,
        public ?string $mimeType,
        public ?string $fileExtension,
        public ?int $fileSizeBytes,
        public ?string $originalFileName,
        public array $viewerConfiguration,
        public ?array $generationMetadata,
        public ?array $materialOverrides,
        public bool $isPrimary,
        public bool $isActive,
        public int $displayOrder,
        public ?string $publishedAtUtc,
        public string $rowVersion,
        public string $createdAtUtc,
        public string $updatedAtUtc,
        public ?array $analysis = null,
        public ?array $optimisation = null,
        public ?Product3DAssetSummary $activeAsset = null,
        public ?array $assets = null,
        public ?string $activeAssetPublicId = null,
    ) {
    }

    public function recommendation(): ?string
    {
        if ($this->analysis === null) {
            return null;
        }
        $value = $this->analysis['recommendation'] ?? null;

        return is_string($value) ? $value : null;
    }
}
