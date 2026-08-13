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
    ) {
    }
}
