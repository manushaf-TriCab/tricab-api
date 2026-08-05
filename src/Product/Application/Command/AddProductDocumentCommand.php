<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\Command;

final readonly class AddProductDocumentCommand
{
    public function __construct(
        public string $productPublicId,
        public string $documentTypeCode,
        public string $title,
        public string $storagePath,
        public string $accessCode,
        public ?string $versionLabel = null,
        public ?string $languageCode = null,
        public ?string $regionPublicId = null,
        public ?string $marketPublicId = null,
        public ?string $effectiveFromUtc = null,
        public ?string $effectiveToUtc = null,
        public int $displayOrder = 0,
        public ?string $publicUrl = null,
    ) {
    }
}
