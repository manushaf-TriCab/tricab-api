<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class CompleteProduct3DPreviewUploadCommand
{
    public function __construct(
        public string $productPublicId,
        public string $modelPublicId,
        public string $expectedRowVersion,
        public string $storagePath,
        public string $storageContainer,
        public string $contentType,
        public int $byteSize,
    ) {
    }
}
