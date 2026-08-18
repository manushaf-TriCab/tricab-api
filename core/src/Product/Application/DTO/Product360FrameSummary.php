<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class Product360FrameSummary
{
    public function __construct(
        public string $publicId,
        public string $setPublicId,
        public int $frameNumber,
        public string $uploadStatusCode,
        public string $scanStatusCode,
        public ?string $publicUrl,
        public ?string $storagePath,
        public ?string $storageContainer,
        public ?string $contentType,
        public ?int $byteSize,
        public ?string $originalFileName,
    ) {
    }
}
