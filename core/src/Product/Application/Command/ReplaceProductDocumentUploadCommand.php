<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class ReplaceProductDocumentUploadCommand
{
    public function __construct(
        public string $documentPublicId,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public ?string $storageContainer = null,
        public int $uploadExpiresInSeconds = 900,
        public bool $isCad = false,
    ) {
    }
}
