<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class InitiateStyleMediaUploadCommand
{
    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $stylePublicId,
        public string $resourceType,
        public string $originalFileName,
        public string $contentType,
        public int $byteSize,
        public string $title = '',
        public ?string $roleCode = null,
        public ?string $altText = null,
        public int $displayOrder = 0,
        public bool $isPrimary = false,
        public array $metadata = [],
        public string $storageContainer = 'products',
        public int $uploadExpiresInSeconds = 900,
    ) {
    }
}
