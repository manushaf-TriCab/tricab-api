<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductArModelSummary
{
    public function __construct(
        public string $publicId,
        public string $productPublicId,
        public string $title,
        public string $uploadStatusCode,
        public string $scanStatusCode,
        public ?string $publicUrl,
        public ?string $storagePath,
        public ?string $storageContainer,
        public ?string $contentType,
        public ?string $fileExtension,
        public ?int $byteSize,
        public ?string $originalFileName,
        public bool $isPrimary = false,
    ) {
    }
}
