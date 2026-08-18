<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AddProductMediaCommand
{
    public function __construct(
        public string $productPublicId,
        public string $mediaCategoryCode,
        public string $mediaTypeCode,
        public string $title,
        public string $storagePath,
        public ?string $storageContainer = null,
        public ?string $contentType = null,
        public ?string $fileExtension = null,
        public ?int $byteSize = null,
        public ?string $altText = null,
        public ?string $caption = null,
        public int $displayOrder = 0,
        public bool $isPrimary = false,
        public ?string $publicUrl = null,
    ) {
    }
}
