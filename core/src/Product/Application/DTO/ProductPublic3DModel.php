<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

/**
 * Public/customer-safe 3D model payload. No storage paths, scan, or generation metadata.
 *
 * @phpstan-type ViewerConfig array<string, mixed>
 */
final readonly class ProductPublic3DModel
{
    /**
     * @param ViewerConfig $viewerConfiguration
     */
    public function __construct(
        public string $publicId,
        public string $name,
        public string $modelType,
        public string $url,
        public ?string $previewImageUrl,
        public array $viewerConfiguration,
        public bool $isPrimary,
        /** Null = product-level model; set when published for a specific variant. */
        public ?string $productVariantPublicId = null,
        /**
         * Internal only — used to issue READ SAS at response time.
         * Never serialize to public/customer JSON.
         */
        public ?string $storageContainer = null,
        public ?string $storagePath = null,
        /** Internal only — preview blob path for READ SAS (not the GLB path). */
        public ?string $previewImageStoragePath = null,
    ) {
    }
}
