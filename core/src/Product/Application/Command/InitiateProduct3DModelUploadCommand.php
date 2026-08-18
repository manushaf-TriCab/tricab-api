<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class InitiateProduct3DModelUploadCommand
{
    /**
     * @param array<string, mixed>|null $generationMetadata
     */
    public function __construct(
        public string $productPublicId,
        public string $name,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public string $storageContainer,
        public bool $isPrimary = false,
        public int $uploadExpiresInSeconds = 900,
        public string $sourceTypeCode = 'upload',
        public ?array $generationMetadata = null,
        public ?string $productVariantPublicId = null,
    ) {
    }
}
