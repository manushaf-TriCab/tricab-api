<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ReplaceProductMediaUploadCommand
{
    public function __construct(
        public string $mediaPublicId,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public ?string $storageContainer = null,
        public int $uploadExpiresInSeconds = 900,
    ) {
    }
}
