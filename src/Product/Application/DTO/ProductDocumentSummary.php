<?php

declare(strict_types=1);

namespace TriCab\Core\Product\Application\DTO;

final readonly class ProductDocumentSummary
{
    public function __construct(
        public string $publicId,
        public string $documentTypeCode,
        public string $title,
        public ?string $versionLabel,
        public ?string $languageCode,
        public ?string $publicUrl,
        public string $accessCode,
        public int $displayOrder,
    ) {
    }
}
