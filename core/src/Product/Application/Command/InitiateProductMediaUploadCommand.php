<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class InitiateProductMediaUploadCommand
{
    public function __construct(
        public string $productPublicId,
        public string $mediaCategoryCode,
        public string $mediaTypeCode,
        public string $title,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public string $storageContainer = 'product-media',
        public ?string $altText = null,
        public ?string $caption = null,
        public int $displayOrder = 0,
        public bool $isPrimary = false,
        public int $uploadExpiresInSeconds = 900,
    ) {
    }
}
