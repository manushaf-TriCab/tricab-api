<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class Initiate360FrameUploadCommand
{
    public function __construct(
        public string $setPublicId,
        public int $frameNumber,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public string $storageContainer = 'product-media',
        public int $uploadExpiresInSeconds = 900,
    ) {
    }
}
