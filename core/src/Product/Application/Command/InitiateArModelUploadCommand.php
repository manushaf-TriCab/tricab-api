<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class InitiateArModelUploadCommand
{
    public function __construct(
        public string $productPublicId,
        public string $title,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public string $storageContainer = 'product-media',
        public bool $isPrimary = false,
        public int $uploadExpiresInSeconds = 900,
    ) {
    }
}
