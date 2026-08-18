<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class InitiateProductDocumentUploadCommand
{
    public function __construct(
        public string $productPublicId,
        public string $documentTypeCode,
        public string $title,
        public string $accessCode,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public string $storageContainer = 'product-documents',
        public ?string $versionLabel = null,
        public ?string $languageCode = null,
        public int $displayOrder = 0,
        public int $uploadExpiresInSeconds = 900,
        public bool $isCad = false,
    ) {
    }
}
