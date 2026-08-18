<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class InitiateProduct3DPreviewUploadCommand
{
    public function __construct(
        public string $productPublicId,
        public string $modelPublicId,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public string $storageContainer,
        public string $expectedRowVersion,
        public int $uploadExpiresInSeconds = 900,
    ) {
    }
}
